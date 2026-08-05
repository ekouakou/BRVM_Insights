<?php
/**
 * Analyse IA combinée d'un ensemble mixte de rapports de sociétés ET de
 * bulletins de marché, sélectionnés librement par l'utilisateur (pas limité
 * à une entreprise ni à une période). Corrèle les fondamentaux d'entreprise
 * (rapports) avec le contexte de marché du moment (bulletins) — utile pour
 * situer la performance d'une ou plusieurs sociétés dans le contexte plus
 * large du marché. Réutilise les analyses individuelles déjà produites par
 * ReportAnalysisService et MarketBulletinAnalysisService comme matière
 * première (déclenche celles qui manquent), même principe que
 * ReportComparisonService / BulletinComparisonService dont ce service
 * s'inspire directement.
 */
class CombinedAnalysisService {
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    private $crud;
    private $reportAnalysisService;
    private $bulletinAnalysisService;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
        $this->reportAnalysisService = new ReportAnalysisService($crud);
        $this->bulletinAnalysisService = new MarketBulletinAnalysisService($crud);
    }

    /**
     * @param int[] $reportIds
     * @param int[] $bulletinIds
     */
    public function compare(array $reportIds, array $bulletinIds, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        $reportIds = array_values(array_unique(array_map('intval', $reportIds)));
        $bulletinIds = array_values(array_unique(array_map('intval', $bulletinIds)));
        sort($reportIds);
        sort($bulletinIds);

        if (empty($reportIds) || empty($bulletinIds)) {
            throw new Exception(
                "Sélectionne au moins un rapport ET au moins un bulletin " .
                "(pour comparer uniquement des rapports entre eux, ou uniquement des bulletins entre eux, " .
                "utilise les pages Rapports / Bulletins directement)."
            );
        }

        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $reports = $this->findReports($reportIds);
        $bulletins = $this->findBulletins($bulletinIds);
        if (count($reports) < 1 || count($bulletins) < 1) {
            throw new Exception("Au moins un rapport et un bulletin avec texte extrait sont nécessaires");
        }

        $requestHash = hash('sha256', json_encode([$reportIds, $bulletinIds]));
        $computedDate = date('Y-m-d');

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM combined_analyses WHERE request_hash = ? AND provider = ? AND model = ? AND computed_date = ? LIMIT 1",
            [$requestHash, $provider, $model, $computedDate]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, true);
        }

        $companies = $this->getCompaniesById(array_column($reports, 'company_id'));

        $reportAnalyses = [];
        $skippedReports = [];
        foreach ($reports as $report) {
            try {
                $reportAnalyses[] = [
                    'report' => $report,
                    'analysis' => $this->reportAnalysisService->analyze($report['id'], $provider, $model),
                ];
            } catch (Exception $e) {
                $skippedReports[] = ['report_id' => $report['id'], 'reason' => $e->getMessage()];
            }
        }

        $bulletinAnalyses = [];
        $skippedBulletins = [];
        foreach ($bulletins as $bulletin) {
            try {
                $bulletinAnalyses[] = [
                    'bulletin' => $bulletin,
                    'analysis' => $this->bulletinAnalysisService->analyze($bulletin['id'], $provider, $model),
                ];
            } catch (Exception $e) {
                $skippedBulletins[] = ['bulletin_id' => $bulletin['id'], 'reason' => $e->getMessage()];
            }
        }

        if (empty($reportAnalyses) || empty($bulletinAnalyses)) {
            throw new Exception(
                "Trop d'échecs pour produire une analyse combinée (" .
                count($skippedReports) . " rapport(s) et " . count($skippedBulletins) . " bulletin(s) en échec)"
            );
        }

        usort($bulletinAnalyses, fn($a, $b) => strcmp($a['bulletin']['publish_date'], $b['bulletin']['publish_date']));

        [$startDate, $endDate] = $this->resolveDateRange($reports, $bulletins);
        $priceSeries = $this->getPriceSeries(array_unique(array_column($reports, 'company_id')), $companies, $startDate, $endDate);
        $indexSeries = $this->getIndexSeries(array_column(array_column($bulletinAnalyses, 'bulletin'), 'publish_date'));

        $prompt = $this->buildPrompt($companies, $reportAnalyses, $bulletinAnalyses);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $row = [
            'request_hash' => $requestHash,
            'report_ids' => json_encode($reportIds),
            'bulletin_ids' => json_encode($bulletinIds),
            'provider' => $provider,
            'model' => $model,
            'computed_date' => $computedDate,
            'input_char_count' => mb_strlen($prompt),
        ];

        if ($aiResult['success']) {
            $data = $aiResult['data'];
            $row['summary'] = $data['combined_overview'] ?? null;
            $row['details'] = json_encode([
                'company_performance_notes' => $data['company_performance_notes'] ?? null,
                'market_context_summary' => $data['market_context_summary'] ?? null,
                'correlation_analysis' => $data['correlation_analysis'] ?? null,
                'timeline_narrative' => $data['timeline_narrative'] ?? null,
                'decision_support_notes' => $data['decision_support_notes'] ?? null,
                'key_takeaways' => $data['key_takeaways'] ?? null,
                'glossary' => $data['glossary'] ?? null,
                'companies' => array_values($companies),
                'reports' => array_map(fn($r) => ['id' => $r['id'], 'title' => $r['title'], 'publish_date' => $r['publish_date']], $reports),
                'bulletins' => array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title'], 'publish_date' => $b['publish_date']], $bulletins),
                'chart_data' => [
                    'price_series' => $priceSeries,
                    'index_series' => $indexSeries,
                ],
                'skipped_reports' => $skippedReports,
                'skipped_bulletins' => $skippedBulletins,
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
            $this->crud->merge('combined_analyses', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('combined_analyses', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('combined_analyses', $rowId);
        return $this->formatResult($savedRow, false);
    }

    public function getLatest(array $reportIds, array $bulletinIds): ?array {
        $reportIds = array_values(array_unique(array_map('intval', $reportIds)));
        $bulletinIds = array_values(array_unique(array_map('intval', $bulletinIds)));
        sort($reportIds);
        sort($bulletinIds);
        $requestHash = hash('sha256', json_encode([$reportIds, $bulletinIds]));

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM combined_analyses WHERE request_hash = ? ORDER BY id DESC LIMIT 1",
            [$requestHash]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->formatResult($rows[0], true);
    }

    /**
     * Historique des analyses combinées pour cette sélection exacte de
     * rapports+bulletins (tous fournisseurs/modèles confondus), du plus
     * récent au plus ancien.
     */
    public function history(array $reportIds, array $bulletinIds): array {
        $reportIds = array_values(array_unique(array_map('intval', $reportIds)));
        $bulletinIds = array_values(array_unique(array_map('intval', $bulletinIds)));
        sort($reportIds);
        sort($bulletinIds);
        $requestHash = hash('sha256', json_encode([$reportIds, $bulletinIds]));

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM combined_analyses WHERE request_hash = ? ORDER BY id DESC",
            [$requestHash]
        ) ?: [];

        return array_map(fn($row) => $this->formatResult($row, true), $rows);
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse combinée
     * déjà enregistrée — voir ChartAnalysisService::rate() pour le même
     * pattern.
     */
    public function rate(int $id, ?int $rating, ?string $notes, bool $ratingProvided, bool $notesProvided): array {
        if ($ratingProvided && $rating !== null && ($rating < 1 || $rating > 5)) {
            throw new Exception("La note doit être comprise entre 1 et 5");
        }

        $row = $this->crud->findById('combined_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse combinée non trouvée (id=$id)");
        }

        $update = [];
        if ($ratingProvided) $update['rating'] = $rating;
        if ($notesProvided) $update['notes'] = $notes;

        if (!empty($update)) {
            $this->crud->merge('combined_analyses', $update, ['id' => $id]);
        }

        $updatedRow = $this->crud->findById('combined_analyses', $id);
        return $this->formatResult($updatedRow, true);
    }

    private function findReports(array $reportIds): array {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        return $this->crud->executeCustomQuery(
            "SELECT * FROM company_reports WHERE id IN ($placeholders) AND text_extracted = 1",
            $reportIds
        ) ?: [];
    }

    private function findBulletins(array $bulletinIds): array {
        $placeholders = implode(',', array_fill(0, count($bulletinIds), '?'));
        return $this->crud->executeCustomQuery(
            "SELECT * FROM market_bulletins WHERE id IN ($placeholders) AND text_extracted = 1 ORDER BY publish_date ASC",
            $bulletinIds
        ) ?: [];
    }

    private function getCompaniesById(array $companyIds): array {
        $companyIds = array_values(array_unique(array_filter($companyIds)));
        if (empty($companyIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $rows = $this->crud->executeCustomQuery(
            "SELECT id, symbol, name FROM companies WHERE id IN ($placeholders)",
            $companyIds
        ) ?: [];

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }
        return $byId;
    }

    /**
     * Période couvrant à la fois les rapports et les bulletins sélectionnés,
     * utilisée pour le graphe de cours (élargie de quelques jours de part et
     * d'autre pour donner du contexte visuel).
     */
    private function resolveDateRange(array $reports, array $bulletins): array {
        $dates = array_filter(array_merge(
            array_column($reports, 'publish_date'),
            array_column($bulletins, 'publish_date')
        ));
        sort($dates);
        $start = $dates[0] ?? date('Y-m-d', strtotime('-30 days'));
        $end = end($dates) ?: date('Y-m-d');
        return [date('Y-m-d', strtotime($start . ' -7 days')), date('Y-m-d', strtotime($end . ' +7 days'))];
    }

    private function getPriceSeries(array $companyIds, array $companies, string $startDate, string $endDate): array {
        $series = [];
        foreach ($companyIds as $companyId) {
            $data = $this->crud->executeCustomQuery(
                "SELECT trading_date AS date, open_price AS open, high_price AS high,
                        low_price AS low, close_price AS close, volume
                 FROM stock_quotes
                 WHERE company_id = ? AND trading_date BETWEEN ? AND ?
                 ORDER BY trading_date ASC",
                [$companyId, $startDate, $endDate]
            ) ?: [];

            $series[] = [
                'company_id' => (int) $companyId,
                'symbol' => $companies[$companyId]['symbol'] ?? null,
                'data' => $data,
            ];
        }
        return $series;
    }

    private function getIndexSeries(array $publishDates): array {
        $publishDates = array_values(array_unique($publishDates));
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

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt(array $companies, array $reportAnalyses, array $bulletinAnalyses): string {
        $reportsBlock = '';
        foreach ($reportAnalyses as $entry) {
            $report = $entry['report'];
            $analysis = $entry['analysis']['analysis'] ?? [];
            $company = $companies[$report['company_id']] ?? [];

            $reportsBlock .= sprintf(
                "\n[%s - %s] %s publié le %s\n" .
                "Résumé: %s\n" .
                "Contexte marché au moment de l'analyse: %s\n",
                $company['symbol'] ?? '?',
                $company['name'] ?? '?',
                $report['report_type'] ?? 'rapport',
                $report['publish_date'] ?? '?',
                $analysis['executive_summary'] ?? 'n/a',
                $analysis['market_context_note'] ?? 'n/a'
            );
        }

        $bulletinsBlock = '';
        foreach ($bulletinAnalyses as $entry) {
            $bulletin = $entry['bulletin'];
            $analysis = $entry['analysis']['analysis'] ?? [];
            $sentiment = $analysis['sentiment'] ?? [];

            $bulletinsBlock .= sprintf(
                "\n[%s] Sentiment: %s\nRésumé de séance: %s\nMouvements notables: %s\n",
                $bulletin['publish_date'],
                $sentiment['verdict'] ?? 'n/a',
                $analysis['session_summary'] ?? 'n/a',
                $analysis['notable_movements'] ?? 'n/a'
            );
        }

        return <<<PROMPT
Tu es un analyste financier senior spécialisé sur les marchés actions
d'Afrique de l'Ouest (BRVM), chargé d'une analyse COMBINÉE croisant des
rapports financiers de société(s) cotée(s) ET des bulletins officiels de
marché (BOC) sélectionnés par un investisseur. L'objectif : situer la
performance et les fondamentaux de chaque société dans le contexte du marché
au moment considéré — est-ce que les mouvements de cours d'une société
s'expliquent par ses propres fondamentaux, par une tendance de marché
générale, ou par une combinaison des deux ? Cette analyse doit vraiment
aider une décision d'investissement, pas se contenter de juxtaposer les
informations.

Rapports d'entreprise(s) inclus :
$reportsBlock

Bulletins de marché inclus (triés par date) :
$bulletinsBlock

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "combined_overview": "synthèse dense de l'ensemble en 4-8 phrases",
  "company_performance_notes": [
    {"company_symbol": "...", "company_name": "...", "narrative": "performance et fondamentaux de cette société d'après son/ses rapport(s)"}
  ],
  "market_context_summary": "synthèse du contexte de marché sur la période couverte par les bulletins",
  "correlation_analysis": "la performance de chaque société suit-elle, précède-t-elle, ou diverge-t-elle de la tendance de marché générale, et pourquoi (base-toi sur les dates et les chiffres fournis)",
  "timeline_narrative": "récit chronologique croisant les événements marquants des rapports et des bulletins dans l'ordre",
  "decision_support_notes": [
    {"company_symbol": "...", "bull_case": "arguments factuels positifs tenant compte du contexte de marché", "bear_case": "arguments factuels prudents tenant compte du contexte de marché", "key_watch_points": ["..."]}
  ],
  "key_takeaways": ["point clé 1 orienté aide à la décision", "point clé 2"],
  "glossary": [{"term": "terme technique utilisé ci-dessus", "explanation": "explication en une phrase simple"}]
}

Règles impératives :
- N'invente aucun chiffre ou fait : base-toi uniquement sur les données fournies ci-dessus.
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite.
- Réponds uniquement avec le JSON.
PROMPT;
    }

    private function responseSchema(): array {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['combined_overview', 'company_performance_notes', 'market_context_summary', 'correlation_analysis', 'timeline_narrative', 'decision_support_notes', 'key_takeaways', 'glossary'],
            'properties' => [
                'combined_overview' => ['type' => 'string'],
                'company_performance_notes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['company_symbol', 'company_name', 'narrative'],
                        'properties' => [
                            'company_symbol' => ['type' => 'string'],
                            'company_name' => ['type' => 'string'],
                            'narrative' => ['type' => 'string'],
                        ],
                    ],
                ],
                'market_context_summary' => ['type' => 'string'],
                'correlation_analysis' => ['type' => 'string'],
                'timeline_narrative' => ['type' => 'string'],
                'decision_support_notes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['company_symbol', 'bull_case', 'bear_case', 'key_watch_points'],
                        'properties' => [
                            'company_symbol' => ['type' => 'string'],
                            'bull_case' => ['type' => 'string'],
                            'bear_case' => ['type' => 'string'],
                            'key_watch_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
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
            'id' => (int) $row['id'],
            'report_ids' => json_decode($row['report_ids'], true),
            'bulletin_ids' => json_decode($row['bulletin_ids'], true),
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'rating' => isset($row['rating']) ? (int) $row['rating'] : null,
            'notes' => $row['notes'] ?? null,
            'analysis' => $row['status'] === 'success' ? array_merge(
                ['combined_overview' => $row['summary']],
                array_diff_key($details, ['companies' => null, 'reports' => null, 'bulletins' => null, 'chart_data' => null, 'skipped_reports' => null, 'skipped_bulletins' => null])
            ) : null,
            'companies' => $details['companies'] ?? [],
            'reports' => $details['reports'] ?? [],
            'bulletins' => $details['bulletins'] ?? [],
            'chart_data' => $details['chart_data'] ?? null,
            'skipped_reports' => $details['skipped_reports'] ?? [],
            'skipped_bulletins' => $details['skipped_bulletins'] ?? [],
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
