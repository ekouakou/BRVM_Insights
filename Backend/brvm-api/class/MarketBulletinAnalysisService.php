<?php
/**
 * Orchestration de l'analyse IA d'un Bulletin Officiel de la Cote (BOC) :
 * construit le prompt (texte du bulletin + valeurs d'indices connues en
 * base pour cette date), l'envoie au fournisseur IA demandé, et met en
 * cache le résultat dans market_bulletin_analyses (un appel par couple
 * fournisseur+modèle, pas plus — pas de dimension "date de contexte marché"
 * ici, contrairement à ReportAnalysisService : le bulletin EST déjà daté).
 */
class MarketBulletinAnalysisService {
    private const MAX_BULLETIN_CHARS = 500000;
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    /**
     * Même registre de fournisseurs que ReportAnalysisService — voir
     * class/AiClientInterface.php pour ajouter un fournisseur.
     */
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    /**
     * Clés de la réponse IA à conserver dans market_bulletin_analyses.details.
     * IMPORTANT : toute clé ajoutée au schéma JSON demandé dans buildPrompt()
     * doit être ajoutée ici, sinon elle sera renvoyée par l'IA puis perdue
     * silencieusement au moment de la mise en cache.
     */
    private const DETAIL_FIELDS = [
        'notable_movements',
        'sector_trends',
        'anomalies_or_alerts',
        'sentiment',
        'key_figures',
        'glossary',
    ];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    public function analyze(int $bulletinId, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
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

        // Préfère le markdown restructuré (tableaux propres, voir
        // BulletinMarkdownFormatterService) s'il est disponible : c'est une
        // matière première plus fiable pour l'IA que le dump pdftotext brut
        // (colonnes entremêlées), qui reste le repli sinon.
        $usingMarkdown = !empty($row['formatted_markdown']) && $row['markdown_status'] === 'success';
        $sourceText = $usingMarkdown ? $row['formatted_markdown'] : ($row['extracted_text'] ?? null);

        if (empty($sourceText)) {
            throw new Exception(
                "Le texte de ce bulletin n'a pas encore été extrait. " .
                "Utilise le bouton 'Traiter' avant de l'analyser."
            );
        }

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM market_bulletin_analyses WHERE bulletin_id = ? AND provider = ? AND model = ? LIMIT 1",
            [$bulletinId, $provider, $model]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, $bulletin, true);
        }

        $indexContext = $this->getIndexContext($bulletin['publish_date']);
        $truncatedText = $this->truncate($sourceText);

        $prompt = $this->buildPrompt($bulletin, $indexContext, $truncatedText);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $row = [
            'bulletin_id' => $bulletinId,
            'provider' => $provider,
            'model' => $model,
            'input_char_count' => mb_strlen($truncatedText),
        ];

        if ($aiResult['success']) {
            $data = $aiResult['data'];
            $row['summary'] = $data['session_summary'] ?? null;

            $details = [];
            foreach (self::DETAIL_FIELDS as $field) {
                $details[$field] = $data[$field] ?? null;
            }
            $row['details'] = json_encode($details, JSON_UNESCAPED_UNICODE);

            $row['status'] = 'success';
            $row['error_message'] = null;
            $row['raw_response'] = $aiResult['raw'] ?? null;
        } else {
            $row['status'] = 'failed';
            $row['error_message'] = $aiResult['error'];
            $row['raw_response'] = $aiResult['raw'] ?? null;
            $row['summary'] = null;
            $row['details'] = null;
        }

        if ($existingRow) {
            $this->crud->merge('market_bulletin_analyses', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('market_bulletin_analyses', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('market_bulletin_analyses', $rowId);

        return $this->formatResult($savedRow, $bulletin, false);
    }

    /**
     * Dernière analyse en cache pour un bulletin, sans jamais appeler de fournisseur IA.
     */
    public function getLatest(int $bulletinId, ?string $provider = null, ?string $model = null): ?array {
        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé (id=$bulletinId)");
        }

        $conditions = ['bulletin_id = ?'];
        $params = [$bulletinId];
        if ($provider) {
            $conditions[] = 'provider = ?';
            $params[] = $provider;
        }
        if ($model) {
            $conditions[] = 'model = ?';
            $params[] = $model;
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM market_bulletin_analyses WHERE " . implode(' AND ', $conditions) .
            " ORDER BY id DESC LIMIT 1",
            $params
        );

        if (empty($rows)) {
            return null;
        }

        return $this->formatResult($rows[0], $bulletin, true);
    }

    /**
     * Historique des analyses d'un bulletin.
     */
    public function history(int $bulletinId): array {
        $rows = $this->crud->find('market_bulletin_analyses', ['bulletin_id' => $bulletinId], PDO::FETCH_ASSOC, true, 'id DESC');

        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'bulletin_id' => $row['bulletin_id'],
                'provider' => $row['provider'],
                'model' => $row['model'],
                'status' => $row['status'],
                'summary' => $row['summary'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }, $rows);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    /**
     * Valeurs des indices BRVM (Composite, BRVM10...) connues en base pour
     * la date du bulletin — sert d'ancrage chiffré fiable en plus du texte
     * du bulletin lui-même (qui contient normalement ces mêmes valeurs).
     */
    private function getIndexContext(string $publishDate): array {
        $rows = $this->crud->executeCustomQuery(
            "SELECT mi.code, mi.name, iv.close_value, iv.variation_percent
             FROM index_values iv
             JOIN market_indices mi ON mi.id = iv.index_id
             WHERE iv.trading_date = ?",
            [$publishDate]
        ) ?: [];

        return $rows;
    }

    private function truncate(string $text): string {
        if (mb_strlen($text) <= self::MAX_BULLETIN_CHARS) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_BULLETIN_CHARS) . "\n\n[...texte tronqué...]";
    }

    private function buildPrompt(array $bulletin, array $indexContext, string $bulletinText): string {
        $dateLabel = $bulletin['publish_date'];

        $indexBlock = "Aucune valeur d'indice connue en base pour cette date.";
        if (!empty($indexContext)) {
            $lines = array_map(function ($row) {
                return sprintf(
                    "%s (%s) : %s (variation %s%%)",
                    $row['name'],
                    $row['code'],
                    $row['close_value'],
                    $row['variation_percent'] ?? '?'
                );
            }, $indexContext);
            $indexBlock = implode("\n", $lines);
        }

        return <<<PROMPT
Tu es un analyste de marché senior spécialisé sur la BRVM (Bourse Régionale
des Valeurs Mobilières, Afrique de l'Ouest). Un client exigeant t'a confié la
rédaction d'une note quotidienne de synthèse de séance à partir du Bulletin
Officiel de la Cote (BOC) du $dateLabel, du niveau d'une note de marché
professionnelle : précise, quantitative, qui va au-delà d'un simple résumé.
N'omets aucune donnée chiffrée pertinente présente dans le texte (volumes,
valeurs échangées, nombre de titres en hausse/baisse, valeurs d'indices).

Valeurs d'indices connues en base pour cette date (ancrage fiable, à
recouper avec le texte du bulletin) :
$indexBlock

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "session_summary": "synthèse dense en 4-8 phrases de la séance : tendance générale, indices, volumes, chiffres à l'appui",
  "notable_movements": "commentaire factuel sur les titres/mouvements les plus marquants de la séance (plus fortes hausses/baisses, plus forts volumes) tels qu'ils apparaissent dans le bulletin",
  "sector_trends": "lecture qualitative par secteur si le bulletin permet de le déduire (quels secteurs/titres ont été actifs ou notables), sinon null",
  "anomalies_or_alerts": "éléments inhabituels explicitement mentionnés dans le bulletin (suspension de cotation, nouvelle admission, volume anormal...), sinon null",
  "sentiment": {
    "verdict": "haussier | baissier | neutre | mixte",
    "rationale": "justification factuelle basée sur les indices et la respiration hausses/baisses du marché"
  },
  "key_figures": {
    "total_volume": nombre ou null,
    "total_turnover": nombre ou null (valeur totale échangée en FCFA),
    "advancers_count": nombre ou null (titres en hausse),
    "decliners_count": nombre ou null (titres en baisse),
    "unchanged_count": nombre ou null (titres inchangés)
  },
  "glossary": [{"term": "terme technique utilisé ci-dessus", "explanation": "explication en une phrase simple"}]
}

Règles impératives :
- N'invente JAMAIS un chiffre absent du texte ou des valeurs d'indices fournies ci-dessus : mets null plutôt que d'extrapoler.
- Reste factuel et neutre : jamais de recommandation d'achat/vente.
- N'inclus dans "glossary" que les termes techniques que tu as réellement utilisés ailleurs dans la réponse.
- Réponds uniquement avec le JSON.

Texte du bulletin :
$bulletinText
PROMPT;
    }

    /**
     * Schéma JSON structuré (utilisé par AnthropicClient ; ignoré par
     * GeminiClient qui se fie au texte du prompt — voir
     * AiClientInterface::generateContent()).
     */
    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['session_summary', 'notable_movements', 'sector_trends', 'anomalies_or_alerts', 'sentiment', 'key_figures', 'glossary'],
            'properties' => [
                'session_summary' => ['type' => 'string'],
                'notable_movements' => ['type' => 'string'],
                'sector_trends' => ['type' => ['string', 'null']],
                'anomalies_or_alerts' => ['type' => ['string', 'null']],
                'sentiment' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['verdict', 'rationale'],
                    'properties' => [
                        'verdict' => ['type' => 'string', 'enum' => ['haussier', 'baissier', 'neutre', 'mixte']],
                        'rationale' => ['type' => 'string'],
                    ],
                ],
                'key_figures' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['total_volume', 'total_turnover', 'advancers_count', 'decliners_count', 'unchanged_count'],
                    'properties' => [
                        'total_volume' => ['type' => ['number', 'null']],
                        'total_turnover' => ['type' => ['number', 'null']],
                        'advancers_count' => ['type' => ['integer', 'null']],
                        'decliners_count' => ['type' => ['integer', 'null']],
                        'unchanged_count' => ['type' => ['integer', 'null']],
                    ],
                ],
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

    private function formatResult(array $row, array $bulletin, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];

        return [
            'bulletin' => [
                'id' => $bulletin['id'],
                'title' => $bulletin['title'],
                'publish_date' => $bulletin['publish_date'],
            ],
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'analysis' => $row['status'] === 'success' ? array_merge(
                ['session_summary' => $row['summary']],
                $details
            ) : null,
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
