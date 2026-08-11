<?php
/**
 * API de score composite 0-100 par entreprise
 * Endpoint: api_composite_score.php
 *
 * Synthétise en une seule note 0-100 les 6 dimensions déjà calculées
 * séparément ailleurs dans l'application : Fondamental (30%), Technique
 * (25%), Momentum (15%), Liquidité (10%), Secteur (10%), Marché (10%) —
 * pondérations demandées explicitement par l'utilisateur. Un score de
 * synthèse, PAS un signal d'achat/vente ni une prédiction — voir
 * TODO_ANALYSES.md pour le contexte de cette demande.
 *
 * Le calcul pur des sous-scores (fondamental/technique/momentum/liquidité/
 * secteur/marché) vit dans class/CompositeScoreCalculator.php, partagé
 * avec la fonctionnalité "Mon Équipe BRVM" (api_portfolio.php, voir
 * TODO_PORTFOLIO_TEAM.md) — ce fichier ne garde que la récupération SQL et
 * l'assemblage de la réponse. La classification de liquidité
 * (getLiquidityByCompany, ci-dessous) reste dupliquée depuis
 * api_signals.php par convention (petite formule, déjà fait 3 fois :
 * api_screener.php, api_backtest.php).
 *
 * Couverture partielle assumée : les fondamentaux ne sont disponibles que
 * pour les entreprises ayant un rapport financier déjà traité avec succès
 * (une minorité — voir api_fundamentals.php). Plutôt que de pénaliser une
 * entreprise sans fondamentaux avec un score de 0 sur cette dimension, le
 * score composite est renormalisé sur les seules dimensions disponibles
 * (coverage_percent indique quelle part des 100% de pondération a
 * effectivement pu être calculée — à toujours afficher à côté du score,
 * un score basé sur 40% de couverture n'a pas la même fiabilité qu'un
 * score basé sur 100%).
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
require_once 'class/CompositeScoreCalculator.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class CompositeScoreAPI {
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

    private function compute($input) {
        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $sectorId = isset($input['sector_id']) && $input['sector_id'] !== '' ? (int) $input['sector_id'] : null;

        // 1. Dernière cotation + derniers indicateurs techniques connus par entreprise (même requête qu'api_screener.php).
        $sql = "
            SELECT
                c.id AS company_id, c.symbol, c.name, c.sector_id, s.name AS sector,
                sq.trading_date, sq.close_price, sq.variation_percent,
                ti.rsi_14, ti.macd_line, ti.macd_signal, ti.sma_10, ti.sma_20, ti.bb_upper, ti.bb_lower
            FROM companies c
            LEFT JOIN sectors s ON s.id = c.sector_id
            INNER JOIN stock_quotes sq
                ON sq.company_id = c.id
                AND sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes WHERE company_id = c.id)
            LEFT JOIN technical_indicators ti
                ON ti.company_id = c.id AND ti.trading_date = sq.trading_date
            WHERE c.active = 1
        ";
        $rows = $this->crud->executeCustomQuery($sql) ?: [];

        $liquidityByCompany = $this->getLiquidityByCompany();

        // 2. Performance sur la période (premier vs dernier cours de clôture), pour Momentum + Secteur + Marché.
        $perfSql = "
            SELECT company_id, trading_date, close_price
            FROM stock_quotes
            WHERE trading_date >= ? AND trading_date <= ?
            ORDER BY company_id ASC, trading_date ASC
        ";
        $perfRows = $this->crud->executeCustomQuery($perfSql, [$startDate, $endDate]) ?: [];

        $firstClose = [];
        $lastClose = [];
        foreach ($perfRows as $row) {
            $cid = (int) $row['company_id'];
            if (!isset($firstClose[$cid])) {
                $firstClose[$cid] = (float) $row['close_price'];
            }
            $lastClose[$cid] = (float) $row['close_price'];
        }
        $performanceByCompany = [];
        foreach ($firstClose as $cid => $first) {
            if ($first > 0) {
                $performanceByCompany[$cid] = round((($lastClose[$cid] - $first) / $first) * 100, 2);
            }
        }

        // Classement au sein du secteur par performance sur la période (même logique qu'api_screener.php).
        $bySector = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (isset($performanceByCompany[$cid])) {
                $bySector[$row['sector_id']][] = ['company_id' => $cid, 'performance' => $performanceByCompany[$cid]];
            }
        }
        $sectorRankByCompany = [];
        $sectorSizeByCompany = [];
        foreach ($bySector as $members) {
            usort($members, fn($a, $b) => $b['performance'] <=> $a['performance']);
            $rank = 1;
            foreach ($members as $m) {
                $sectorRankByCompany[$m['company_id']] = $rank;
                $sectorSizeByCompany[$m['company_id']] = count($members);
                $rank++;
            }
        }

        // 3. Rendement net de BRVM-COMPOSITE sur la même période (pour la dimension Marché — même calcul qu'api_risk_metrics.php).
        $benchmarkReturn = $this->getBenchmarkReturn($startDate, $endDate);

        // 4. Fondamentaux (dernier rapport financier traité avec succès par entreprise — même source qu'api_fundamentals.php).
        $fundamentalsByCompany = $this->getFundamentalsByCompany();

        // 5. Assemblage + score composite par entreprise.
        $result = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if ($sectorId !== null && (int) $row['sector_id'] !== $sectorId) continue;

            $liquidity = $liquidityByCompany[$cid] ?? null;
            $signal = CompositeScoreCalculator::buildSignal($row, $liquidity);
            $periodPerformance = $performanceByCompany[$cid] ?? null;
            $sectorRank = $sectorRankByCompany[$cid] ?? null;
            $sectorSize = $sectorSizeByCompany[$cid] ?? null;
            $fundamentals = $fundamentalsByCompany[$cid] ?? null;

            $subScores = CompositeScoreCalculator::computeSubScores(
                $row, $fundamentals, $liquidity, $periodPerformance, $sectorRank, $sectorSize, $benchmarkReturn
            );
            $weighted = CompositeScoreCalculator::weightedScore($subScores);

            $result[] = [
                'company_id' => $cid,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector_id' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
                'sector' => $row['sector'],
                'composite_score' => $weighted['composite_score'],
                'coverage_percent' => $weighted['coverage_percent'],
                'sub_scores' => $subScores,
                'weights' => CompositeScoreCalculator::WEIGHTS,
                'close_price' => $row['close_price'],
                'variation_percent' => $row['variation_percent'],
                'period_performance_percent' => $periodPerformance,
                'benchmark_return_percent' => $benchmarkReturn,
                'liquidity' => $liquidity,
                'signal_score' => $signal['score'],
                'signal_label' => $signal['label'],
                'sector_rank' => $sectorRank,
                'sector_size' => $sectorSize,
                'fundamentals_available' => $fundamentals !== null,
            ];
        }

        usort($result, function ($a, $b) {
            return ($b['composite_score'] ?? -1) <=> ($a['composite_score'] ?? -1);
        });

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'weights' => CompositeScoreCalculator::WEIGHTS,
        ];
    }

    /**
     * Rendement net de BRVM-COMPOSITE sur la période (première vs dernière
     * clôture connue dans l'intervalle) — même calcul qu'api_risk_metrics.php.
     */
    private function getBenchmarkReturn(string $startDate, string $endDate): ?float {
        $sql = "
            SELECT iv.close_value
            FROM index_values iv
            INNER JOIN market_indices mi ON mi.id = iv.index_id
            WHERE mi.code = 'BRVM-COMPOSITE'
            AND iv.trading_date >= ? AND iv.trading_date <= ?
            ORDER BY iv.trading_date ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$startDate, $endDate]) ?: [];
        if (count($rows) < 2) return null;

        $first = (float) $rows[0]['close_value'];
        $last = (float) end($rows)['close_value'];
        if ($first <= 0) return null;

        return round((($last - $first) / $first) * 100, 2);
    }

    /**
     * Dernier rapport financier traité avec succès par entreprise — même
     * source qu'api_fundamentals.php (company_report_analyses.details).
     */
    private function getFundamentalsByCompany(): array {
        $sql = "
            SELECT cra.company_id, cra.details, cr.publish_date
            FROM company_report_analyses cra
            INNER JOIN company_reports cr ON cr.id = cra.report_id
            INNER JOIN companies c ON c.id = cra.company_id
            WHERE cra.status = 'success'
            AND c.active = 1
            ORDER BY cra.company_id ASC, cr.publish_date DESC
        ";
        $rows = $this->crud->executeCustomQuery($sql) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (isset($result[$cid])) continue; // déjà le plus récent (tri DESC ci-dessus)

            $details = json_decode($row['details'] ?? 'null', true) ?: [];
            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];

            $result[$cid] = [
                'pe_ratio' => $this->toFloatOrNull($valuation['pe_ratio'] ?? null),
                'dividend_yield_percent' => $this->toFloatOrNull($valuation['dividend_yield_percent'] ?? null),
                'roe_percent' => $this->toFloatOrNull($financials['roe_percent'] ?? null),
                'revenue_growth_percent' => $this->toFloatOrNull($financials['revenue_growth_percent'] ?? null),
                'net_margin_percent' => $this->toFloatOrNull($financials['net_margin_percent'] ?? null),
            ];
        }

        return $result;
    }

    private function toFloatOrNull($value): ?float {
        if ($value === null || $value === '') return null;
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Classement de liquidité par entreprise — copie de
     * api_signals.php::getLiquidityByCompany() (voir note en tête de fichier).
     */
    private function getLiquidityByCompany() {
        $days = 30;
        $endDate = date('Y-m-d');

        $sql = "
            SELECT
                c.id AS company_id,
                AVG(sq.volume) AS avg_volume,
                SUM(CASE WHEN sq.volume = 0 OR sq.volume IS NULL THEN 1 ELSE 0 END) AS zero_volume_days,
                COUNT(*) AS total_days
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND sq.trading_date <= ?
            AND c.active = 1
            GROUP BY c.id
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$endDate, $days, $endDate]) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $avgVolume = (float) $row['avg_volume'];
            $totalDays = (int) $row['total_days'];
            $zeroDays = (int) $row['zero_volume_days'];
            $zeroRatio = $totalDays > 0 ? $zeroDays / $totalDays : 0;

            if ($zeroRatio > 0.3) {
                $label = 'Illiquide';
            } elseif ($avgVolume < 200) {
                $label = 'Faible';
            } elseif ($avgVolume < 2000) {
                $label = 'Moyenne';
            } else {
                $label = 'Élevée';
            }

            $result[(int) $row['company_id']] = $label;
        }

        return $result;
    }
}

// Exécution
$api = new CompositeScoreAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
