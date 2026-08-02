<?php
/**
 * Analyse IA comparative de plusieurs rapports sur une période (une seule
 * entreprise dans le temps, plusieurs entreprises entre elles, ou les deux).
 * Réutilise les analyses individuelles déjà produites par
 * ReportAnalysisService (key_financials, SWOT, risques...) comme matière
 * première plutôt que de renvoyer le texte brut de chaque rapport à l'IA —
 * déclenche l'analyse individuelle d'un rapport si elle manque encore.
 */
class ReportComparisonService {
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
        $this->analysisService = new ReportAnalysisService($crud);
    }

    /**
     * @param int[] $companyIds
     * @return array Résultat structuré prêt à être renvoyé par l'API
     */
    public function compare(array $companyIds, string $startDate, string $endDate, ?string $reportType = null, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        if (empty($companyIds)) {
            throw new Exception("Au moins une entreprise (company_ids ou symbols) requise");
        }

        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        sort($companyIds);

        $reports = $this->findReports($companyIds, $startDate, $endDate, $reportType);
        if (empty($reports)) {
            throw new Exception(
                "Aucun rapport avec texte extrait trouvé pour ces entreprises entre $startDate et $endDate" .
                ($reportType ? " (type: $reportType)" : "")
            );
        }

        $requestHash = hash('sha256', json_encode([$companyIds, $startDate, $endDate, $reportType]));
        $computedDate = date('Y-m-d');

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM company_report_comparisons WHERE request_hash = ? AND provider = ? AND model = ? AND computed_date = ? LIMIT 1",
            [$requestHash, $provider, $model, $computedDate]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, true);
        }

        $companies = $this->getCompaniesById($companyIds);

        // Récupère (ou déclenche) l'analyse individuelle de chaque rapport
        $reportAnalyses = [];
        $skipped = [];
        foreach ($reports as $report) {
            try {
                $reportAnalyses[] = [
                    'report' => $report,
                    'analysis' => $this->analysisService->analyze($report['id'], $provider, $model),
                ];
            } catch (Exception $e) {
                $skipped[] = ['report_id' => $report['id'], 'reason' => $e->getMessage()];
            }
        }

        if (empty($reportAnalyses)) {
            throw new Exception("Aucun rapport n'a pu être analysé (" . count($skipped) . " échec(s))");
        }

        $priceSeries = $this->getPriceSeries($companyIds, $companies, $startDate, $endDate);
        $financialsSeries = $this->buildFinancialsSeries($reportAnalyses, $companies);

        $prompt = $this->buildPrompt($companies, $reportAnalyses, $priceSeries, $startDate, $endDate);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model, $this->responseSchema());

        $row = [
            'request_hash' => $requestHash,
            'company_ids' => json_encode($companyIds),
            'report_ids' => json_encode(array_column(array_column($reportAnalyses, 'report'), 'id')),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'report_type' => $reportType,
            'provider' => $provider,
            'model' => $model,
            'computed_date' => $computedDate,
            'input_char_count' => mb_strlen($prompt),
        ];

        if ($aiResult['success']) {
            $data = $aiResult['data'];
            $row['summary'] = $data['comparative_summary'] ?? null;
            $row['details'] = json_encode([
                'trend_analysis' => $data['trend_analysis'] ?? null,
                'cross_company_ranking' => $data['cross_company_ranking'] ?? null,
                'price_correlation_note' => $data['price_correlation_note'] ?? null,
                'risks_evolution' => $data['risks_evolution'] ?? null,
                'decision_support_notes' => $data['decision_support_notes'] ?? null,
                'companies' => array_values($companies),
                'chart_data' => [
                    'price_series' => $priceSeries,
                    'financials_series' => $financialsSeries,
                ],
                'skipped_reports' => $skipped,
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
            $this->crud->merge('company_report_comparisons', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('company_report_comparisons', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        $savedRow = $this->crud->findById('company_report_comparisons', $rowId);
        return $this->formatResult($savedRow, false);
    }

    /**
     * Dernière comparaison en cache pour ces critères, sans jamais appeler l'IA.
     */
    public function getLatest(array $companyIds, string $startDate, string $endDate, ?string $reportType = null): ?array {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        sort($companyIds);
        $requestHash = hash('sha256', json_encode([$companyIds, $startDate, $endDate, $reportType]));

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM company_report_comparisons WHERE request_hash = ? ORDER BY id DESC LIMIT 1",
            [$requestHash]
        );

        if (empty($rows)) {
            return null;
        }

        return $this->formatResult($rows[0], true);
    }

    private function findReports(array $companyIds, string $startDate, string $endDate, ?string $reportType): array {
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $sql = "SELECT * FROM company_reports
                WHERE company_id IN ($placeholders)
                  AND publish_date BETWEEN ? AND ?
                  AND text_extracted = 1";
        $params = array_merge($companyIds, [$startDate, $endDate]);

        if ($reportType) {
            $sql .= " AND report_type = ?";
            $params[] = $reportType;
        }

        $sql .= " ORDER BY company_id, publish_date ASC";

        return $this->crud->executeCustomQuery($sql, $params) ?: [];
    }

    private function getCompaniesById(array $companyIds): array {
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
     * Historique de prix par entreprise sur la période demandée, même forme
     * que api_quotes.php::getOHLCData — réutilisable tel quel par le frontend.
     */
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
                'company_id' => $companyId,
                'symbol' => $companies[$companyId]['symbol'] ?? null,
                'data' => $data,
            ];
        }
        return $series;
    }

    /**
     * Série des chiffres clés (déjà extraits par l'analyse individuelle de
     * chaque rapport) par entreprise — la matière première d'un graphe de
     * tendance financière (CA/résultat net dans le temps).
     */
    private function buildFinancialsSeries(array $reportAnalyses, array $companies): array {
        $byCompany = [];

        foreach ($reportAnalyses as $entry) {
            $report = $entry['report'];
            $analysis = $entry['analysis'];
            $companyId = $report['company_id'];
            $financials = $analysis['analysis']['key_financials'] ?? null;

            if (!isset($byCompany[$companyId])) {
                $byCompany[$companyId] = [
                    'company_id' => $companyId,
                    'symbol' => $companies[$companyId]['symbol'] ?? null,
                    'data' => [],
                ];
            }

            $byCompany[$companyId]['data'][] = [
                'report_id' => $report['id'],
                'publish_date' => $report['publish_date'],
                'report_type' => $report['report_type'],
                'revenue' => $financials['revenue'] ?? null,
                'net_income' => $financials['net_income'] ?? null,
                'net_margin_percent' => $financials['net_margin_percent'] ?? null,
                'roe_percent' => $financials['roe_percent'] ?? null,
            ];
        }

        return array_values($byCompany);
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function buildPrompt(array $companies, array $reportAnalyses, array $priceSeries, string $startDate, string $endDate): string {
        $companyCount = count($companies);
        $reportsBlock = '';
        foreach ($reportAnalyses as $entry) {
            $report = $entry['report'];
            $analysis = $entry['analysis']['analysis'] ?? [];
            $company = $companies[$report['company_id']] ?? [];
            $financials = $analysis['key_financials'] ?? [];
            $risks = array_map(fn($r) => $r['description'] ?? '', $analysis['risks'] ?? []);
            $strengths = $analysis['swot']['strengths'] ?? [];
            $weaknesses = $analysis['swot']['weaknesses'] ?? [];

            $reportsBlock .= sprintf(
                "\n[%s - %s] %s publié le %s\n" .
                "Résumé: %s\n" .
                "Chiffres clés: CA=%s, croissance CA=%s%%, résultat net=%s, marge nette=%s%%, ROE=%s%%\n" .
                "Forces: %s\n" .
                "Faiblesses: %s\n" .
                "Risques: %s\n",
                $company['symbol'] ?? '?',
                $company['name'] ?? '?',
                $report['report_type'] ?? 'rapport',
                $report['publish_date'] ?? '?',
                $analysis['executive_summary'] ?? 'n/a',
                $financials['revenue'] ?? 'n/a',
                $financials['revenue_growth_percent'] ?? 'n/a',
                $financials['net_income'] ?? 'n/a',
                $financials['net_margin_percent'] ?? 'n/a',
                $financials['roe_percent'] ?? 'n/a',
                implode('; ', $strengths) ?: 'n/a',
                implode('; ', $weaknesses) ?: 'n/a',
                implode('; ', $risks) ?: 'n/a'
            );
        }

        $priceBlock = '';
        foreach ($priceSeries as $serie) {
            $data = $serie['data'];
            if (count($data) < 2) {
                $priceBlock .= sprintf("\n%s : historique de prix insuffisant sur la période.\n", $serie['symbol']);
                continue;
            }
            $first = $data[0];
            $last = $data[count($data) - 1];
            $variation = $first['close'] > 0 ? round((($last['close'] - $first['close']) / $first['close']) * 100, 2) : null;
            $priceBlock .= sprintf(
                "\n%s : cours du %s (%s FCFA) au %s (%s FCFA), variation sur la période: %s%%.\n",
                $serie['symbol'], $first['date'], $first['close'], $last['date'], $last['close'], $variation ?? 'n/a'
            );
        }

        $comparisonAxis = $companyCount > 1
            ? "Compare aussi les entreprises ENTRE ELLES (force financière relative, laquelle est la mieux positionnée et pourquoi), en plus de la tendance de chacune dans le temps."
            : "Une seule entreprise ici : concentre-toi sur sa tendance dans le temps (mets cross_company_ranking à null).";

        return <<<PROMPT
Tu es un analyste financier senior spécialisé sur les marchés actions
d'Afrique de l'Ouest (BRVM), chargé d'une analyse COMPARATIVE (pas un simple
résumé) portant sur plusieurs rapports d'entreprise(s) publiés entre
$startDate et $endDate. Cette analyse doit vraiment aider une décision
d'investissement : identifie les tendances, les points d'inflexion, et
appuie chaque affirmation sur les chiffres fournis ci-dessous (déjà extraits
de chaque rapport individuel — ne les recalcule pas différemment, utilise-les
tels quels). $comparisonAxis

Rapports inclus dans la comparaison :
$reportsBlock

Performance boursière sur la période :
$priceBlock

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "comparative_summary": "synthèse dense de la comparaison en 4-8 phrases",
  "trend_analysis": [
    {"company_symbol": "...", "company_name": "...", "narrative": "évolution CA/marge/résultat net dans le temps, points d'inflexion", "revenue_trend_percent": nombre ou null, "net_income_trend_percent": nombre ou null}
  ],
  "cross_company_ranking": "comparaison factuelle de solidité financière entre entreprises, ou null si une seule entreprise",
  "price_correlation_note": "le cours de chaque entreprise a-t-il suivi la tendance fondamentale sur la période, et pourquoi",
  "risks_evolution": "les risques identifiés dans les rapports successifs augmentent/diminuent/restent stables, et lesquels",
  "decision_support_notes": [
    {"company_symbol": "...", "bull_case": "arguments factuels positifs", "bear_case": "arguments factuels prudents", "key_watch_points": ["..."]}
  ]
}

Règles impératives :
- N'invente aucun chiffre : base-toi uniquement sur les données fournies ci-dessus.
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite.
- Réponds uniquement avec le JSON.
PROMPT;
    }

    private function responseSchema(): array {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'comparative_summary' => ['type' => 'string'],
                'trend_analysis' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'company_symbol' => ['type' => 'string'],
                            'company_name' => ['type' => 'string'],
                            'narrative' => ['type' => 'string'],
                            'revenue_trend_percent' => $nullableNumber,
                            'net_income_trend_percent' => $nullableNumber,
                        ],
                        'required' => ['company_symbol', 'company_name', 'narrative', 'revenue_trend_percent', 'net_income_trend_percent'],
                        'additionalProperties' => false,
                    ],
                ],
                'cross_company_ranking' => $nullableString,
                'price_correlation_note' => ['type' => 'string'],
                'risks_evolution' => ['type' => 'string'],
                'decision_support_notes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'company_symbol' => ['type' => 'string'],
                            'bull_case' => ['type' => 'string'],
                            'bear_case' => ['type' => 'string'],
                            'key_watch_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['company_symbol', 'bull_case', 'bear_case', 'key_watch_points'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['comparative_summary', 'trend_analysis', 'cross_company_ranking', 'price_correlation_note', 'risks_evolution', 'decision_support_notes'],
            'additionalProperties' => false,
        ];
    }

    private function formatResult(array $row, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];

        return [
            'company_ids' => json_decode($row['company_ids'], true),
            'report_ids' => json_decode($row['report_ids'] ?? '[]', true),
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'report_type' => $row['report_type'],
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'analysis' => $row['status'] === 'success' ? array_merge(
                ['comparative_summary' => $row['summary']],
                array_diff_key($details, ['companies' => null, 'chart_data' => null, 'skipped_reports' => null])
            ) : null,
            'companies' => $details['companies'] ?? [],
            'chart_data' => $details['chart_data'] ?? null,
            'skipped_reports' => $details['skipped_reports'] ?? [],
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
