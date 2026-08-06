<?php
/**
 * Extraction IA structurée des opérations sur titres (dividendes,
 * augmentations de capital, admissions, assemblées générales...) mentionnées
 * dans un Bulletin Officiel de la Cote (BOC). Contrairement à
 * MarketBulletinAnalysisService (résumé de séance en texte libre), ici
 * chaque opération devient une ligne interrogeable dans
 * market_bulletin_corporate_actions, rattachée si possible à une entreprise
 * de la table companies via CompanySlugMatcher::matchCompanyName().
 *
 * Voir TODO_ANALYSES.md, point 12 : "Suivi des dividendes et opérations sur
 * titres".
 */
class BulletinCorporateActionsService {
    private const MAX_BULLETIN_CHARS = 500000;

    /**
     * Même registre de fournisseurs que MarketBulletinAnalysisService — voir
     * class/AiClientInterface.php pour ajouter un fournisseur.
     */
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    public const ACTION_TYPES = ['dividende', 'augmentation_capital', 'admission', 'assemblee_generale', 'autre'];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * Lance (ou relit en cache) l'extraction des opérations sur titres d'un
     * bulletin. Contrairement à MarketBulletinAnalysisService::analyze(), le
     * cache n'est pas gardé par (bulletin_id, provider, model) dans une table
     * à part : on stocke directement les lignes extraites, et le statut de la
     * dernière extraction sur market_bulletin_contents (une seule extraction
     * "active" à la fois par bulletin, la plus récente écrase la précédente
     * — une réextraction est la source de vérité, pas un ajout).
     */
    public function extract(int $bulletinId, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $row = $content[0] ?? null;

        $usingMarkdown = !empty($row['formatted_markdown']) && $row['markdown_status'] === 'success';
        $sourceText = $usingMarkdown ? $row['formatted_markdown'] : ($row['extracted_text'] ?? null);

        if (empty($sourceText)) {
            throw new Exception(
                "Le texte de ce bulletin n'a pas encore été extrait. " .
                "Utilise le bouton 'Traiter' avant de l'extraire."
            );
        }

        if (!$forceRefresh && ($row['corporate_actions_status'] ?? null) === 'success') {
            return $this->getStoredActions($bulletinId, $bulletin, true);
        }

        $truncatedText = $this->truncate($sourceText);
        $prompt = $this->buildPrompt($bulletin, $truncatedText);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $update = [
            'corporate_actions_provider' => $provider,
            'corporate_actions_model' => $model,
            'corporate_actions_updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($aiResult['success']) {
            $actions = $aiResult['data']['actions'] ?? [];
            $companies = $this->crud->executeCustomQuery(
                "SELECT c.*, co.code AS country_code FROM companies c
                 LEFT JOIN countries co ON co.id = c.country_id
                 WHERE c.active = 1"
            ) ?: [];

            // Réextraction = source de vérité : on repart de zéro pour ce
            // bulletin plutôt que d'accumuler des doublons à chaque relance.
            $this->crud->remove('market_bulletin_corporate_actions', ['bulletin_id' => $bulletinId]);

            foreach ($actions as $action) {
                $rawName = trim((string) ($action['company_name'] ?? ''));
                if ($rawName === '') continue;

                $match = CompanySlugMatcher::matchCompanyName($rawName, $companies);

                $this->crud->persist('market_bulletin_corporate_actions', [
                    'bulletin_id' => $bulletinId,
                    'company_id' => $match['company_id'] ?? null,
                    'company_name_raw' => $rawName,
                    'match_confidence' => $match['confidence'] ?? null,
                    'action_type' => in_array($action['action_type'] ?? null, self::ACTION_TYPES, true) ? $action['action_type'] : 'autre',
                    'event_date' => $this->validDateOrNull($action['event_date'] ?? null),
                    'amount' => is_numeric($action['amount'] ?? null) ? $action['amount'] : null,
                    'currency' => !empty($action['currency']) ? $action['currency'] : 'FCFA',
                    'description' => $action['description'] ?? null,
                    'source_section' => $action['source_section'] ?? null,
                ]);
            }

            $update['corporate_actions_status'] = 'success';
            $update['corporate_actions_error'] = null;
        } else {
            $update['corporate_actions_status'] = 'error';
            $update['corporate_actions_error'] = $aiResult['error'];
        }

        if ($row) {
            $this->crud->merge('market_bulletin_contents', $update, ['bulletin_id' => $bulletinId]);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        return $this->getStoredActions($bulletinId, $bulletin, false);
    }

    /**
     * Lecture cache-only des opérations déjà extraites pour un bulletin.
     */
    public function getStoredActions(int $bulletinId, ?array $bulletin = null, bool $cached = true): array {
        $bulletin = $bulletin ?: $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $contentRow = $content[0] ?? null;

        $rows = $this->crud->executeCustomQuery(
            "SELECT a.*, c.symbol AS company_symbol, c.name AS company_name
             FROM market_bulletin_corporate_actions a
             LEFT JOIN companies c ON c.id = a.company_id
             WHERE a.bulletin_id = ?
             ORDER BY a.event_date IS NULL, a.event_date ASC, a.id ASC",
            [$bulletinId]
        ) ?: [];

        return [
            'bulletin' => [
                'id' => $bulletin['id'],
                'title' => $bulletin['title'],
                'publish_date' => $bulletin['publish_date'],
            ],
            'status' => $contentRow['corporate_actions_status'] ?? null,
            'error_message' => $contentRow['corporate_actions_error'] ?? null,
            'provider' => $contentRow['corporate_actions_provider'] ?? null,
            'model' => $contentRow['corporate_actions_model'] ?? null,
            'actions' => $rows,
            'cached' => $cached,
            'updated_at' => $contentRow['corporate_actions_updated_at'] ?? null,
        ];
    }

    /**
     * Vue calendrier tous bulletins confondus, avec filtres optionnels.
     * Utilisée par api_bulletin_corporate_actions.php (action list).
     */
    public function listActions(array $filters = []): array {
        $conditions = [];
        $params = [];

        if (!empty($filters['company_id'])) {
            $conditions[] = 'a.company_id = ?';
            $params[] = $filters['company_id'];
        }
        if (!empty($filters['action_type'])) {
            $conditions[] = 'a.action_type = ?';
            $params[] = $filters['action_type'];
        }
        if (!empty($filters['start_date'])) {
            $conditions[] = 'a.event_date >= ?';
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $conditions[] = 'a.event_date <= ?';
            $params[] = $filters['end_date'];
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $rows = $this->crud->executeCustomQuery(
            "SELECT a.*, c.symbol AS company_symbol, c.name AS company_name,
                    b.publish_date AS bulletin_publish_date, b.title AS bulletin_title
             FROM market_bulletin_corporate_actions a
             LEFT JOIN companies c ON c.id = a.company_id
             JOIN market_bulletins b ON b.id = a.bulletin_id
             $where
             ORDER BY a.event_date IS NULL, a.event_date DESC, b.publish_date DESC",
            $params
        ) ?: [];

        $pending = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE (mbc.extracted_text IS NOT NULL AND mbc.extracted_text != '')
               AND (mbc.corporate_actions_status IS NULL OR mbc.corporate_actions_status != 'success')
             ORDER BY b.publish_date DESC"
        ) ?: [];

        return [
            'actions' => $rows,
            'count' => count($rows),
            'pending_bulletins' => $pending,
            'pending_count' => count($pending),
        ];
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function truncate(string $text): string {
        if (mb_strlen($text) <= self::MAX_BULLETIN_CHARS) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_BULLETIN_CHARS) . "\n\n[...texte tronqué...]";
    }

    private function validDateOrNull(?string $date): ?string {
        if (!$date) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }

    private function buildPrompt(array $bulletin, string $bulletinText): string {
        $dateLabel = $bulletin['publish_date'];
        $typesList = implode(', ', self::ACTION_TYPES);

        return <<<PROMPT
Tu es un analyste opérations sur titres spécialisé sur la BRVM (Bourse
Régionale des Valeurs Mobilières, Afrique de l'Ouest). Le Bulletin Officiel de
la Cote (BOC) du $dateLabel ci-dessous peut mentionner des opérations sur
titres : paiements de dividendes, augmentations de capital, admissions de
nouveaux titres à la cote, avis, convocations ou tenues d'assemblées
générales.

Ta tâche : extraire de façon EXHAUSTIVE toutes les opérations sur titres
mentionnées dans ce bulletin, qu'elles apparaissent dans une section dédiée
("Opérations en cours", "Avis", "Calendrier des assemblées générales") ou
ailleurs dans le texte. N'en oublie aucune, mais n'en invente aucune.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "actions": [
    {
      "company_name": "nom de l'entreprise EXACTEMENT comme écrit dans le bulletin, ne corrige pas l'orthographe ni la casse",
      "action_type": "un de: $typesList",
      "event_date": "date de l'opération au format YYYY-MM-DD si mentionnée explicitement, sinon null (ne déduis jamais une date approximative)",
      "amount": nombre (ex: montant du dividende par action) ou null si non applicable,
      "currency": "code devise si mentionné (ex: FCFA), sinon FCFA par défaut",
      "description": "phrase factuelle résumant l'opération telle que décrite dans le bulletin",
      "source_section": "titre de la section du bulletin d'où provient cette info, si identifiable, sinon null"
    }
  ]
}

Règles impératives :
- Une ligne par opération distincte. Si une même entreprise a deux opérations différentes (ex: dividende ET assemblée générale), crée deux entrées.
- action_type doit être choisi parmi : $typesList (utilise "autre" si aucun ne correspond).
- N'invente JAMAIS une date, un montant ou une devise absents du texte : mets null plutôt que d'extrapoler.
- Si aucune opération sur titres n'est mentionnée dans ce bulletin, réponds avec "actions": [].
- Réponds uniquement avec le JSON.

Texte du bulletin :
$bulletinText
PROMPT;
    }

    /**
     * Schéma JSON structuré (utilisé par AnthropicClient ; ignoré par
     * GeminiClient/GrokClient qui se fient au texte du prompt — voir
     * AiClientInterface::generateContent()).
     */
    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['actions'],
            'properties' => [
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['company_name', 'action_type', 'event_date', 'amount', 'currency', 'description', 'source_section'],
                        'properties' => [
                            'company_name' => ['type' => 'string'],
                            'action_type' => ['type' => 'string', 'enum' => self::ACTION_TYPES],
                            'event_date' => ['type' => ['string', 'null']],
                            'amount' => ['type' => ['number', 'null']],
                            'currency' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'source_section' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
