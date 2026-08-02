<?php
/**
 * Analyse IA comparative d'un ensemble de Bulletins Officiels de la Cote
 * (BOC) sélectionnés : tendance des indices, des volumes/valeurs échangées
 * et de la respiration hausses/baisses sur la période couverte par ces
 * bulletins. Mirror de class/ReportComparisonService.php, adapté : réutilise
 * les analyses individuelles déjà produites par MarketBulletinAnalysisService
 * (session_summary, key_figures, sentiment) comme matière première plutôt
 * que de renvoyer le texte brut de chaque bulletin à l'IA — déclenche
 * l'analyse individuelle d'un bulletin si elle manque encore.
 */
class BulletinComparisonService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    private $crud;
    private $analysisService;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
        $this->analysisService = new MarketBulletinAnalysisService($crud);
    }

    /**
     * @param int[] $bulletinIds
     * @return array Résultat structuré prêt à être renvoyé par l'API
     */
    public function compare(array $bulletinIds, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        $bulletinIds = array_values(array_unique(array_map('intval', $bulletinIds)));
        if (count($bulletinIds) < 2) {
            throw new Exception("Sélectionne au moins 2 bulletins à comparer");
        }
        sort($bulletinIds);

        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $bulletins = $this->findBulletins($bulletinIds);
        if (count($bulletins) < 2) {
            throw new Exception("Au moins 2 des bulletins sélectionnés doivent avoir un texte extrait");
        }

        $requestHash = hash('sha256', json_encode($bulletinIds));
        $computedDate = date('Y-m-d');

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM market_bulletin_comparisons WHERE request_hash = ? AND provider = ? AND model = ? AND computed_date = ? LIMIT 1",
            [$requestHash, $provider, $model, $computedDate]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, true);
        }

        // Récupère (ou déclenche) l'analyse individuelle de chaque bulletin
        $bulletinAnalyses = [];
        $skipped = [];
        foreach ($bulletins as $bulletin) {
            try {
                $bulletinAnalyses[] = [
                    'bulletin' => $bulletin,
                    'analysis' => $this->analysisService->analyze($bulletin['id'], $provider, $model),
                ];
            } catch (Exception $e) {
                $skipped[] = ['bulletin_id' => $bulletin['id'], 'reason' => $e->getMessage()];
            }
        }

        if (count($bulletinAnalyses) < 2) {
            throw new Exception("Moins de 2 bulletins ont pu être analysés (" . count($skipped) . " échec(s))");
        }

        // Trie par date pour des séries temporelles cohérentes
        usort($bulletinAnalyses, fn($a, $b) => strcmp($a['bulletin']['publish_date'], $b['bulletin']['publish_date']));

        $publishDates = array_column(array_column($bulletinAnalyses, 'bulletin'), 'publish_date');
        $indexSeries = $this->getIndexSeries($publishDates);
        $keyFiguresSeries = $this->buildKeyFiguresSeries($bulletinAnalyses);

        $prompt = $this->buildPrompt($bulletinAnalyses, $indexSeries);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $row = [
            'request_hash' => $requestHash,
            'bulletin_ids' => json_encode($bulletinIds),
            'provider' => $provider,
            'model' => $model,
            'computed_date' => $computedDate,
            'input_char_count' => mb_strlen($prompt),
        ];

        if ($aiResult['success']) {
            $data = $aiResult['data'];
            $row['summary'] = $data['period_overview'] ?? null;
            $row['details'] = json_encode([
                'trend_by_index' => $data['trend_by_index'] ?? null,
                'recurring_movers' => $data['recurring_movers'] ?? null,
                'volume_turnover_trend' => $data['volume_turnover_trend'] ?? null,
                'sentiment_evolution' => $data['sentiment_evolution'] ?? null,
                'key_takeaways' => $data['key_takeaways'] ?? null,
                'glossary' => $data['glossary'] ?? null,
                'bulletins' => array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title'], 'publish_date' => $b['publish_date']], $bulletins),
                'chart_data' => [
                    'index_series' => $indexSeries,
                    'key_figures_series' => $keyFiguresSeries,
                ],
                'skipped_bulletins' => $skipped,
            ], JSON_UNESCAPED_UNICODE);
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
            $this->crud->merge('market_bulletin_comparisons', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('market_bulletin_comparisons', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('market_bulletin_comparisons', $rowId);
        return $this->formatResult($savedRow, false);
    }

    /**
     * Dernière comparaison en cache pour cet ensemble de bulletins, sans
     * jamais appeler l'IA.
     */
    public function getLatest(array $bulletinIds): ?array {
        $bulletinIds = array_values(array_unique(array_map('intval', $bulletinIds)));
        sort($bulletinIds);
        $requestHash = hash('sha256', json_encode($bulletinIds));

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM market_bulletin_comparisons WHERE request_hash = ? ORDER BY id DESC LIMIT 1",
            [$requestHash]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->formatResult($rows[0], true);
    }

    private function findBulletins(array $bulletinIds): array {
        $placeholders = implode(',', array_fill(0, count($bulletinIds), '?'));
        $sql = "SELECT * FROM market_bulletins WHERE id IN ($placeholders) AND text_extracted = 1 ORDER BY publish_date ASC";
        return $this->crud->executeCustomQuery($sql, $bulletinIds) ?: [];
    }

    /**
     * Valeurs des indices BRVM pour chaque date de bulletin sélectionnée —
     * matière première fiable pour un graphe de tendance des indices.
     */
    private function getIndexSeries(array $publishDates): array {
        if (empty($publishDates)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($publishDates), '?'));
        $rows = $this->crud->executeCustomQuery(
            "SELECT mi.code, mi.name, iv.trading_date AS date, iv.close_value, iv.variation_percent
             FROM index_values iv
             JOIN market_indices mi ON mi.id = iv.index_id
             WHERE iv.trading_date IN ($placeholders)
             ORDER BY mi.code, iv.trading_date ASC",
            $publishDates
        ) ?: [];

        $byIndex = [];
        foreach ($rows as $row) {
            if (!isset($byIndex[$row['code']])) {
                $byIndex[$row['code']] = ['code' => $row['code'], 'name' => $row['name'], 'data' => []];
            }
            $byIndex[$row['code']]['data'][] = [
                'date' => $row['date'],
                'close_value' => $row['close_value'],
                'variation_percent' => $row['variation_percent'],
            ];
        }
        return array_values($byIndex);
    }

    /**
     * Série des chiffres clés (déjà extraits par l'analyse individuelle de
     * chaque bulletin) — la matière première d'un graphe volume/respiration
     * hausses-baisses dans le temps.
     */
    private function buildKeyFiguresSeries(array $bulletinAnalyses): array {
        return array_map(function ($entry) {
            $bulletin = $entry['bulletin'];
            $figures = $entry['analysis']['analysis']['key_figures'] ?? [];
            return [
                'bulletin_id' => $bulletin['id'],
                'publish_date' => $bulletin['publish_date'],
                'total_volume' => $figures['total_volume'] ?? null,
                'total_turnover' => $figures['total_turnover'] ?? null,
                'advancers_count' => $figures['advancers_count'] ?? null,
                'decliners_count' => $figures['decliners_count'] ?? null,
                'unchanged_count' => $figures['unchanged_count'] ?? null,
            ];
        }, $bulletinAnalyses);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt(array $bulletinAnalyses, array $indexSeries): string {
        $bulletinsBlock = '';
        foreach ($bulletinAnalyses as $entry) {
            $bulletin = $entry['bulletin'];
            $analysis = $entry['analysis']['analysis'] ?? [];
            $figures = $analysis['key_figures'] ?? [];
            $sentiment = $analysis['sentiment'] ?? [];

            $bulletinsBlock .= sprintf(
                "\n[%s]\n" .
                "Résumé: %s\n" .
                "Sentiment: %s (%s)\n" .
                "Chiffres clés: volume=%s, valeur échangée=%s FCFA, hausses=%s, baisses=%s, inchangés=%s\n" .
                "Mouvements notables: %s\n",
                $bulletin['publish_date'],
                $analysis['session_summary'] ?? 'n/a',
                $sentiment['verdict'] ?? 'n/a',
                $sentiment['rationale'] ?? 'n/a',
                $figures['total_volume'] ?? 'n/a',
                $figures['total_turnover'] ?? 'n/a',
                $figures['advancers_count'] ?? 'n/a',
                $figures['decliners_count'] ?? 'n/a',
                $figures['unchanged_count'] ?? 'n/a',
                $analysis['notable_movements'] ?? 'n/a'
            );
        }

        $indexBlock = '';
        foreach ($indexSeries as $serie) {
            $points = array_map(fn($d) => "{$d['date']}: {$d['close_value']} ({$d['variation_percent']}%)", $serie['data']);
            $indexBlock .= sprintf("\n%s (%s) : %s\n", $serie['name'], $serie['code'], implode(' | ', $points));
        }

        $startDate = $bulletinAnalyses[0]['bulletin']['publish_date'];
        $endDate = $bulletinAnalyses[count($bulletinAnalyses) - 1]['bulletin']['publish_date'];

        return <<<PROMPT
Tu es un analyste de marché senior spécialisé sur la BRVM (Bourse Régionale
des Valeurs Mobilières, Afrique de l'Ouest), chargé d'une analyse COMPARATIVE
(pas un simple résumé) portant sur plusieurs Bulletins Officiels de la Cote
(BOC) publiés entre $startDate et $endDate. Cette analyse doit vraiment aider
une décision d'investissement : identifie les tendances, les points
d'inflexion, et appuie chaque affirmation sur les données fournies ci-dessous
(déjà extraites de chaque bulletin individuel — ne les recalcule pas
différemment, utilise-les telles quelles).

Bulletins inclus dans la comparaison (triés par date) :
$bulletinsBlock

Valeurs d'indices sur la période (ancrage chiffré fiable) :
$indexBlock

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "period_overview": "synthèse dense de la période en 4-8 phrases : tendance générale, points d'inflexion",
  "trend_by_index": "évolution de chaque indice sur la période (BRVM Composite, BRVM 30/10, BRVM Prestige, BRVM Principal selon ce qui est disponible), chiffres à l'appui",
  "recurring_movers": "titres ou secteurs qui reviennent plusieurs fois comme mouvements notables sur la période, et pourquoi",
  "volume_turnover_trend": "évolution du volume et de la valeur échangée sur la période (hausse, baisse, stabilité, pics)",
  "sentiment_evolution": "comment le sentiment de marché a évolué jour après jour sur la période (bascules haussier/baissier/neutre/mixte)",
  "key_takeaways": ["point clé 1 orienté aide à la décision", "point clé 2", "point clé 3"],
  "glossary": [{"term": "terme technique utilisé ci-dessus", "explanation": "explication en une phrase simple"}]
}

Règles impératives :
- N'invente aucun chiffre : base-toi uniquement sur les données fournies ci-dessus.
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite.
- Réponds uniquement avec le JSON.
PROMPT;
    }

    private function responseSchema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['period_overview', 'trend_by_index', 'recurring_movers', 'volume_turnover_trend', 'sentiment_evolution', 'key_takeaways', 'glossary'],
            'properties' => [
                'period_overview' => ['type' => 'string'],
                'trend_by_index' => ['type' => 'string'],
                'recurring_movers' => ['type' => 'string'],
                'volume_turnover_trend' => ['type' => 'string'],
                'sentiment_evolution' => ['type' => 'string'],
                'key_takeaways' => ['type' => 'array', 'items' => ['type' => 'string']],
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

    private function formatResult(array $row, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];

        return [
            'bulletin_ids' => json_decode($row['bulletin_ids'], true),
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'analysis' => $row['status'] === 'success' ? array_merge(
                ['period_overview' => $row['summary']],
                array_diff_key($details, ['bulletins' => null, 'chart_data' => null, 'skipped_bulletins' => null])
            ) : null,
            'bulletins' => $details['bulletins'] ?? [],
            'chart_data' => $details['chart_data'] ?? null,
            'skipped_bulletins' => $details['skipped_bulletins'] ?? [],
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
