<?php
/**
 * API de métriques de risque/performance avancées
 * Endpoint: api_risk_metrics.php
 *
 * Sharpe et Sortino ratios (réels, avec taux sans risque explicite),
 * Maximum Drawdown et Calmar, VaR/CVaR historique, skewness/kurtosis, et
 * bêta vs indice (régression) — voir TODO_ANALYSES.md, points 17 à 22.
 *
 * Tout dérive de la même série de rendements journaliers par entreprise
 * (chargée une seule fois), via class/ReturnsCalculator.php — un seul
 * passage par entreprise plutôt que 5 endpoints séparés qui auraient
 * chacun requêté la même série de cours.
 *
 * Différence avec api_quotes.php, action 'risk_adjusted' (déjà existante,
 * volontairement laissée telle quelle) : ce ratio-là est un Sharpe
 * SIMPLIFIÉ (volatilité = churn intrajournalier cumulé, pas un écart-type
 * de rendements, pas de taux sans risque). Les métriques ici sont les
 * définitions standard du domaine — les deux sont complémentaires, pas
 * redondantes, et affichées séparément côté frontend pour ne pas laisser
 * croire que ce sont deux fois la même chose.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
require_once 'class/ReturnsCalculator.php';
AuthGuard::requireAuth();

class RiskMetricsAPI {
    /** Nombre minimum de rendements journaliers avant de considérer les métriques annualisées/statistiques fiables. */
    private const MIN_DAILY_RETURNS = 20;

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'compute':
                    return $this->compute($input);

                default:
                    throw new Exception("Action non reconnue: $action");
            }
        } catch (Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Calcule l'ensemble des métriques de risque/performance pour chaque
     * entreprise sélectionnée, sur une période donnée.
     */
    private function compute($input) {
        $companyIds = $input['company_ids'] ?? [];
        if (empty($companyIds)) {
            throw new Exception("IDs des entreprises requis");
        }

        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-90 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $riskFreeRate = isset($input['risk_free_rate_percent']) ? (float) $input['risk_free_rate_percent'] : 0.0;
        $confidence = isset($input['var_confidence']) ? (float) $input['var_confidence'] : 0.95;

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        // Cours de clôture par entreprise, pour les log-rendements
        // (Sharpe/Sortino/drawdown/Calmar/VaR/CVaR/skewness/kurtosis).
        $sql = "
            SELECT c.id AS company_id, c.symbol, c.name, sq.trading_date, sq.close_price
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.company_id IN ($placeholders)
            AND sq.trading_date >= ? AND sq.trading_date <= ?
            ORDER BY c.id ASC, sq.trading_date ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, array_merge($companyIds, [$startDate, $endDate])) ?: [];

        $byCompany = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (!isset($byCompany[$cid])) {
                $byCompany[$cid] = [
                    'symbol' => $row['symbol'],
                    'name' => $row['name'],
                    'dates' => [],
                    'closes' => [],
                ];
            }
            $byCompany[$cid]['dates'][] = $row['trading_date'];
            $byCompany[$cid]['closes'][] = (float) $row['close_price'];
        }

        // Rendements quotidiens (simples, déjà stockés) de BRVM-COMPOSITE et
        // de chaque entreprise, pour le bêta — aligné par date, sur les
        // seuls jours communs aux deux séries (même principe que
        // getCorrelation()/getRelativeStrength() dans api_quotes.php).
        $indexSql = "
            SELECT iv.trading_date, iv.variation_percent
            FROM index_values iv
            INNER JOIN market_indices mi ON mi.id = iv.index_id
            WHERE mi.code = 'BRVM-COMPOSITE'
            AND iv.trading_date >= ? AND iv.trading_date <= ?
        ";
        $indexRows = $this->crud->executeCustomQuery($indexSql, [$startDate, $endDate]) ?: [];
        $indexReturnByDate = [];
        foreach ($indexRows as $row) {
            if ($row['variation_percent'] !== null) {
                $indexReturnByDate[$row['trading_date']] = (float) $row['variation_percent'] / 100;
            }
        }

        $companyReturnSql = "
            SELECT company_id, trading_date, variation_percent
            FROM stock_quotes
            WHERE company_id IN ($placeholders)
            AND trading_date >= ? AND trading_date <= ?
        ";
        $companyReturnRows = $this->crud->executeCustomQuery($companyReturnSql, array_merge($companyIds, [$startDate, $endDate])) ?: [];
        $companyReturnsByDate = [];
        foreach ($companyReturnRows as $row) {
            if ($row['variation_percent'] !== null) {
                $companyReturnsByDate[(int) $row['company_id']][$row['trading_date']] = (float) $row['variation_percent'] / 100;
            }
        }

        // Rendement net de BRVM-COMPOSITE sur la même période (même calcul
        // que net_return_percent par entreprise : première vs dernière
        // clôture connue dans l'intervalle) — jusqu'ici le bêta utilisait
        // déjà les rendements quotidiens de l'indice en interne, mais sa
        // propre performance sur la période n'était jamais renvoyée : le
        // texte de méthodologie IA mentionne BRVM-COMPOSITE sans qu'aucune
        // valeur concrète ne soit affichée nulle part à côté du bêta.
        $indexCloseSql = "
            SELECT iv.trading_date, iv.close_value
            FROM index_values iv
            INNER JOIN market_indices mi ON mi.id = iv.index_id
            WHERE mi.code = 'BRVM-COMPOSITE'
            AND iv.trading_date >= ? AND iv.trading_date <= ?
            ORDER BY iv.trading_date ASC
        ";
        $indexCloseRows = $this->crud->executeCustomQuery($indexCloseSql, [$startDate, $endDate]) ?: [];
        $benchmarkReturn = null;
        if (count($indexCloseRows) >= 2) {
            $firstIndexClose = (float) $indexCloseRows[0]['close_value'];
            $lastIndexRow = end($indexCloseRows);
            $lastIndexClose = (float) $lastIndexRow['close_value'];
            if ($firstIndexClose > 0) {
                $benchmarkReturn = round((($lastIndexClose - $firstIndexClose) / $firstIndexClose) * 100, 2);
            }
        }

        $result = [];
        foreach ($byCompany as $companyId => $data) {
            $closes = $data['closes'];
            $dates = $data['dates'];
            $dailyReturns = ReturnsCalculator::dailyLogReturns($closes);

            $firstClose = $closes[0] ?? null;
            $lastClose = !empty($closes) ? end($closes) : null;
            $calendarDays = (int) round((strtotime($endDate) - strtotime($startDate)) / 86400);

            $netReturn = ($firstClose && $lastClose)
                ? round((($lastClose - $firstClose) / $firstClose) * 100, 2)
                : null;

            // En-dessous de ce nombre de rendements journaliers, CAGR/
            // volatilité annualisée/Sharpe/Sortino/Calmar/VaR/CVaR/
            // skewness/kurtosis/bêta seraient statistiquement du bruit
            // (ex. extrapoler une volatilité "annualisée" à partir de 2
            // observations n'a aucun sens) — cohérent avec le reste de
            // l'app, qui affiche déjà "historique insuffisant" plutôt
            // qu'un chiffre trompeur tant que l'historique est trop court
            // (voir ADX/Stochastique/RSI dans Quotes.tsx).
            $hasEnoughHistory = count($dailyReturns) >= self::MIN_DAILY_RETURNS;

            $cagr = ($hasEnoughHistory && $firstClose && $lastClose)
                ? ReturnsCalculator::cagrPercent($firstClose, $lastClose, $calendarDays)
                : null;
            $annualizedReturn = $hasEnoughHistory ? ReturnsCalculator::annualizedMeanReturnPercent($dailyReturns) : null;
            $annualizedVol = $hasEnoughHistory ? ReturnsCalculator::annualizedVolatilityPercent($dailyReturns) : null;
            $downsideDev = $hasEnoughHistory ? ReturnsCalculator::downsideDeviationPercent($dailyReturns) : null;

            $sharpe = ($annualizedReturn !== null && $annualizedVol)
                ? ($annualizedReturn - $riskFreeRate) / $annualizedVol
                : null;
            $sortino = ($annualizedReturn !== null && $downsideDev)
                ? ($annualizedReturn - $riskFreeRate) / $downsideDev
                : null;

            $drawdown = $hasEnoughHistory ? ReturnsCalculator::maxDrawdown($closes) : null;
            $calmar = ($drawdown && $cagr !== null && $drawdown['drawdown_percent'] != 0)
                ? $cagr / abs($drawdown['drawdown_percent'])
                : null;

            $var = $hasEnoughHistory ? ReturnsCalculator::historicalVaRPercent($dailyReturns, $confidence) : null;
            $cvar = $hasEnoughHistory ? ReturnsCalculator::historicalCVaRPercent($dailyReturns, $confidence) : null;
            $skew = $hasEnoughHistory ? ReturnsCalculator::skewness($dailyReturns) : null;
            $kurtosis = $hasEnoughHistory ? ReturnsCalculator::excessKurtosis($dailyReturns) : null;

            $assetReturns = [];
            $idxReturns = [];
            foreach (($companyReturnsByDate[$companyId] ?? []) as $date => $ret) {
                if (isset($indexReturnByDate[$date])) {
                    $assetReturns[] = $ret;
                    $idxReturns[] = $indexReturnByDate[$date];
                }
            }
            $beta = count($assetReturns) >= self::MIN_DAILY_RETURNS
                ? ReturnsCalculator::beta($assetReturns, $idxReturns)
                : null;

            $result[] = [
                'company_id' => $companyId,
                'symbol' => $data['symbol'],
                'name' => $data['name'],
                'trading_days' => count($closes),
                'daily_returns_count' => count($dailyReturns),
                'insufficient_history' => !$hasEnoughHistory,
                'net_return_percent' => $netReturn,
                'cagr_percent' => $cagr !== null ? round($cagr, 2) : null,
                'annualized_volatility_percent' => $annualizedVol !== null ? round($annualizedVol, 2) : null,
                'sharpe_ratio' => $sharpe !== null ? round($sharpe, 3) : null,
                'sortino_ratio' => $sortino !== null ? round($sortino, 3) : null,
                'max_drawdown_percent' => $drawdown ? round($drawdown['drawdown_percent'], 2) : null,
                'max_drawdown_peak_date' => $drawdown ? $dates[$drawdown['peak_index']] : null,
                'max_drawdown_trough_date' => $drawdown ? $dates[$drawdown['trough_index']] : null,
                'calmar_ratio' => $calmar !== null ? round($calmar, 3) : null,
                'var_percent' => $var !== null ? round($var, 2) : null,
                'cvar_percent' => $cvar !== null ? round($cvar, 2) : null,
                'skewness' => $skew !== null ? round($skew, 3) : null,
                'excess_kurtosis' => $kurtosis !== null ? round($kurtosis, 3) : null,
                'beta' => $beta !== null ? round($beta, 3) : null,
                'beta_common_days' => count($assetReturns),
                // Rendement net de l'indice BRVM-COMPOSITE sur la même période
                // (identique pour toutes les entreprises de la sélection) — la
                // valeur concrète face à laquelle le bêta se lit.
                'benchmark_return_percent' => $benchmarkReturn,
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'risk_free_rate_percent' => $riskFreeRate,
            'var_confidence' => $confidence
        ];
    }
}

// Exécution
$api = new RiskMetricsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
