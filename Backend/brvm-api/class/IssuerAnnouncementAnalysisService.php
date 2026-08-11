<?php
/**
 * Analyse IA d'une annonce émetteur/publication BRVM — mirror de
 * MarketBulletinAnalysisService pour issuer_announcement_analyses : cache
 * par (announcement_id, provider, model), markdown restructuré préféré au
 * texte brut, sortie structurée (résumé, points clés, dates, montants,
 * pertinence marché) plutôt qu'un texte libre.
 */
class IssuerAnnouncementAnalysisService {
    private const MAX_CHARS = 300000;
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    /**
     * Clés de la réponse IA conservées dans issuer_announcement_analyses.details
     * (toute clé ajoutée au schéma doit être ajoutée ici, sinon perdue en cache).
     */
    private const DETAIL_FIELDS = [
        'key_points',
        'important_dates',
        'amounts',
        'potential_market_relevance',
        'glossary',
    ];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    public function analyze(int $announcementId, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $announcement = $this->crud->findById('issuer_announcements', $announcementId);
        if (!$announcement) {
            throw new Exception("Annonce non trouvée (id=$announcementId)");
        }

        $content = $this->crud->find('issuer_announcement_contents', ['announcement_id' => $announcementId]);
        $row = $content[0] ?? null;

        $usingMarkdown = !empty($row['formatted_markdown']) && ($row['markdown_status'] ?? null) === 'success';
        $sourceText = $usingMarkdown ? $row['formatted_markdown'] : ($row['extracted_text'] ?? null);

        if (empty($sourceText)) {
            throw new Exception("Le texte de cette annonce n'a pas encore été extrait. Utilise 'Traiter' d'abord.");
        }

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM issuer_announcement_analyses WHERE announcement_id = ? AND provider = ? AND model = ? LIMIT 1",
            [$announcementId, $provider, $model]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, $announcement, true);
        }

        $truncated = mb_strlen($sourceText) > self::MAX_CHARS
            ? mb_substr($sourceText, 0, self::MAX_CHARS) . "\n\n[...texte tronqué...]"
            : $sourceText;

        $prompt = $this->buildPrompt($announcement, $truncated);
        $clientClass = self::PROVIDERS[$provider]['class'];
        $client = new $clientClass();
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $rowData = [
            'announcement_id' => $announcementId,
            'provider' => $provider,
            'model' => $model,
            'input_char_count' => mb_strlen($truncated),
        ];

        if ($aiResult['success']) {
            $data = $aiResult['data'];
            $rowData['summary'] = $data['summary'] ?? null;

            $details = [];
            foreach (self::DETAIL_FIELDS as $field) {
                $details[$field] = $data[$field] ?? null;
            }
            $rowData['details'] = json_encode($details, JSON_UNESCAPED_UNICODE);
            $rowData['status'] = 'success';
            $rowData['error_message'] = null;
            $rowData['raw_response'] = $aiResult['raw'] ?? null;
        } else {
            $rowData['status'] = 'failed';
            $rowData['error_message'] = $aiResult['error'];
            $rowData['raw_response'] = $aiResult['raw'] ?? null;
            $rowData['summary'] = null;
            $rowData['details'] = null;
        }

        if ($existingRow) {
            $this->crud->merge('issuer_announcement_analyses', $rowData, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('issuer_announcement_analyses', $rowData);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('issuer_announcement_analyses', $rowId);

        return $this->formatResult($savedRow, $announcement, false);
    }

    /**
     * Dernière analyse en cache, sans jamais appeler de fournisseur IA.
     */
    public function getLatest(int $announcementId): ?array {
        $announcement = $this->crud->findById('issuer_announcements', $announcementId);
        if (!$announcement) {
            throw new Exception("Annonce non trouvée (id=$announcementId)");
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM issuer_announcement_analyses WHERE announcement_id = ? ORDER BY id DESC LIMIT 1",
            [$announcementId]
        );

        return !empty($rows) ? $this->formatResult($rows[0], $announcement, true) : null;
    }

    private function buildPrompt(array $announcement, string $sourceText): string {
        $typeLabel = BRVMAnnouncementsScraper::TYPES[$announcement['announcement_type']]['label'] ?? $announcement['announcement_type'];
        $context = "Type d'annonce : $typeLabel"
            . ($announcement['publish_date'] ? "\nDate de publication : {$announcement['publish_date']}" : '')
            . ($announcement['company_name_raw'] ? "\nÉmetteur (tel qu'affiché par la BRVM) : {$announcement['company_name_raw']}" : '')
            . "\nTitre : {$announcement['title']}";

        return <<<PROMPT
Tu es un analyste financier senior spécialisé sur la BRVM (Bourse Régionale
des Valeurs Mobilières, Afrique de l'Ouest). Analyse cette annonce
officielle pour un investisseur particulier qui n'est pas familier du
jargon boursier.

$context

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "summary": "résumé factuel et accessible en 3-5 phrases : qui, quoi, quand, combien",
  "key_points": ["point clé factuel 1", "point clé 2", "..."],
  "important_dates": [{"date": "YYYY-MM-DD ou texte si non précis", "event": "à quoi correspond cette date"}],
  "amounts": [{"label": "nature du montant (ex: dividende net par action)", "value": "montant avec devise tel qu'écrit"}],
  "potential_market_relevance": "en quoi cette annonce peut intéresser un investisseur (facteurs à surveiller), SANS recommandation d'achat/vente ni prédiction de cours — ou null si purement administratif",
  "glossary": [{"term": "terme technique utilisé ci-dessus", "explanation": "explication en une phrase simple"}]
}

Règles impératives :
- N'invente JAMAIS un chiffre, une date ou un fait absent du texte : mets null ou omets plutôt que d'extrapoler.
- Reste factuel et neutre : jamais de recommandation d'achat/vente.
- N'inclus dans "glossary" que les termes réellement utilisés ailleurs dans ta réponse.
- Réponds uniquement avec le JSON.

Texte de l'annonce :
$sourceText
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'key_points', 'important_dates', 'amounts', 'potential_market_relevance', 'glossary'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'important_dates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['date', 'event'],
                        'properties' => [
                            'date' => ['type' => 'string'],
                            'event' => ['type' => 'string'],
                        ],
                    ],
                ],
                'amounts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'value'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                        ],
                    ],
                ],
                'potential_market_relevance' => ['type' => ['string', 'null']],
                'glossary' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['term', 'explanation'],
                        'properties' => [
                            'term' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatResult(array $row, array $announcement, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];

        return [
            'announcement' => [
                'id' => (int) $announcement['id'],
                'title' => $announcement['title'],
                'announcement_type' => $announcement['announcement_type'],
                'publish_date' => $announcement['publish_date'],
            ],
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'analysis' => $row['status'] === 'success' ? array_merge(
                ['summary' => $row['summary']],
                $details
            ) : null,
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
