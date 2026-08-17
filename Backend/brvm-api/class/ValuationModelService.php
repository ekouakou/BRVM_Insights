<?php
/**
 * Modèles de valorisation intrinsèque d'une entreprise — DCF (flux de
 * trésorerie actualisés), DDM (Gordon Growth Model sur les dividendes),
 * ROIC/EVA (création de valeur économique), et le WACC (coût moyen pondéré
 * du capital) qui leur sert de taux d'actualisation commun.
 *
 * Contrairement à api_fundamentals.php (ratios déjà extraits ou dérivés
 * simplement), ce service repose sur des HYPOTHÈSES DE MARCHÉ explicites
 * (taux sans risque, prime de risque, taux d'imposition, croissance
 * terminale — voir les constantes ci-dessous) choisies avec l'utilisateur :
 * package de valeurs par défaut raisonnables pour la zone UEMOA/BRVM,
 * documentées et modifiables ici en un seul endroit. Ce ne sont jamais des
 * données extraites ou mesurées — toujours affichées comme telles, jamais
 * comme un fait.
 *
 * Ne recalcule PAS les ratios déjà produits par FundamentalsAPI (market_cap,
 * net_debt, free_cash_flow...) : les reçoit en entrée (voir compute()) pour
 * éviter une troisième implémentation indépendante de la même arithmétique
 * financière, qui dériverait inévitablement des deux autres avec le temps.
 * Seul le bêta (nécessite l'historique de cours, pas dans les données déjà
 * extraites) est calculé ici, en dupliquant volontairement la logique
 * d'alignement de séries déjà écrite dans api_risk_metrics.php (convention
 * de ce projet : fichiers indépendants plutôt que couplés entre classes).
 */
class ValuationModelService {
    // === Hypothèses de marché (package par défaut validé avec l'utilisateur) ===
    // Taux sans risque : proxy rendement obligations du Trésor UEMOA long terme.
    private const RISK_FREE_RATE_PERCENT = 6.5;
    // Prime de risque actions : prime additionnelle typique d'un marché frontière comme la BRVM.
    private const MARKET_RISK_PREMIUM_PERCENT = 6.0;
    // Taux d'imposition des sociétés : taux standard Côte d'Ivoire (simplification — certains secteurs/pays UEMOA diffèrent).
    private const CORPORATE_TAX_RATE_PERCENT = 25.0;
    // Croissance terminale (perpétuité, DCF et repli DDM) : proche de l'inflation régionale long terme UEMOA.
    private const TERMINAL_GROWTH_RATE_PERCENT = 2.5;
    // Prime de crédit forfaitaire ajoutée au taux sans risque quand aucune charge d'intérêt n'est extraite pour estimer le coût de la dette.
    private const FALLBACK_CREDIT_SPREAD_PERCENT = 2.0;

    private const DCF_EXPLICIT_YEARS = 5;
    // Croissance annuelle plafonnée pendant la période explicite du DCF — évite d'extrapoler indéfiniment un CAGR historique extrême (ex. redressement post-crise).
    private const MAX_PROJECTED_GROWTH_PERCENT = 15.0;
    private const MIN_PROJECTED_GROWTH_PERCENT = -10.0;

    // Même seuil que RiskMetricsAPI::MIN_DAILY_RETURNS — en-dessous, un bêta serait du bruit statistique.
    private const MIN_DAILY_RETURNS_FOR_BETA = 20;
    private const BETA_LOOKBACK_DAYS = 730;
    // Bêta de repli (marché) quand l'historique de cours est encore trop court pour un calcul fiable — hypothèse conservatrice explicite, jamais présentée comme mesurée.
    private const ASSUMED_BETA_WHEN_UNAVAILABLE = 1.0;

    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * @param array $fundamentals Une ligne déjà produite par FundamentalsAPI::buildRatios()
     *   (voir api_fundamentals.php, action 'list') — envoyée par le frontend, qui l'a déjà
     *   récupérée pour afficher le reste de la fiche de cette entreprise.
     */
    public function compute(int $companyId, array $fundamentals): array {
        $betaInfo = $this->computeBeta($companyId);
        $costOfEquity = self::RISK_FREE_RATE_PERCENT + $betaInfo['beta'] * self::MARKET_RISK_PREMIUM_PERCENT;

        $wacc = $this->computeWacc($fundamentals, $costOfEquity);
        $roicEva = $this->computeRoicEva($fundamentals, $wacc['wacc_percent']);
        $dcf = $this->computeDcf($fundamentals, $wacc['wacc_percent']);
        $ddm = $this->computeDdm($fundamentals, $costOfEquity);

        return [
            'company_id' => $companyId,
            'assumptions' => [
                'risk_free_rate_percent' => self::RISK_FREE_RATE_PERCENT,
                'market_risk_premium_percent' => self::MARKET_RISK_PREMIUM_PERCENT,
                'corporate_tax_rate_percent' => self::CORPORATE_TAX_RATE_PERCENT,
                'terminal_growth_rate_percent' => self::TERMINAL_GROWTH_RATE_PERCENT,
                'dcf_explicit_years' => self::DCF_EXPLICIT_YEARS,
            ],
            'beta' => $betaInfo['beta'],
            'beta_source' => $betaInfo['beta_source'],
            'beta_sample_days' => $betaInfo['beta_sample_days'],
            'cost_of_equity_percent' => round($costOfEquity, 2),
            'wacc' => $wacc,
            'roic_eva' => $roicEva,
            'dcf' => $dcf,
            'ddm' => $ddm,
            'disclaimer' => "Modèle de valorisation théorique basé sur des hypothèses de marché explicites "
                . "(voir 'assumptions') et les derniers chiffres extraits par IA — pas un calcul déterministe, "
                . "encore moins un conseil en investissement. Sensible aux hypothèses : fait varier le WACC et "
                . "la croissance terminale pour voir l'ampleur de cette sensibilité avant de te fier à un chiffre unique.",
        ];
    }

    /**
     * Bêta vs BRVM-COMPOSITE — même formule et même seuil de fiabilité que
     * RiskMetricsAPI::compute() (voir api_risk_metrics.php), mais sur une
     * fenêtre fixe de BETA_LOOKBACK_DAYS jours (pas la période affichée à
     * l'écran par ailleurs) pour maximiser l'historique disponible : un
     * bêta a besoin d'assez de points pour être significatif, contrairement
     * aux ratios "instantané" du reste de cette fiche.
     */
    private function computeBeta(int $companyId): array {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-' . self::BETA_LOOKBACK_DAYS . ' days'));

        $indexRows = $this->crud->executeCustomQuery(
            "SELECT iv.trading_date, iv.variation_percent
             FROM index_values iv
             INNER JOIN market_indices mi ON mi.id = iv.index_id
             WHERE mi.code = 'BRVM-COMPOSITE' AND iv.trading_date >= ? AND iv.trading_date <= ?",
            [$startDate, $endDate]
        ) ?: [];
        $indexReturnByDate = [];
        foreach ($indexRows as $row) {
            if ($row['variation_percent'] !== null) {
                $indexReturnByDate[$row['trading_date']] = (float) $row['variation_percent'] / 100;
            }
        }

        $companyRows = $this->crud->executeCustomQuery(
            "SELECT trading_date, variation_percent FROM stock_quotes
             WHERE company_id = ? AND trading_date >= ? AND trading_date <= ?",
            [$companyId, $startDate, $endDate]
        ) ?: [];

        $assetReturns = [];
        $idxReturns = [];
        foreach ($companyRows as $row) {
            if ($row['variation_percent'] === null || !isset($indexReturnByDate[$row['trading_date']])) {
                continue;
            }
            $assetReturns[] = (float) $row['variation_percent'] / 100;
            $idxReturns[] = $indexReturnByDate[$row['trading_date']];
        }

        $beta = count($assetReturns) >= self::MIN_DAILY_RETURNS_FOR_BETA
            ? ReturnsCalculator::beta($assetReturns, $idxReturns)
            : null;

        if ($beta === null) {
            return [
                'beta' => self::ASSUMED_BETA_WHEN_UNAVAILABLE,
                'beta_source' => 'assumed_market_neutral',
                'beta_sample_days' => count($assetReturns),
            ];
        }

        return ['beta' => round($beta, 3), 'beta_source' => 'computed', 'beta_sample_days' => count($assetReturns)];
    }

    /**
     * WACC = poids capitaux propres × coût des capitaux propres (CAPM) +
     * poids dette × coût de la dette après impôt. Poids basés sur la
     * capitalisation boursière (E) et la dette totale extraite (D) — pas la
     * dette nette : le WACC pondère le financement brut de l'entreprise,
     * pas sa position de trésorerie.
     */
    private function computeWacc(array $f, float $costOfEquity): array {
        $marketCap = $f['market_cap'] ?? null;
        $totalDebt = $f['total_debt'] ?? null;
        $interestExpense = $f['interest_expense'] ?? null;

        $costOfDebtSource = 'extracted';
        if ($interestExpense !== null && $totalDebt !== null && $totalDebt > 0) {
            $costOfDebtPreTax = ($interestExpense / $totalDebt) * 100;
        } else {
            $costOfDebtPreTax = self::RISK_FREE_RATE_PERCENT + self::FALLBACK_CREDIT_SPREAD_PERCENT;
            $costOfDebtSource = 'assumed_risk_free_plus_spread';
        }
        $costOfDebtAfterTax = $costOfDebtPreTax * (1 - self::CORPORATE_TAX_RATE_PERCENT / 100);

        $debtForWeighting = $totalDebt ?? 0.0;
        $totalCapital = ($marketCap ?? 0.0) + $debtForWeighting;

        if ($marketCap === null || $totalCapital <= 0) {
            return [
                'wacc_percent' => null,
                'cost_of_debt_pre_tax_percent' => round($costOfDebtPreTax, 2),
                'cost_of_debt_after_tax_percent' => round($costOfDebtAfterTax, 2),
                'cost_of_debt_source' => $costOfDebtSource,
                'equity_weight_percent' => null,
                'debt_weight_percent' => null,
                'reason' => "Capitalisation boursière indisponible (actions en circulation ou cours de référence manquants) — impossible de pondérer capitaux propres/dette.",
            ];
        }

        $equityWeight = $marketCap / $totalCapital;
        $debtWeight = $debtForWeighting / $totalCapital;
        $wacc = $equityWeight * $costOfEquity + $debtWeight * $costOfDebtAfterTax;

        return [
            'wacc_percent' => round($wacc, 2),
            'cost_of_debt_pre_tax_percent' => round($costOfDebtPreTax, 2),
            'cost_of_debt_after_tax_percent' => round($costOfDebtAfterTax, 2),
            'cost_of_debt_source' => $costOfDebtSource,
            'equity_weight_percent' => round($equityWeight * 100, 1),
            'debt_weight_percent' => round($debtWeight * 100, 1),
            'reason' => null,
        ];
    }

    /**
     * ROIC = NOPAT ÷ capital investi (dette totale + capitaux propres,
     * approche brute — pas nette de trésorerie : le capital investi mesure
     * TOUT le financement mobilisé par l'activité, la trésorerie excédentaire
     * n'est pas "investie" dans l'exploitation). EVA = NOPAT - (capital
     * investi × WACC) : positif signifie que l'entreprise génère plus que le
     * coût de son financement, donc crée de la valeur économique au-delà de
     * la rentabilité comptable.
     */
    private function computeRoicEva(array $f, ?float $waccPercent): array {
        $operatingIncome = $f['operating_income'] ?? null;
        $totalDebt = $f['total_debt'] ?? null;
        $totalEquity = $f['total_equity'] ?? null;

        $nopat = $operatingIncome !== null ? $operatingIncome * (1 - self::CORPORATE_TAX_RATE_PERCENT / 100) : null;
        $investedCapital = ($totalDebt !== null && $totalEquity !== null) ? $totalDebt + $totalEquity : null;

        $roic = ($nopat !== null && $investedCapital !== null && $investedCapital > 0)
            ? ($nopat / $investedCapital) * 100
            : null;

        $eva = ($nopat !== null && $investedCapital !== null && $waccPercent !== null)
            ? $nopat - ($investedCapital * $waccPercent / 100)
            : null;

        $evaSpreadPercent = ($roic !== null && $waccPercent !== null) ? round($roic - $waccPercent, 2) : null;

        return [
            'nopat' => $nopat !== null ? round($nopat, 2) : null,
            'invested_capital' => $investedCapital !== null ? round($investedCapital, 2) : null,
            'roic_percent' => $roic !== null ? round($roic, 2) : null,
            'eva' => $eva !== null ? round($eva, 2) : null,
            'eva_spread_percent' => $evaSpreadPercent,
        ];
    }

    /**
     * DCF simplifié à 2 étapes : projection explicite de DCF_EXPLICIT_YEARS
     * années à un taux de croissance plafonné (CAGR historique du CA, ou du
     * résultat net à défaut, ou 0% en dernier recours), puis valeur
     * terminale (Gordon Growth) actualisées au WACC. Valeur des capitaux
     * propres = valeur d'entreprise du DCF - dette nette, divisée par le
     * nombre d'actions.
     */
    private function computeDcf(array $f, ?float $waccPercent): array {
        $baseFcf = $f['free_cash_flow'] ?? null;
        $sharesOutstanding = $f['shares_outstanding'] ?? null;
        $marketPrice = $f['market_price'] ?? null;
        $netDebt = $f['net_debt'] ?? 0.0;

        if ($baseFcf === null || $baseFcf <= 0) {
            return $this->emptyDcf("Free cash flow indisponible ou négatif dans le dernier rapport analysé — un DCF ne peut pas partir d'un flux de départ négatif ou inconnu.");
        }
        if ($waccPercent === null) {
            return $this->emptyDcf("WACC indisponible (voir wacc.reason) — impossible d'actualiser les flux projetés.");
        }
        if ($sharesOutstanding === null) {
            return $this->emptyDcf("Nombre d'actions en circulation non divulgué dans le rapport source — impossible de convertir la valeur d'entreprise en valeur par action.");
        }

        $growthPercent = $f['revenue_cagr']['cagr_percent'] ?? $f['net_income_cagr']['cagr_percent'] ?? 0.0;
        $growthSource = isset($f['revenue_cagr']['cagr_percent']) && $f['revenue_cagr']['cagr_percent'] !== null
            ? 'revenue_cagr'
            : (isset($f['net_income_cagr']['cagr_percent']) && $f['net_income_cagr']['cagr_percent'] !== null ? 'net_income_cagr' : 'none_assumed_zero');
        $growthPercent = max(self::MIN_PROJECTED_GROWTH_PERCENT, min(self::MAX_PROJECTED_GROWTH_PERCENT, $growthPercent));

        $g = $growthPercent / 100;
        $wacc = $waccPercent / 100;
        $terminalG = self::TERMINAL_GROWTH_RATE_PERCENT / 100;

        if ($wacc <= $terminalG) {
            return $this->emptyDcf("WACC ({$waccPercent}%) inférieur ou égal à la croissance terminale (" . self::TERMINAL_GROWTH_RATE_PERCENT . "%) — la valeur terminale diverge mathématiquement dans ce cas, résultat non calculable.");
        }

        $pvSum = 0.0;
        $fcf = $baseFcf;
        $projectedFcfs = [];
        for ($year = 1; $year <= self::DCF_EXPLICIT_YEARS; $year++) {
            $fcf *= (1 + $g);
            $discounted = $fcf / (1 + $wacc) ** $year;
            $pvSum += $discounted;
            $projectedFcfs[] = ['year' => $year, 'fcf' => round($fcf, 2), 'present_value' => round($discounted, 2)];
        }

        $terminalValue = ($fcf * (1 + $terminalG)) / ($wacc - $terminalG);
        $pvTerminal = $terminalValue / (1 + $wacc) ** self::DCF_EXPLICIT_YEARS;

        $enterpriseValue = $pvSum + $pvTerminal;
        $equityValue = $enterpriseValue - $netDebt;
        $valuePerShare = $equityValue / $sharesOutstanding;

        $upsidePercent = ($marketPrice !== null && $marketPrice > 0)
            ? round((($valuePerShare - $marketPrice) / $marketPrice) * 100, 1)
            : null;

        return [
            'applicable' => true,
            'reason' => null,
            'growth_rate_percent' => round($growthPercent, 2),
            'growth_rate_source' => $growthSource,
            'projected_free_cash_flows' => $projectedFcfs,
            'terminal_value' => round($terminalValue, 2),
            'present_value_terminal_value' => round($pvTerminal, 2),
            'enterprise_value' => round($enterpriseValue, 2),
            'net_debt_deducted' => round($netDebt, 2),
            'equity_value' => round($equityValue, 2),
            'value_per_share' => round($valuePerShare, 2),
            'market_price' => $marketPrice,
            'upside_percent' => $upsidePercent,
        ];
    }

    private function emptyDcf(string $reason): array {
        return [
            'applicable' => false,
            'reason' => $reason,
            'growth_rate_percent' => null,
            'growth_rate_source' => null,
            'projected_free_cash_flows' => [],
            'terminal_value' => null,
            'present_value_terminal_value' => null,
            'enterprise_value' => null,
            'net_debt_deducted' => null,
            'equity_value' => null,
            'value_per_share' => null,
            'market_price' => null,
            'upside_percent' => null,
        ];
    }

    /**
     * DDM (Gordon Growth Model) : V0 = D1 / (Ke - g), avec D1 = dividende
     * par action projeté un an en avant. Seulement applicable aux
     * entreprises versant déjà un dividende — pas de sens pour une
     * entreprise qui n'en distribue pas.
     */
    private function computeDdm(array $f, float $costOfEquity): array {
        $dps = $f['dividend_per_share'] ?? null;
        $marketPrice = $f['market_price'] ?? null;

        if ($dps === null || $dps <= 0) {
            return $this->emptyDdm("Aucun dividende par action divulgué dans le dernier rapport analysé — le modèle de Gordon ne s'applique qu'aux entreprises qui distribuent un dividende.");
        }

        $growthPercent = $f['dividend_cagr']['cagr_percent'] ?? self::TERMINAL_GROWTH_RATE_PERCENT;
        $growthSource = isset($f['dividend_cagr']['cagr_percent']) && $f['dividend_cagr']['cagr_percent'] !== null
            ? 'dividend_cagr'
            : 'assumed_terminal_growth';
        $growthPercent = max(self::MIN_PROJECTED_GROWTH_PERCENT, min(self::MAX_PROJECTED_GROWTH_PERCENT, $growthPercent));

        $g = $growthPercent / 100;
        $ke = $costOfEquity / 100;

        if ($ke <= $g) {
            return $this->emptyDdm("Coût des capitaux propres ({$costOfEquity}%) inférieur ou égal à la croissance du dividende supposée — la formule de Gordon diverge dans ce cas, résultat non calculable.");
        }

        $d1 = $dps * (1 + $g);
        $valuePerShare = $d1 / ($ke - $g);
        $upsidePercent = ($marketPrice !== null && $marketPrice > 0)
            ? round((($valuePerShare - $marketPrice) / $marketPrice) * 100, 1)
            : null;

        return [
            'applicable' => true,
            'reason' => null,
            'growth_rate_percent' => round($growthPercent, 2),
            'growth_rate_source' => $growthSource,
            'projected_dividend_per_share' => round($d1, 2),
            'value_per_share' => round($valuePerShare, 2),
            'market_price' => $marketPrice,
            'upside_percent' => $upsidePercent,
        ];
    }

    private function emptyDdm(string $reason): array {
        return [
            'applicable' => false,
            'reason' => $reason,
            'growth_rate_percent' => null,
            'growth_rate_source' => null,
            'projected_dividend_per_share' => null,
            'value_per_share' => null,
            'market_price' => null,
            'upside_percent' => null,
        ];
    }
}
