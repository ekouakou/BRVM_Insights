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
        $truncatedText = $this->truncate($sourceText);

        $prompt = $this->buildPrompt($company, $report, $marketContext, $truncatedText);
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

        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'report_id' => $row['report_id'],
                'provider' => $row['provider'],
                'model' => $row['model'],
                'market_context_date' => $row['market_context_date'],
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

    private function buildPrompt($company, $report, array $marketContext, string $reportText): string {
        $companyLabel = ($company['symbol'] ?? '?') . ' - ' . ($company['name'] ?? '?');
        $reportLabel = ($report['report_type'] ?? 'rapport') . ' publié le ' . ($report['publish_date'] ?? 'date inconnue');

        $marketBlock = "Aucune donnée de marché récente disponible.";
        if (!empty($marketContext['quote'])) {
            $q = $marketContext['quote'];
            $i = $marketContext['indicators'] ?? [];
            $marketBlock = sprintf(
                "Cours du %s : ouverture %s / clôture %s FCFA (plus haut %s, plus bas %s), " .
                "variation %s%%, volume %s, valeur échangée %s FCFA. " .
                "Indicateurs techniques : RSI(14) %s, SMA20 %s, SMA50 %s, SMA200 %s, EMA10 %s, EMA20 %s, " .
                "MACD %s (signal %s), Bandes de Bollinger [%s ; %s ; %s], ATR(14) %s.",
                $marketContext['trading_date'],
                $q['open_price'] ?? '?',
                $q['close_price'] ?? '?',
                $q['high_price'] ?? '?',
                $q['low_price'] ?? '?',
                $q['variation_percent'] ?? '?',
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

Réponds UNIQUEMENT avec un objet JSON de cette forme exacte (aucun texte
avant/après, pas de balises markdown) :

{
  "executive_summary": "synthèse dense en 4-8 phrases : ce qu'il faut retenir, chiffres à l'appui",
  "company_overview": "activité, positionnement, actionnariat si mentionnés dans le texte, sinon null",
  "key_financials": {
    "currency": "FCFA",
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
    "total_debt": nombre ou null,
    "total_equity": nombre ou null,
    "debt_to_equity": nombre ou null,
    "interest_expense": "charges financières/frais d'intérêt SI mentionnées, sinon null",
    "interest_coverage_ratio": "EBIT ou EBITDA / interest_expense, calculé seulement si les deux sont connus, sinon null",
    "debt_to_ebitda": nombre ou null (total_debt / ebitda),
    "current_assets": nombre ou null,
    "current_liabilities": nombre ou null,
    "current_ratio": nombre ou null (current_assets / current_liabilities),
    "quick_ratio": "ratio de liquidité immédiate SI le détail des stocks est connu (current_assets - stocks) / current_liabilities, sinon null",
    "working_capital": nombre ou null (current_assets - current_liabilities),
    "cash_position": nombre ou null,
    "receivable_days": "délai clients en jours SI calculable (créances / CA * 365), sinon null",
    "payable_days": "délai fournisseurs en jours SI calculable, sinon null",
    "inventory_days": "délai de rotation des stocks en jours SI calculable, sinon null",
    "dividend_per_share": nombre ou null,
    "roe_percent": nombre ou null,
    "roa_percent": "rentabilité des actifs = net_income / total actifs, SI le total actif est connu, sinon null"
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
    "book_value_per_share": "capitaux propres / shares_outstanding, calculé seulement si shares_outstanding est connu, sinon null",
    "pe_ratio": "PER = cours de clôture fourni ci-dessus / eps, calculé seulement si eps est connu, sinon null",
    "price_to_book": "cours de clôture / book_value_per_share, calculé seulement si book_value_per_share est connu, sinon null",
    "ev_to_ebitda": "(capitalisation boursière + total_debt - cash_position) / ebitda, calculé seulement si shares_outstanding et ebitda sont connus, sinon null",
    "dividend_yield_percent": "rendement du dividende = dividend_per_share / cours de clôture * 100, si dividend_per_share est connu, sinon null",
    "payout_ratio_percent": "taux de distribution = dividend_per_share * shares_outstanding / net_income * 100, calculé seulement si les trois sont connus, sinon null",
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