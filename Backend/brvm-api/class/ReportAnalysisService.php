<?php
/**
 * Orchestration de l'analyse IA d'un rapport de société : construit le
 * prompt (texte du rapport + contexte marché récent), l'envoie au fournisseur
 * IA demandé (Anthropic par défaut, ou un autre via $provider), et met en
 * cache le résultat dans company_report_analyses (un appel par jour et par
 * couple fournisseur+modèle, pas plus).
 */
class ReportAnalysisService {
    private const MAX_REPORT_CHARS = 500000;
    private const PRICE_HISTORY_DAYS = 180;
    private const MAX_ADDITIONAL_DOCUMENTS = 3;
    private const MAX_ADDITIONAL_DOCUMENT_CHARS = 15000;
    private const DISCLAIMER = "Analyse générée automatiquement à titre informatif, "
        . "ne constitue pas un conseil en investissement.";

    /**
     * Registre des fournisseurs IA supportés. Pour ajouter un fournisseur
     * (OpenAI, Grok, Kimi...) : créer une classe implémentant
     * AiClientInterface (voir GeminiClient/AnthropicClient) et l'ajouter ici.
     */
    private const PROVIDERS = [
        'anthropic' => ['class' => 'AnthropicClient', 'default_model' => 'claude-opus-5'],
        'gemini' => ['class' => 'GeminiClient', 'default_model' => 'gemini-flash-lite-latest'],
        'grok' => ['class' => 'GrokClient', 'default_model' => 'grok-4-fast-reasoning'],
    ];
    private const DEFAULT_PROVIDER = 'gemini';

    /**
     * Clés de la réponse IA à conserver dans company_report_analyses.details.
     * IMPORTANT : toute clé ajoutée au schéma JSON demandé dans buildPrompt()
     * doit être ajoutée ici, sinon elle sera renvoyée par l'IA puis perdue
     * silencieusement au moment de la mise en cache.
     */
    private const DETAIL_FIELDS = [
        'company_overview',
        'key_financials',
        'financial_analysis',
        'growth_trends',
        'cash_flow_analysis',
        'swot',
        'risks',
        'governance_and_audit',
        'outlook_guidance',
        'market_context_note',
        'technical_reading',
        'valuation_assessment',
        'investment_thesis',
        'data_quality_note',
        'glossary',
    ];

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * @return array Résultat structuré prêt à être renvoyé par l'API
     */
    public function analyze(int $reportId, ?string $provider = null, ?string $model = null, bool $forceRefresh = false): array {
        $provider = $provider ?: self::DEFAULT_PROVIDER;
        if (!isset(self::PROVIDERS[$provider])) {
            throw new Exception("Fournisseur IA inconnu: $provider. Disponibles: " . implode(', ', array_keys(self::PROVIDERS)));
        }
        $model = $model ?: self::PROVIDERS[$provider]['default_model'];

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé (id=$reportId)");
        }

        $content = $this->crud->find('company_report_contents', ['report_id' => $reportId]);
        $contentRow = $content[0] ?? null;

        // Préfère le markdown restructuré (tableaux propres, voir
        // ReportMarkdownFormatterService) s'il est disponible : matière
        // première plus fiable pour l'IA que le dump pdftotext brut, qui
        // reste le repli sinon.
        $usingMarkdown = !empty($contentRow['formatted_markdown']) && $contentRow['markdown_status'] === 'success';
        $sourceText = $usingMarkdown ? $contentRow['formatted_markdown'] : ($contentRow['extracted_text'] ?? null);

        if (empty($sourceText)) {
            throw new Exception(
                "Le texte de ce rapport n'a pas encore été extrait. " .
                "Lance 'php scripts/backfill_reports.php' avant de l'analyser."
            );
        }

        $company = $this->crud->findById('companies', $report['company_id']);
        $marketContextDate = $this->getLatestTradingDate($report['company_id']);

        $existing = $this->crud->executeCustomQuery(
            "SELECT * FROM company_report_analyses WHERE report_id = ? AND provider = ? AND model = ? AND market_context_date <=> ? LIMIT 1",
            [$reportId, $provider, $model, $marketContextDate]
        );
        $existingRow = $existing[0] ?? null;

        if ($existingRow && $existingRow['status'] === 'success' && !$forceRefresh) {
            return $this->formatResult($existingRow, $report, $company, true);
        }

        $marketContext = $this->getMarketContext($report['company_id'], $marketContextDate);
        $additionalDocsContext = $this->buildAdditionalDocumentsContext($report['company_id']);
        $truncatedText = $this->truncate($sourceText);

        $prompt = $this->buildPrompt($company, $report, $marketContext, $truncatedText, $additionalDocsContext);
        $client = $this->createClient($provider);
        $aiResult = $client->generateContent($prompt, $model);

        $row = [
            'report_id' => $reportId,
            'company_id' => $report['company_id'],
            'provider' => $provider,
            'model' => $model,
            'market_context_date' => $marketContextDate,
            'input_char_count' => mb_strlen($truncatedText),
        ];

        if ($aiResult['success']) {
            $data = $aiResult['data'];
            $row['summary'] = $data['executive_summary'] ?? null;

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
            $this->crud->merge('company_report_analyses', $row, ['id' => $existingRow['id']]);
            $rowId = $existingRow['id'];
        } else {
            $rowId = $this->crud->persist('company_report_analyses', $row);
        }

        if (!$aiResult['success']) {
            throw new Exception($aiResult['error']);
        }

        // Relu depuis la base pour récupérer created_at/updated_at générés par MySQL
        $savedRow = $this->crud->findById('company_report_analyses', $rowId);

        return $this->formatResult($savedRow, $report, $company, false);
    }

    /** Champs autorisés dans key_financials pour une saisie manuelle — mêmes clés que le schéma IA (buildPrompt()), sauf les champs texte narratifs qui n'ont pas leur place dans une saisie de chiffres. */
    private const MANUAL_KEY_FINANCIALS_FIELDS = [
        'currency', 'period_end_date', 'revenue', 'revenue_prior_year', 'revenue_growth_percent',
        'gross_profit', 'gross_margin_percent', 'operating_income', 'operating_margin_percent',
        'ebitda', 'ebitda_margin_percent', 'net_income', 'net_income_prior_year', 'net_margin_percent',
        'operating_cash_flow', 'capex', 'free_cash_flow', 'total_debt', 'total_equity', 'total_assets',
        'debt_to_equity', 'interest_expense', 'interest_coverage_ratio', 'debt_to_ebitda',
        'current_assets', 'current_liabilities', 'current_ratio', 'quick_ratio', 'working_capital',
        'cash_position', 'receivable_days', 'payable_days', 'inventory_days', 'dividend_per_share',
        'roe_percent', 'roa_percent',
    ];

    /** Champs autorisés dans valuation_assessment pour une saisie manuelle — voir MANUAL_KEY_FINANCIALS_FIELDS ci-dessus. */
    private const MANUAL_VALUATION_ASSESSMENT_FIELDS = [
        'shares_outstanding', 'eps', 'book_value_per_share', 'pe_ratio', 'price_to_book',
        'ev_to_ebitda', 'dividend_yield_percent', 'payout_ratio_percent', 'free_float_percent',
        'verdict', 'rationale',
    ];

    /**
     * Enregistre (ou met à jour) une saisie MANUELLE des données financières
     * d'un rapport — soit pour corriger des chiffres extraits par IA (les
     * champs narratifs de l'analyse IA d'origine, elle, restent consultables
     * séparément via history()/getLatest() : cette saisie n'écrase jamais une
     * analyse IA existante, elle s'ajoute comme sa propre entrée), soit pour
     * saisir des chiffres pour un rapport jamais analysé par IA.
     *
     * $reportId nul/0 : aucun rapport existant à corriger — un rapport
     * SYNTHÉTIQUE est créé à la volée (report_type='manuel', aucun PDF), qui
     * sert uniquement d'ancrage pour que cette saisie se comporte à
     * l'identique d'une analyse issue d'un vrai rapport partout ailleurs
     * dans l'app (graphes de croissance, page Fondamentaux, filtres). Exige
     * alors $companyId ; $reportTitle sert de titre, sinon un titre par
     * défaut est dérivé de la date de clôture saisie. Ces rapports
     * synthétiques n'apparaissent pas dans la page Rapports (voir
     * api_reports.php::listReports(), filtre report_type != 'manuel').
     *
     * Stockée avec provider='manuel'/model='manuel' — mêmes conventions que
     * les analyses IA (voir dedupeByReportId() côté api_fundamentals.php),
     * ce qui la rend automatiquement sélectionnable dans le filtre "IA" du
     * frontend et prioritaire par défaut dès lors qu'elle est plus récente
     * que la dernière analyse IA (le principe "dernière analyse gagne"
     * s'applique aussi à une correction manuelle : c'est le but).
     *
     * Une seule entrée manuelle par rapport (pas une par date de marché
     * comme les analyses IA) : une correction manuelle ne se périme pas
     * quand le cours du jour change, donc une nouvelle sauvegarde met à jour
     * la précédente plutôt que d'en empiler une nouvelle à chaque fois.
     */
    public function saveManualFinancials(
        ?int $reportId,
        array $keyFinancials,
        array $valuationAssessment,
        ?int $companyId = null,
        ?string $reportTitle = null
    ): array {
        if ($reportId) {
            $report = $this->crud->findById('company_reports', $reportId);
            if (!$report) {
                throw new Exception("Rapport non trouvé (id=$reportId)");
            }
        } else {
            if (!$companyId) {
                throw new Exception("company_id requis pour créer une nouvelle saisie manuelle");
            }
            $company = $this->crud->findById('companies', $companyId);
            if (!$company) {
                throw new Exception("Entreprise non trouvée (id=$companyId)");
            }
            $periodEndDate = $keyFinancials['period_end_date'] ?? null;
            $title = $reportTitle ?: ('Saisie manuelle' . ($periodEndDate ? " — exercice clos le $periodEndDate" : ''));
            $newReportId = $this->crud->persist('company_reports', [
                'company_id' => $companyId,
                'report_type' => 'manuel',
                'title' => $title,
                'publish_date' => $periodEndDate,
                'file_url' => '',
                'text_extracted' => 0,
            ]);
            $report = $this->crud->findById('company_reports', $newReportId);
            $reportId = (int) $newReportId;
        }
        $company = $this->crud->findById('companies', $report['company_id']);

        $filteredKeyFinancials = array_intersect_key($keyFinancials, array_flip(self::MANUAL_KEY_FINANCIALS_FIELDS));
        $filteredValuation = array_intersect_key($valuationAssessment, array_flip(self::MANUAL_VALUATION_ASSESSMENT_FIELDS));

        $details = array_fill_keys(self::DETAIL_FIELDS, null);
        $details['key_financials'] = $filteredKeyFinancials;
        $details['valuation_assessment'] = $filteredValuation;
        $details['data_quality_note'] = "Chiffres saisis manuellement — pas une extraction IA.";

        $marketContextDate = $this->getLatestTradingDate((int) $report['company_id']);

        $row = [
            'report_id' => $reportId,
            'company_id' => $report['company_id'],
            'provider' => 'manuel',
            'model' => 'manuel',
            'market_context_date' => $marketContextDate,
            'summary' => 'Données financières saisies manuellement.',
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'status' => 'success',
            'error_message' => null,
            'raw_response' => null,
        ];

        $existing = $this->crud->executeCustomQuery(
            "SELECT id FROM company_report_analyses WHERE report_id = ? AND provider = 'manuel' AND model = 'manuel' LIMIT 1",
            [$reportId]
        );

        if (!empty($existing)) {
            $this->crud->merge('company_report_analyses', $row, ['id' => $existing[0]['id']]);
            $rowId = $existing[0]['id'];
        } else {
            $rowId = $this->crud->persist('company_report_analyses', $row);
        }

        $savedRow = $this->crud->findById('company_report_analyses', $rowId);

        return $this->formatResult($savedRow, $report, $company, false);
    }

    /**
     * Dernière analyse en cache pour un rapport, sans jamais appeler de fournisseur IA.
     */
    public function getLatest(int $reportId, ?string $provider = null, ?string $model = null): ?array {
        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé (id=$reportId)");
        }
        $company = $this->crud->findById('companies', $report['company_id']);

        $conditions = ['report_id = ?'];
        $params = [$reportId];
        if ($provider) {
            $conditions[] = 'provider = ?';
            $params[] = $provider;
        }
        if ($model) {
            $conditions[] = 'model = ?';
            $params[] = $model;
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM company_report_analyses WHERE " . implode(' AND ', $conditions) .
            " ORDER BY market_context_date DESC, id DESC LIMIT 1",
            $params
        );

        if (empty($rows)) {
            return null;
        }

        return $this->formatResult($rows[0], $report, $company, true);
    }

    /**
     * Historique des analyses d'un rapport (ou de tous les rapports d'une société).
     */
    public function history(?int $reportId, ?int $companyId): array {
        if ($reportId) {
            $rows = $this->crud->find('company_report_analyses', ['report_id' => $reportId], PDO::FETCH_ASSOC, true, 'market_context_date DESC, id DESC');
        } elseif ($companyId) {
            $rows = $this->crud->find('company_report_analyses', ['company_id' => $companyId], PDO::FETCH_ASSOC, true, 'market_context_date DESC, id DESC');
        } else {
            throw new Exception("report_id ou company_id requis");
        }

        $reportsById = [];
        $companiesById = [];

        return array_map(function ($row) use (&$reportsById, &$companiesById) {
            $rId = $row['report_id'];
            if (!isset($reportsById[$rId])) {
                $reportsById[$rId] = $this->crud->findById('company_reports', $rId);
            }
            $report = $reportsById[$rId];

            $cId = $report['company_id'] ?? null;
            if ($cId !== null && !isset($companiesById[$cId])) {
                $companiesById[$cId] = $this->crud->findById('companies', $cId);
            }
            $company = $cId !== null ? ($companiesById[$cId] ?? null) : null;

            return $this->formatResult($row, $report, $company, true);
        }, $rows);
    }

    /**
     * Statistiques agrégées des analyses IA déjà réalisées pour UNE
     * entreprise — pense pour alimenter des graphes (répartition des
     * verdicts de valorisation, fréquence des catégories de risque,
     * tendance CA/résultat net) plutôt qu'une lecture rapport par rapport.
     * Recalculé à la demande à chaque appel (pas de cache dédié) : reflète
     * toujours l'état actuel de company_report_analyses (et de
     * company_document_analyses si $includeDocuments), donc tout nouveau
     * rapport/document analysé apparaît automatiquement au prochain appel
     * sans modification de code.
     *
     * Un rapport (ou document) peut avoir plusieurs lignes dans sa table
     * d'analyses (ré-analyses, fournisseurs différents, jours différents à
     * cause du cache quotidien) — seule la plus récente analyse réussie par
     * rapport/document est prise en compte, pour ne pas compter deux fois
     * le même élément dans les répartitions.
     *
     * $includeDocuments (défaut false, rétro-compatible) : si true, les
     * documents complémentaires déjà analysés (voir
     * CompanyDocumentAnalysisService, même schéma d'analyse) sont fusionnés
     * dans les mêmes répartitions que les rapports officiels, distingués par
     * 'source_type' dans financial_trend — les documents complémentaires
     * n'ont pas de date de publication propre (contrairement aux rapports
     * scrapés depuis brvm.org), 'publish_date' utilise alors leur date
     * d'ajout (uploaded_at) comme repère chronologique approximatif.
     */
    public function getCompanyAnalysisStats(int $companyId, bool $includeDocuments = false): array {
        $totalReports = (int) ($this->crud->executeCustomQuery(
            "SELECT COUNT(*) AS c FROM company_reports WHERE company_id = ?",
            [$companyId]
        )[0]['c'] ?? 0);

        $reportRows = $this->crud->executeCustomQuery(
            "SELECT cra.*, cr.publish_date, cr.title
             FROM company_report_analyses cra
             INNER JOIN company_reports cr ON cr.id = cra.report_id
             INNER JOIN (
                 SELECT report_id, MAX(id) AS max_id
                 FROM company_report_analyses
                 WHERE company_id = ? AND status = 'success'
                 GROUP BY report_id
             ) latest ON latest.max_id = cra.id",
            [$companyId]
        ) ?: [];

        $items = array_map(fn($row) => [
            'source_type' => 'report',
            'source_id' => (int) $row['report_id'],
            'source_title' => $row['title'],
            'publish_date' => $row['publish_date'],
            'details' => json_decode($row['details'] ?? 'null', true) ?: [],
        ], $reportRows);

        $totalDocuments = 0;
        $analyzedDocuments = 0;
        if ($includeDocuments) {
            $totalDocuments = (int) ($this->crud->executeCustomQuery(
                "SELECT COUNT(*) AS c FROM company_documents WHERE company_id = ?",
                [$companyId]
            )[0]['c'] ?? 0);

            $documentRows = $this->crud->executeCustomQuery(
                "SELECT cda.*, cd.title, cd.uploaded_at
                 FROM company_document_analyses cda
                 INNER JOIN company_documents cd ON cd.id = cda.document_id
                 INNER JOIN (
                     SELECT document_id, MAX(id) AS max_id
                     FROM company_document_analyses
                     WHERE company_id = ? AND status = 'success'
                     GROUP BY document_id
                 ) latest ON latest.max_id = cda.id",
                [$companyId]
            ) ?: [];

            $analyzedDocuments = count($documentRows);
            foreach ($documentRows as $row) {
                $items[] = [
                    'source_type' => 'document',
                    'source_id' => (int) $row['document_id'],
                    'source_title' => $row['title'],
                    'publish_date' => $row['uploaded_at'] ? substr($row['uploaded_at'], 0, 10) : null,
                    'details' => json_decode($row['details'] ?? 'null', true) ?: [],
                ];
            }
        }

        usort($items, fn($a, $b) => ($a['publish_date'] ?? '') <=> ($b['publish_date'] ?? ''));

        $verdictCounts = [];
        $riskCategoryCounts = [];
        $financialTrend = [];

        foreach ($items as $item) {
            $details = $item['details'];

            $verdict = $details['valuation_assessment']['verdict'] ?? null;
            $verdictKey = $verdict ?: 'indéterminable';
            $verdictCounts[$verdictKey] = ($verdictCounts[$verdictKey] ?? 0) + 1;

            foreach ($details['risks'] ?? [] as $risk) {
                $category = trim((string) ($risk['category'] ?? ''));
                if ($category === '') {
                    continue;
                }
                $normalized = mb_strtolower($category);
                if (!isset($riskCategoryCounts[$normalized])) {
                    $riskCategoryCounts[$normalized] = ['label' => ucfirst($category), 'count' => 0];
                }
                $riskCategoryCounts[$normalized]['count']++;
            }

            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];
            $financialTrend[] = [
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'source_title' => $item['source_title'],
                'publish_date' => $item['publish_date'],
                'revenue' => isset($financials['revenue']) ? (float) $financials['revenue'] : null,
                'net_income' => isset($financials['net_income']) ? (float) $financials['net_income'] : null,
                'net_margin_percent' => isset($financials['net_margin_percent']) ? (float) $financials['net_margin_percent'] : null,
                'roe_percent' => isset($financials['roe_percent']) ? (float) $financials['roe_percent'] : null,
                'pe_ratio' => isset($valuation['pe_ratio']) ? (float) $valuation['pe_ratio'] : null,
                'verdict' => $verdict,
            ];
        }

        $analyzedReports = count($reportRows);

        $verdictDistribution = [];
        foreach ($verdictCounts as $verdict => $count) {
            $verdictDistribution[] = ['verdict' => $verdict, 'count' => $count];
        }

        $riskCategoryDistribution = array_values($riskCategoryCounts);
        usort($riskCategoryDistribution, fn($a, $b) => $b['count'] <=> $a['count']);
        $riskCategoryDistribution = array_map(
            fn($r) => ['category' => $r['label'], 'count' => $r['count']],
            $riskCategoryDistribution
        );

        return [
            'company_id' => $companyId,
            'documents_included' => $includeDocuments,
            'total_reports' => $totalReports,
            'analyzed_reports' => $analyzedReports,
            'pending_reports' => max(0, $totalReports - $analyzedReports),
            'total_documents' => $totalDocuments,
            'analyzed_documents' => $analyzedDocuments,
            'pending_documents' => max(0, $totalDocuments - $analyzedDocuments),
            'verdict_distribution' => $verdictDistribution,
            'risk_category_distribution' => $riskCategoryDistribution,
            'financial_trend' => $financialTrend,
        ];
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse déjà
     * enregistrée — voir ChartAnalysisService::rate() pour le même pattern
     * (rating/notes ne sont modifiés que s'ils sont explicitement fournis).
     */
    public function rate(int $id, ?int $rating, ?string $notes, bool $ratingProvided, bool $notesProvided): array {
        if ($ratingProvided && $rating !== null && ($rating < 1 || $rating > 5)) {
            throw new Exception("La note doit être comprise entre 1 et 5");
        }

        $row = $this->crud->findById('company_report_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse non trouvée (id=$id)");
        }

        $update = [];
        if ($ratingProvided) $update['rating'] = $rating;
        if ($notesProvided) $update['notes'] = $notes;

        if (!empty($update)) {
            $this->crud->merge('company_report_analyses', $update, ['id' => $id]);
        }

        $updatedRow = $this->crud->findById('company_report_analyses', $id);
        $report = $this->crud->findById('company_reports', $updatedRow['report_id']);
        $company = $this->crud->findById('companies', $report['company_id']);
        return $this->formatResult($updatedRow, $report, $company, true);
    }

    /**
     * Supprime une analyse enregistrée. Si c'était la dernière analyse
     * rattachée à un rapport SYNTHÉTIQUE (report_type='manuel', créé à la
     * volée par saveManualFinancials() faute de rapport existant), supprime
     * aussi ce rapport — sinon une coquille vide sans aucune analyse
     * resterait indéfiniment en base sans utilité ni moyen de la retrouver.
     * Un vrai rapport (PDF importé) n'est, lui, jamais supprimé ici.
     */
    public function remove(int $id): void {
        $row = $this->crud->findById('company_report_analyses', $id);
        if (!$row) {
            throw new Exception("Analyse non trouvée (id=$id)");
        }
        $reportId = (int) $row['report_id'];
        $this->crud->remove('company_report_analyses', ['id' => $id]);

        $report = $this->crud->findById('company_reports', $reportId);
        if ($report && $report['report_type'] === 'manuel') {
            $remaining = $this->crud->executeCustomQuery(
                "SELECT COUNT(*) AS c FROM company_report_analyses WHERE report_id = ?",
                [$reportId]
            );
            if ((int) ($remaining[0]['c'] ?? 0) === 0) {
                $this->crud->remove('company_reports', ['id' => $reportId]);
            }
        }
    }

    private function createClient(string $provider): AiClientInterface {
        $class = self::PROVIDERS[$provider]['class'];
        return new $class();
    }

    private function getLatestTradingDate(int $companyId): ?string {
        $result = $this->crud->executeCustomQuery(
            "SELECT MAX(trading_date) AS d FROM stock_quotes WHERE company_id = ?",
            [$companyId]
        );
        return $result[0]['d'] ?? null;
    }

    private function getMarketContext(int $companyId, ?string $tradingDate): array {
        if (!$tradingDate) {
            return [];
        }

        $quote = $this->crud->find('stock_quotes', ['company_id' => $companyId, 'trading_date' => $tradingDate]);
        $indicators = $this->crud->find('technical_indicators', ['company_id' => $companyId, 'trading_date' => $tradingDate]);

        return [
            'trading_date' => $tradingDate,
            'quote' => $quote[0] ?? null,
            'indicators' => $indicators[0] ?? null,
        ];
    }

    /**
     * Historique de prix récent (même forme que api_quotes.php::getOHLCData)
     * pour permettre au frontend de tracer un graphe à côté de l'analyse,
     * sans appel IA ni impact sur le cache.
     */
    private function getPriceHistory(int $companyId): array {
        return $this->crud->executeCustomQuery(
            "SELECT trading_date AS date, open_price AS open, high_price AS high,
                    low_price AS low, close_price AS close, volume
             FROM stock_quotes
             WHERE company_id = ? AND trading_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             ORDER BY trading_date ASC",
            [$companyId, self::PRICE_HISTORY_DAYS]
        ) ?: [];
    }

    private function truncate(string $text): string {
        if (mb_strlen($text) <= self::MAX_REPORT_CHARS) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_REPORT_CHARS) . "\n\n[...texte tronqué...]";
    }

    /**
     * Documents complémentaires ajoutés manuellement pour cette entreprise
     * (voir api_company_documents.php) — rapports détaillés publiés sur le
     * site de l'entreprise mais absents/résumés dans le rapport officiel
     * analysé ici, présentations investisseurs, etc. Toujours inclus quand
     * disponibles (pas d'option à cocher, contrairement à
     * ChartAnalysisService::buildReportContext() : ici il n'y a qu'une seule
     * entreprise concernée, pas de coût de prompt à arbitrer entre
     * plusieurs).
     */
    private function buildAdditionalDocumentsContext(int $companyId): string {
        $documents = $this->crud->executeCustomQuery(
            "SELECT id, title FROM company_documents
             WHERE company_id = ? AND text_extracted = 1
             ORDER BY uploaded_at DESC LIMIT ?",
            [$companyId, self::MAX_ADDITIONAL_DOCUMENTS]
        ) ?: [];

        $blocks = [];
        foreach ($documents as $document) {
            $contents = $this->crud->find('company_document_contents', ['document_id' => $document['id']]);
            $content = $contents[0] ?? null;
            $usingMarkdown = !empty($content['formatted_markdown']) && ($content['markdown_status'] ?? null) === 'success';
            $text = $usingMarkdown ? $content['formatted_markdown'] : ($content['extracted_text'] ?? null);

            if (empty($text)) {
                continue;
            }

            if (mb_strlen($text) > self::MAX_ADDITIONAL_DOCUMENT_CHARS) {
                $text = mb_substr($text, 0, self::MAX_ADDITIONAL_DOCUMENT_CHARS) . "\n\n[...texte tronqué...]";
            }
            $sourceNote = $usingMarkdown ? '' : ' (texte brut extrait, pas encore reformaté en Markdown)';
            $blocks[] = "#### {$document['title']}$sourceNote\n\n$text";
        }

        return implode("\n\n", $blocks);
    }

    private function buildPrompt($company, $report, array $marketContext, string $reportText, string $additionalDocsContext = ''): string {
        $companyLabel = ($company['symbol'] ?? '?') . ' - ' . ($company['name'] ?? '?');
        $reportLabel = ($report['report_type'] ?? 'rapport') . ' publié le ' . ($report['publish_date'] ?? 'date inconnue');

        $marketBlock = "Aucune donnée de marché récente disponible.";
        if (!empty($marketContext['quote'])) {
            $q = $marketContext['quote'];
            $i = $marketContext['indicators'] ?? [];
            $marketBlock = sprintf(
                "Cours du %s : ouverture %s / clôture %s FCFA (plus haut %s, plus bas %s). " .
                "Variation %s%% par rapport à la clôture de la séance précédente (%s FCFA) — PAS par rapport à " .
                "l'ouverture du jour même, ne pas les comparer comme si elles étaient sur la même base. " .
                "Volume %s, valeur échangée %s FCFA. " .
                "Indicateurs techniques : RSI(14) %s, SMA20 %s, SMA50 %s, SMA200 %s, EMA10 %s, EMA20 %s, " .
                "MACD %s (signal %s), Bandes de Bollinger [%s ; %s ; %s], ATR(14) %s.",
                $marketContext['trading_date'],
                $q['open_price'] ?? '?',
                $q['close_price'] ?? '?',
                $q['high_price'] ?? '?',
                $q['low_price'] ?? '?',
                $q['variation_percent'] ?? '?',
                $q['previous_close'] ?? '?',
                $q['volume'] ?? '?',
                $q['turnover'] ?? '?',
                $i['rsi_14'] ?? 'n/a',
                $i['sma_20'] ?? 'n/a',
                $i['sma_50'] ?? 'n/a',
                $i['sma_200'] ?? 'n/a',
                $i['ema_10'] ?? 'n/a',
                $i['ema_20'] ?? 'n/a',
                $i['macd_line'] ?? 'n/a',
                $i['macd_signal'] ?? 'n/a',
                $i['bb_lower'] ?? 'n/a',
                $i['bb_middle'] ?? 'n/a',
                $i['bb_upper'] ?? 'n/a',
                $i['atr_14'] ?? 'n/a'
            );
        }

        $additionalDocsBlock = '';
        if (!empty($additionalDocsContext)) {
            $additionalDocsBlock = "\nDocuments complémentaires disponibles pour cette entreprise (ajoutés manuellement, "
                . "souvent plus détaillés que le rapport officiel ci-dessus — croise-les avec le rapport analysé, "
                . "complète les champs (ex: shares_outstanding, chiffres manquants) s'ils y figurent explicitement, "
                . "sans jamais inventer une donnée absente des deux sources) :\n$additionalDocsContext\n";
        }

        return <<<PROMPT
Tu es un analyste financier senior et data analyst spécialisé sur les marchés
actions d'Afrique de l'Ouest (BRVM), avec une double expertise en lecture des
états financiers (normes SYSCOHADA/IFRS) et en analyse technique boursière.
Un client exigeant t'a confié la rédaction d'une note d'analyse approfondie,
du niveau d'une note de recherche actions professionnelle : précise,
quantitative, exhaustive, qui va au-delà d'un simple résumé. N'omets aucune
donnée chiffrée pertinente présente dans le texte, et calcule toi-même les
ratios/variations qui en découlent (croissance N/N-1, marges, ratios de
liquidité, de solvabilité, d'efficacité opérationnelle, etc.) plutôt que de
te contenter de les recopier s'ils y sont, ou de les laisser de côté s'ils
n'y sont pas explicitement mais calculables à partir des données du texte.

Société : $companyLabel
Rapport analysé : $reportLabel

Contexte marché récent (BRVM) :
$marketBlock
$additionalDocsBlock

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "executive_summary": "synthèse dense en 4-8 phrases : ce qu'il faut retenir, chiffres à l'appui",
  "company_overview": "activité, positionnement, actionnariat si mentionnés dans le texte, sinon null",
  "key_financials": {
    "currency": "FCFA",
    "period_end_date": "date de clôture de l'exercice ou de la période couverte par CES CHIFFRES, au format YYYY-MM-DD (ex: 'exercice clos le 31 décembre 2024' -> '2024-12-31', 'premier semestre 2025' -> '2025-06-30', '1er trimestre 2026' -> '2026-03-31') — JAMAIS la date de publication du rapport, toujours la date de fin de la période financière que ces chiffres couvrent. null si le texte ne permet pas de la déterminer avec certitude.",
    "revenue": nombre ou null,
    "revenue_prior_year": nombre ou null,
    "revenue_growth_percent": nombre ou null (calculé si les deux valeurs ci-dessus sont connues),
    "gross_profit": nombre ou null,
    "gross_margin_percent": nombre ou null (gross_profit / revenue),
    "operating_income": nombre ou null,
    "operating_margin_percent": nombre ou null (operating_income / revenue),
    "ebitda": nombre ou null,
    "ebitda_margin_percent": nombre ou null (ebitda / revenue),
    "net_income": nombre ou null,
    "net_income_prior_year": nombre ou null,
    "net_margin_percent": nombre ou null (net_income / revenue),
    "operating_cash_flow": "flux de trésorerie d'exploitation SI mentionné dans le texte, sinon null",
    "capex": "investissements (capex) SI mentionnés dans le texte, sinon null",
    "free_cash_flow": "free cash flow = operating_cash_flow - capex, calculé seulement si les deux sont connus, sinon null",
    "total_debt": "dette financière portant intérêt (emprunts, obligations émises, dettes bancaires) — voir la note 'Banques et établissements financiers' plus bas pour ce que ce champ signifie pour ce secteur, nombre ou null",
    "total_equity": "capitaux propres part du groupe (aussi appelés 'fonds propres' dans certains rapports, notamment bancaires) — cherche ce montant même s'il n'est pas sur la même ligne/page que le résultat net (souvent dans le bilan ou un tableau de variation des capitaux propres), nombre ou null",
    "total_assets": "total du bilan / total actif — nécessaire pour calculer roa_percent ci-dessous, nombre ou null",
    "debt_to_equity": nombre ou null (total_debt / total_equity, calculé si les deux sont connus même si non énoncé explicitement),
    "interest_expense": "charges financières/frais d'intérêt SI mentionnées, sinon null",
    "interest_coverage_ratio": "EBIT ou EBITDA / interest_expense, calculé seulement si les deux sont connus, sinon null",
    "debt_to_ebitda": nombre ou null (total_debt / ebitda),
    "current_assets": "actifs courants/circulants SI le bilan de cette entreprise est classé en courant/non courant, sinon null (une banque ou un établissement financier n'a en général pas cette distinction : ne pas l'inventer)",
    "current_liabilities": "passifs courants/circulants, mêmes règles que current_assets ci-dessus",
    "current_ratio": nombre ou null (current_assets / current_liabilities),
    "quick_ratio": "ratio de liquidité immédiate SI le détail des stocks est connu (current_assets - stocks) / current_liabilities, sinon null",
    "working_capital": nombre ou null (current_assets - current_liabilities),
    "cash_position": "trésorerie / disponibilités / équivalents de trésorerie (peut aussi apparaître comme 'liquidités' ou, pour une banque, 'caisse et banques centrales'), nombre ou null",
    "receivable_days": "délai clients en jours SI calculable (créances / CA * 365), sinon null",
    "payable_days": "délai fournisseurs en jours SI calculable, sinon null",
    "inventory_days": "délai de rotation des stocks en jours SI calculable, sinon null",
    "dividend_per_share": nombre ou null,
    "roe_percent": "rentabilité des capitaux propres = net_income / total_equity * 100, calculé si total_equity est connu même si ce pourcentage n'est pas énoncé tel quel dans le texte, sinon null",
    "roa_percent": "rentabilité des actifs = net_income / total_assets * 100, calculé si total_assets est connu même si non énoncé explicitement, sinon null"
  },
  "financial_analysis": "analyse détaillée en plusieurs phrases des chiffres ci-dessus : tendance N/N-1, rentabilité à chaque étage du compte de résultat (marge brute -> opérationnelle -> nette), structure financière, points marquants",
  "growth_trends": "analyse de la trajectoire pluriannuelle si le rapport fournit un historique sur 3 ans ou plus (CAGR du chiffre d'affaires et du résultat net, régularité ou volatilité de la croissance) ; si seules deux années sont disponibles, le préciser explicitement ; null si aucune série historique n'est présente",
  "cash_flow_analysis": "lecture de la génération de trésorerie : cohérence entre résultat net et flux de trésorerie d'exploitation, niveau des investissements, capacité à autofinancer la croissance et le dividende ; null si le texte ne fournit pas de tableau de flux de trésorerie",
  "swot": {
    "strengths": ["force 1", "force 2"],
    "weaknesses": ["faiblesse 1"],
    "opportunities": ["opportunité 1"],
    "threats": ["menace 1"]
  },
  "risks": [
    {"category": "financier|opérationnel|marché|réglementaire|change", "description": "description précise du risque"}
  ],
  "governance_and_audit": "réserves ou observations du commissaire aux comptes, doute sur la continuité d'exploitation, transactions significatives avec des parties liées, changements de gouvernance mentionnés dans le texte ; null si le texte ne mentionne rien de tel",
  "outlook_guidance": "objectifs, guidance ou perspectives communiqués par la direction pour le prochain exercice, s'ils sont explicitement mentionnés dans le texte ; null sinon",
  "market_context_note": "mise en perspective détaillée : ce rapport confirme-t-il ou contredit-il le cours/les indicateurs techniques récents, et pourquoi",
  "technical_reading": "lecture technique factuelle des indicateurs fournis (tendance, momentum, niveaux), sans recommandation d'achat/vente",
  "valuation_assessment": {
    "shares_outstanding": "nombre d'actions en circulation SI mentionné dans le texte (capital social, actionnariat...), sinon null — ne jamais deviner",
    "eps": "bénéfice net par action = net_income / shares_outstanding, calculé seulement si shares_outstanding est connu, sinon null",
    "book_value_per_share": "capitaux propres / shares_outstanding, calculé si total_equity ET shares_outstanding sont TOUS LES DEUX connus (pas seulement shares_outstanding), sinon null",
    "pe_ratio": "PER = cours de clôture fourni ci-dessus / eps, calculé seulement si eps est connu, sinon null",
    "price_to_book": "cours de clôture / book_value_per_share, calculé seulement si book_value_per_share est connu, sinon null",
    "ev_to_ebitda": "(capitalisation boursière + total_debt - cash_position) / ebitda, calculé seulement si shares_outstanding et ebitda sont connus, sinon null",
    "dividend_yield_percent": "rendement du dividende = dividend_per_share / cours de clôture * 100, si dividend_per_share est connu, sinon null",
    "payout_ratio_percent": "taux de distribution = dividend_per_share * shares_outstanding / net_income * 100, calculé seulement si les trois sont connus, sinon null",
    "free_float_percent": "pourcentage du capital détenu par le public (flottant), c'est-à-dire hors actionnaires de référence/stratégiques (État, groupe fondateur, actionnaire majoritaire, autres sociétés du même groupe...) — UNIQUEMENT si la répartition de l'actionnariat est donnée avec des pourcentages précis dans le texte, sinon null. Ne jamais estimer ou déduire un flottant approximatif.",
    "verdict": "sous-coté | surcoté | correctement valorisé | indéterminable (indéterminable si shares_outstanding est inconnu)",
    "rationale": "justification factuelle du verdict : précise la base de comparaison utilisée (multiples usuels pour ce secteur à la BRVM, évolution récente du titre, niveau du rendement du dividende...). Jamais une recommandation d'achat/vente."
  },
  "investment_thesis": {
    "bull_case": "arguments factuels qui iraient dans le sens d'une vision positive",
    "bear_case": "arguments factuels qui iraient dans le sens d'une vision prudente",
    "key_watch_points": ["point à surveiller 1", "point à surveiller 2"]
  },
  "data_quality_note": "précise ce qui manquait dans le texte pour une analyse complète (ex: pas de tableau de flux de trésorerie, pas de comparatif N-1, pas de nombre d'actions...), ou null si le texte était complet",
  "glossary": [{"term": "terme technique utilisé ci-dessus", "explanation": "explication en une phrase simple"}]
}

Règles impératives :
- N'invente JAMAIS un chiffre absent du texte : mets null plutôt que d'extrapoler. Ceci s'applique en particulier à "shares_outstanding" : ne devine jamais un nombre d'actions non mentionné explicitement.
- Avant de mettre un champ de key_financials à null, vérifie tout le document, pas seulement le compte de résultat principal : les bilans, tableaux de flux de trésorerie, notes annexes, tableaux "chiffres clés"/"faits marquants" en début de rapport et communiqués de résultats contiennent souvent des montants qui n'apparaissent nulle part ailleurs.
- Les libellés varient d'un rapport à l'autre pour la même notion : "capitaux propres" = "fonds propres" ; "trésorerie" = "disponibilités" = "liquidités" (ou, pour une banque, "caisse et banques centrales") ; "résultat d'exploitation" = "résultat opérationnel" ; "EBITDA" = "EBE"/"excédent brut d'exploitation" ; "dette financière" = "emprunts" = "dettes financières". Reconnais ces équivalences plutôt que de renvoyer null faute de correspondance exacte du terme.
- Calcule un ratio dérivé (ex. roe_percent, roa_percent, debt_to_equity) dès que ses composants sont connus, même si le ratio lui-même n'est écrit nulle part dans le texte tel quel — ne le laisse null que si un des composants manque réellement.
- Banques et établissements financiers : leur bilan n'est pas classé en actifs/passifs "courants" — laisse current_assets/current_liabilities/current_ratio/quick_ratio/working_capital à null plutôt que de forcer une distinction absente de leurs états financiers. Pour total_debt, ne compte que la dette financière portant intérêt (emprunts, obligations émises) — jamais les dépôts de la clientèle, qui ne sont pas un endettement au sens de ce ratio.
- Distingue clairement ce qui est extrait du texte de ce que tu calcules à partir de ces chiffres (financial_analysis et cash_flow_analysis doivent expliciter les calculs).
- Reste factuel et neutre : jamais de recommandation d'achat/vente explicite (investment_thesis et valuation_assessment présentent une lecture factuelle, pas une conclusion).
- N'inclus dans "glossary" que les termes techniques que tu as réellement utilisés ailleurs dans la réponse.
- Réponds uniquement avec le JSON.

Texte du rapport :
$reportText
PROMPT;
    }

    private function formatResult(array $row, array $report, ?array $company, bool $cached): array {
        $details = json_decode($row['details'] ?? 'null', true) ?: [];

        return [
            'id' => (int) $row['id'],
            'report' => [
                'id' => $report['id'],
                'title' => $report['title'],
                'report_type' => $report['report_type'],
                'publish_date' => $report['publish_date'],
            ],
            'company' => [
                'id' => $company['id'] ?? null,
                'symbol' => $company['symbol'] ?? null,
                'name' => $company['name'] ?? null,
            ],
            'provider' => $row['provider'],
            'model' => $row['model'],
            'market_context_date' => $row['market_context_date'],
            'status' => $row['status'],
            'error_message' => $row['error_message'] ?? null,
            'rating' => isset($row['rating']) ? (int) $row['rating'] : null,
            'notes' => $row['notes'] ?? null,
            'analysis' => $row['status'] === 'success' ? array_merge(
                ['executive_summary' => $row['summary']],
                $details
            ) : null,
            'chart_data' => [
                'price_history' => $this->getPriceHistory($report['company_id']),
            ],
            'disclaimer' => self::DISCLAIMER,
            'cached' => $cached,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}