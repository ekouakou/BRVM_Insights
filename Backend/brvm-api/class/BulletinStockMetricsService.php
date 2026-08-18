<?php
/**
 * Extraction IA structurée du PER et du rendement net officiels BRVM, tels
 * qu'imprimés dans le tableau par valeur de chaque Bulletin Officiel de la
 * Cote (BOC). Même architecture que BulletinCorporateActionsService (voir
 * ce fichier pour le pattern d'ensemble) : chaque ligne du tableau devient
 * une ligne interrogeable dans bulletin_stock_metrics, une extraction par
 * bulletin écrase la précédente (source de vérité, pas d'accumulation).
 *
 * Pourquoi : FinancialStatements.tsx calcule son propre PER/PBR à partir
 * de companies.shares_outstanding — une seule valeur "actuelle", sans
 * historique. Un PER calculé sur un rapport ancien utilise donc le nombre
 * d'actions d'aujourd'hui, faussé en cas d'augmentation de capital/rachat/
 * split depuis. La BRVM publie déjà, par valeur et par séance, un PER
 * calculé avec le bon nombre d'actions à cette date précise — le capturer
 * ici évite d'avoir à reconstruire un historique d'actions en circulation.
 *
 * Rattachement par SYMBOLE (companies.symbol), pas par nom via
 * CompanySlugMatcher : le tableau du BOC liste déjà le code exact
 * (ex: "SNTS"), correspondance directe et fiable — pas besoin de
 * rapprochement flou ici.
 */
class BulletinStockMetricsService {
    private const MAX_BULLETIN_CHARS = 500000;

    /** Même registre de fournisseurs que BulletinCorporateActionsService. */
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * Lance (ou relit en cache) l'extraction des métriques par valeur d'un
     * bulletin. Comme pour les opérations sur titres : pas de cache par
     * (bulletin_id, provider, model), une réextraction remplace les lignes
     * existantes pour ce bulletin.
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

        if (!$forceRefresh && ($row['stock_metrics_status'] ?? null) === 'success') {
            return $this->getStoredMetrics($bulletinId, $bulletin, true);
        }

        $truncatedText = $this->truncate($sourceText);
        $prompt = $this->buildPrompt($bulletin, $truncatedText);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $update = [
            'stock_metrics_provider' => $provider,
            'stock_metrics_model' => $model,
            'stock_metrics_updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($aiResult['success']) {
            $metrics = $aiResult['data']['metrics'] ?? [];
            $companies = $this->crud->executeCustomQuery("SELECT id, symbol FROM companies WHERE active = 1") ?: [];
            $bySymbol = [];
            foreach ($companies as $c) {
                $bySymbol[strtoupper(trim($c['symbol']))] = (int) $c['id'];
            }

            // Réextraction = source de vérité : on repart de zéro pour ce
            // bulletin plutôt que d'accumuler des doublons à chaque relance.
            // DynamiqueCrud::remove() ajoute un LIMIT 1 systématique (pensé
            // pour supprimer UNE ligne identifiée) : sur plusieurs dizaines
            // de lignes par bulletin, il n'en supprimerait qu'une seule et
            // les persist() suivants échoueraient en silence sur la
            // contrainte UNIQUE (bulletin_id, symbol_raw) — d'où un DELETE
            // direct, sans limite, ici.
            $this->crud->executeCustomQuery("DELETE FROM bulletin_stock_metrics WHERE bulletin_id = ?", [$bulletinId]);

            foreach ($metrics as $metric) {
                $rawSymbol = strtoupper(trim((string) ($metric['symbol'] ?? '')));
                if ($rawSymbol === '') continue;

                $this->crud->persist('bulletin_stock_metrics', [
                    'bulletin_id' => $bulletinId,
                    'publish_date' => $bulletin['publish_date'],
                    'company_id' => $bySymbol[$rawSymbol] ?? null,
                    'symbol_raw' => $rawSymbol,
                    'company_name_raw' => $metric['company_name'] ?? null,
                    'close_price' => is_numeric($metric['close_price'] ?? null) ? $metric['close_price'] : null,
                    'per' => is_numeric($metric['per'] ?? null) ? $metric['per'] : null,
                    'yield_net_percent' => is_numeric($metric['yield_net_percent'] ?? null) ? $metric['yield_net_percent'] : null,
                ]);
            }

            $update['stock_metrics_status'] = 'success';
            $update['stock_metrics_error'] = null;
        } else {
            $update['stock_metrics_status'] = 'error';
            $update['stock_metrics_error'] = $aiResult['error'];
        }

        if ($row) {
            $this->crud->merge('market_bulletin_contents', $update, ['bulletin_id' => $bulletinId]);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        return $this->getStoredMetrics($bulletinId, $bulletin, false);
    }

    /**
     * Lecture cache-only des métriques déjà extraites pour un bulletin.
     */
    public function getStoredMetrics(int $bulletinId, ?array $bulletin = null, bool $cached = true): array {
        $bulletin = $bulletin ?: $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $contentRow = $content[0] ?? null;

        $rows = $this->crud->executeCustomQuery(
            "SELECT m.*, c.symbol AS company_symbol, c.name AS company_name
             FROM bulletin_stock_metrics m
             LEFT JOIN companies c ON c.id = m.company_id
             WHERE m.bulletin_id = ?
             ORDER BY m.symbol_raw ASC",
            [$bulletinId]
        ) ?: [];

        return [
            'bulletin' => [
                'id' => $bulletin['id'],
                'title' => $bulletin['title'],
                'publish_date' => $bulletin['publish_date'],
            ],
            'status' => $contentRow['stock_metrics_status'] ?? null,
            'error_message' => $contentRow['stock_metrics_error'] ?? null,
            'provider' => $contentRow['stock_metrics_provider'] ?? null,
            'model' => $contentRow['stock_metrics_model'] ?? null,
            'metrics' => $rows,
            'cached' => $cached,
            'updated_at' => $contentRow['stock_metrics_updated_at'] ?? null,
        ];
    }

    /**
     * Vue d'ensemble filtrable de toutes les métriques déjà extraites, plus
     * la liste des bulletins dont le texte est disponible mais pas encore
     * traité — même forme que BulletinCorporateActionsService::listActions().
     */
    public function listMetrics(array $filters = []): array {
        $conditions = [];
        $params = [];

        if (!empty($filters['company_id'])) {
            $conditions[] = 'm.company_id = ?';
            $params[] = $filters['company_id'];
        }
        if (!empty($filters['bulletin_id'])) {
            $conditions[] = 'm.bulletin_id = ?';
            $params[] = $filters['bulletin_id'];
        }
        if (!empty($filters['start_date'])) {
            $conditions[] = 'm.publish_date >= ?';
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $conditions[] = 'm.publish_date <= ?';
            $params[] = $filters['end_date'];
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $rows = $this->crud->executeCustomQuery(
            "SELECT m.*, c.symbol AS company_symbol, c.name AS company_name,
                    b.title AS bulletin_title
             FROM bulletin_stock_metrics m
             LEFT JOIN companies c ON c.id = m.company_id
             JOIN market_bulletins b ON b.id = m.bulletin_id
             $where
             ORDER BY m.publish_date DESC, m.symbol_raw ASC",
            $params
        ) ?: [];

        $pending = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE (mbc.extracted_text IS NOT NULL AND mbc.extracted_text != '')
               AND (mbc.stock_metrics_status IS NULL OR mbc.stock_metrics_status != 'success')
             ORDER BY b.publish_date DESC"
        ) ?: [];

        // Bulletins déjà extraits, pour peupler un sélecteur explicite « choisir
        // un bulletin » — respecte le filtre entreprise (ne propose que les
        // bulletins où cette entreprise apparaît) mais jamais les filtres de
        // date, sinon le sélecteur se viderait dès qu'une date de référence
        // est choisie alors que c'est justement l'alternative à cette date.
        $bulletinConditions = ['mbc.stock_metrics_status = \'success\''];
        $bulletinParams = [];
        if (!empty($filters['company_id'])) {
            $bulletinConditions[] = 'EXISTS (SELECT 1 FROM bulletin_stock_metrics m2 WHERE m2.bulletin_id = b.id AND m2.company_id = ?)';
            $bulletinParams[] = $filters['company_id'];
        }
        $bulletins = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE " . implode(' AND ', $bulletinConditions) . "
             ORDER BY b.publish_date DESC",
            $bulletinParams
        ) ?: [];

        return [
            'metrics' => $rows,
            'count' => count($rows),
            'bulletins' => $bulletins,
            'pending_bulletins' => $pending,
            'pending_count' => count($pending),
        ];
    }

    /**
     * Métrique officielle BRVM la plus proche (à la date demandée ou avant)
     * pour une entreprise donnée — même logique que le cours retenu pour le
     * PER calculé dans api_financial_statements.php (dernière clôture
     * connue à la date de clôture de l'exercice, jamais après).
     */
    public function findNearest(int $companyId, string $date): ?array {
        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM bulletin_stock_metrics
             WHERE company_id = ? AND publish_date <= ?
             ORDER BY publish_date DESC LIMIT 1",
            [$companyId, $date]
        );
        return $rows[0] ?? null;
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

    private function buildPrompt(array $bulletin, string $bulletinText): string {
        $dateLabel = $bulletin['publish_date'];

        return <<<PROMPT
Tu es un analyste de marché spécialisé sur la BRVM (Bourse Régionale des
Valeurs Mobilières, Afrique de l'Ouest). Le Bulletin Officiel de la Cote
(BOC) du $dateLabel ci-dessous contient un tableau récapitulatif "par
valeur" (une ligne par action cotée), avec des colonnes telles que :
Symbole, Titre (nom), Cours (précédent/ouverture/clôture), Volume, Valeur
échangée, Variation, Dernier dividende, Rendement net ("Rdt. Net"), et PER.

Ta tâche : extraire de façon EXHAUSTIVE la ligne de CHAQUE action présente
dans ce tableau — actions du Compartiment Principal ET du Compartiment
Prestige, toutes présentes dans le même tableau. N'en oublie aucune, mais
n'en invente aucune.

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "metrics": [
    {
      "symbol": "code exact de la valeur tel qu'écrit dans le bulletin (ex: SNTS, ORAC)",
      "company_name": "nom de l'entreprise tel qu'écrit dans le bulletin",
      "close_price": nombre (cours de clôture du jour) ou null si absent,
      "per": nombre (colonne PER) ou null si absent, tiret, ou non applicable (ex: résultat négatif)",
      "yield_net_percent": nombre (colonne Rendement net / "Rdt. Net", en %) ou null si absent
    }
  ]
}

Règles impératives :
- Une ligne par action du tableau, jamais une ligne par obligation ou par ligne d'indice.
- N'invente JAMAIS une valeur numérique absente ou illisible du tableau : mets null plutôt que d'extrapoler ou d'estimer.
- Si le PER ou le rendement affiche un tiret ("-") ou est manifestement non calculable dans le bulletin, mets null.
- Si aucun tableau par valeur n'est présent dans ce texte, réponds avec "metrics": [].
- Réponds uniquement avec le JSON.

Texte du bulletin :
$bulletinText
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['metrics'],
            'properties' => [
                'metrics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['symbol', 'company_name', 'close_price', 'per', 'yield_net_percent'],
                        'properties' => [
                            'symbol' => ['type' => 'string'],
                            'company_name' => ['type' => 'string'],
                            'close_price' => ['type' => ['number', 'null']],
                            'per' => ['type' => ['number', 'null']],
                            'yield_net_percent' => ['type' => ['number', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
