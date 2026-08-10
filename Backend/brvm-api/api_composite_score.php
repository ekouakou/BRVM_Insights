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
 * La formule du score technique composite (buildSignal/scoreLabel) et la
 * classification de liquidité (getLiquidityByCompany) sont dupliquées
 * depuis api_signals.php — fichiers indépendants par convention dans ce
 * projet (déjà fait 3 fois : api_screener.php, api_backtest.php). Si l'une
 * des deux formules change, mettre à jour les autres endroits.
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
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class CompositeScoreAPI {
    // Pondérations demandées par l'utilisateur (somme = 100).
    private const WEIGHTS = [
        'fundamental' => 30,
        'technical' => 25,
        'momentum' => 15,
        'liquidity' => 10,
        'sector' => 10,
        'market' => 10,
    ];

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
            $signal = $this->buildSignal($row, $liquidity);
            $periodPerformance = $performanceByCompany[$cid] ?? null;
            $sectorRank = $sectorRankByCompany[$cid] ?? null;
            $sectorSize = $sectorSizeByCompany[$cid] ?? null;
            $fundamentals = $fundamentalsByCompany[$cid] ?? null;

            $subScores = [
                'fundamental' => $this->fundamentalSubScore($fundamentals),
                'technical' => $signal['score'] !== null ? $this->clamp(($signal['score'] + 2) / 4 * 100) : null,
                'momentum' => $periodPerformance !== null ? $this->clamp(($periodPerformance + 30) / 60 * 100) : null,
                'liquidity' => $this->liquiditySubScore($liquidity),
                'sector' => ($sectorRank !== null && $sectorSize) ? $this->clamp((($sectorSize - $sectorRank + 1) / $sectorSize) * 100) : null,
                'market' => ($periodPerformance !== null && $benchmarkReturn !== null)
                    ? $this->clamp((($periodPerformance - $benchmarkReturn) + 15) / 30 * 100)
                    : null,
            ];

            $weightedSum = 0;
            $coveredWeight = 0;
            foreach ($subScores as $key => $value) {
                if ($value !== null) {
                    $weightedSum += $value * self::WEIGHTS[$key];
                    $coveredWeight += self::WEIGHTS[$key];
                }
            }
            $compositeScore = $coveredWeight > 0 ? round($weightedSum / $coveredWeight, 1) : null;
            $coveragePercent = $coveredWeight;

            $result[] = [
                'company_id' => $cid,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector_id' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
                'sector' => $row['sector'],
                'composite_score' => $compositeScore,
                'coverage_percent' => $coveragePercent,
                'sub_scores' => $subScores,
                'weights' => self::WEIGHTS,
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
            'weights' => self::WEIGHTS,
        ];
    }

    private function clamp(float $value): float {
        return max(0, min(100, $value));
    }

    /**
     * Sous-score Fondamental (0-100) : moyenne des composantes disponibles
     * parmi PER, rendement du dividende, ROE, croissance du CA, marge nette
     * — chacune normalisée par un barème simple documenté ci-dessous.
     * Barèmes volontairement simples (pas de comparaison au secteur/marché,
     * pas de calibrage statistique) — à affiner si besoin, mais transparent
     * et déterministe plutôt qu'une boîte noire.
     */
    private function fundamentalSubScore(?array $f): ?float {
        if ($f === null) return null;

        $components = [];

        // PER : récompense un PER modéré (proche de 8), pénalise un PER élevé. Pas de récompense pour un PER très bas (souvent signe de détresse plutôt que d'opportunité).
        if ($f['pe_ratio'] !== null && $f['pe_ratio'] > 0) {
            $components[] = $this->clamp(100 - max(0, $f['pe_ratio'] - 8) * 4);
        }
        // Rendement du dividende : 8%+ = score maximal.
        if ($f['dividend_yield_percent'] !== null) {
            $components[] = $this->clamp($f['dividend_yield_percent'] / 8 * 100);
        }
        // ROE : 25%+ = score maximal.
        if ($f['roe_percent'] !== null) {
            $components[] = $this->clamp($f['roe_percent'] / 25 * 100);
        }
        // Croissance du CA : -10% = 0, +20% = 100.
        if ($f['revenue_growth_percent'] !== null) {
            $components[] = $this->clamp(($f['revenue_growth_percent'] + 10) / 30 * 100);
        }
        // Marge nette : 20%+ = score maximal.
        if ($f['net_margin_percent'] !== null) {
            $components[] = $this->clamp($f['net_margin_percent'] / 20 * 100);
        }

        if (empty($components)) return null;
        return round(array_sum($components) / count($components), 1);
    }

    private function liquiditySubScore(?string $liquidity): ?float {
        return match ($liquidity) {
            'Élevée' => 100.0,
            'Moyenne' => 70.0,
            'Faible' => 40.0,
            'Illiquide' => 10.0,
            default => null,
        };
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
     * Construit le score composite technique (-2 à +2) — copie de
     * api_signals.php::buildSignal() (voir note en tête de fichier).
     */
    private function buildSignal($row, $liquidity = null) {
        $sum = 0;
        $count = 0;
        $signals = [];

        if ($row['rsi_14'] !== null) {
            $rsi = (float) $row['rsi_14'];
            $signals[] = $rsi < 30 ? 1 : ($rsi > 70 ? -1 : 0);
        }
        if ($row['macd_line'] !== null && $row['macd_signal'] !== null) {
            $macdLine = (float) $row['macd_line'];
            $macdSignal = (float) $row['macd_signal'];
            $signals[] = $macdLine > $macdSignal ? 1 : ($macdLine < $macdSignal ? -1 : 0);
        }
        $sma = $row['sma_20'] !== null ? $row['sma_20'] : $row['sma_10'];
        if ($sma !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            $smaValue = (float) $sma;
            $signals[] = $close > $smaValue ? 1 : ($close < $smaValue ? -1 : 0);
        }
        if ($row['bb_upper'] !== null && $row['bb_lower'] !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            if ($close < (float) $row['bb_lower']) {
                $signals[] = 1;
            } elseif ($close > (float) $row['bb_upper']) {
                $signals[] = -1;
            } else {
                $signals[] = 0;
            }
        }

        foreach ($signals as $s) {
            $sum += $s;
            $count++;
        }

        $score = null;
        $label = 'Indéterminé';

        if ($count > 0) {
            $score = (int) round(($sum / $count) * 2);
            $score = max(-2, min(2, $score));

            if ($liquidity === 'Illiquide' && abs($score) === 2) {
                $score = $score > 0 ? 1 : -1;
            }

            $label = match ($score) {
                2 => 'Achat fort',
                1 => 'Achat',
                0 => 'Neutre',
                -1 => 'Vente',
                -2 => 'Vente forte',
                default => 'Indéterminé',
            };
        }

        return ['score' => $score, 'label' => $label];
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
