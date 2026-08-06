<?php
/**
 * API de screening multi-critères
 * Endpoint: api_screener.php
 *
 * Croise plusieurs sources déjà exposées séparément ailleurs (signal
 * technique composite, liquidité, performance sur une période, classement
 * au sein du secteur) en une seule table filtrable côté serveur — plutôt
 * que de naviguer entre les écrans Cotations/Classements/Statistiques pour
 * croiser manuellement les critères. Voir TODO_ANALYSES.md, point 23.
 *
 * La formule du score composite (buildSignal/scoreLabel) et la
 * classification de liquidité (getLiquidityByCompany) sont dupliquées
 * depuis api_signals.php — fichiers indépendants par convention dans ce
 * projet plutôt qu'un couplage entre classes API (même choix déjà fait
 * pour la liquidité, dupliquée depuis api_quotes.php dans api_signals.php).
 * Si l'une des deux formules change, mettre à jour les deux endroits.
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

class ScreenerAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'screen':
                    return $this->screen($input);

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
     * Classement filtrable de toutes les entreprises actives, croisant
     * signal technique, liquidité, performance sur la période et
     * classement au sein du secteur.
     */
    private function screen($input) {
        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sectorId = isset($input['sector_id']) && $input['sector_id'] !== '' ? (int) $input['sector_id'] : null;
        $minScore = isset($input['min_score']) && $input['min_score'] !== '' ? (int) $input['min_score'] : null;
        $maxScore = isset($input['max_score']) && $input['max_score'] !== '' ? (int) $input['max_score'] : null;
        $minRsi = isset($input['min_rsi']) && $input['min_rsi'] !== '' ? (float) $input['min_rsi'] : null;
        $maxRsi = isset($input['max_rsi']) && $input['max_rsi'] !== '' ? (float) $input['max_rsi'] : null;
        $minVariation = isset($input['min_variation_percent']) && $input['min_variation_percent'] !== '' ? (float) $input['min_variation_percent'] : null;
        $maxVariation = isset($input['max_variation_percent']) && $input['max_variation_percent'] !== '' ? (float) $input['max_variation_percent'] : null;
        $minVolume = isset($input['min_volume']) && $input['min_volume'] !== '' ? (float) $input['min_volume'] : null;
        $liquidityIn = $input['liquidity'] ?? [];

        // 1. Dernière cotation + derniers indicateurs techniques connus par entreprise.
        $sql = "
            SELECT
                c.id AS company_id, c.symbol, c.name, c.sector_id, s.name AS sector,
                sq.trading_date, sq.close_price, sq.variation_percent, sq.volume,
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

        // 2. Performance sur la période choisie (premier vs dernier cours de
        // clôture de la plage), pour le classement au sein du secteur.
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

        // Classement au sein du secteur, par performance sur la période —
        // une entreprise sans performance calculable sur la période (pas de
        // cotation) n'est pas classée (pas de rang "au hasard").
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

        // 3. Score composite (dupliqué de api_signals.php::buildSignal(), voir note en tête de fichier) + filtres.
        $result = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            $liquidity = $liquidityByCompany[$cid] ?? null;
            $signal = $this->buildSignal($row, $liquidity);

            $variationPercent = $row['variation_percent'] !== null ? (float) $row['variation_percent'] : null;
            $rsi = $row['rsi_14'] !== null ? (float) $row['rsi_14'] : null;
            $volume = (float) ($row['volume'] ?? 0);

            if ($sectorId !== null && (int) $row['sector_id'] !== $sectorId) continue;
            if ($minScore !== null && ($signal['score'] === null || $signal['score'] < $minScore)) continue;
            if ($maxScore !== null && ($signal['score'] === null || $signal['score'] > $maxScore)) continue;
            if ($minRsi !== null && ($rsi === null || $rsi < $minRsi)) continue;
            if ($maxRsi !== null && ($rsi === null || $rsi > $maxRsi)) continue;
            if ($minVariation !== null && ($variationPercent === null || $variationPercent < $minVariation)) continue;
            if ($maxVariation !== null && ($variationPercent === null || $variationPercent > $maxVariation)) continue;
            if ($minVolume !== null && $volume < $minVolume) continue;
            if (!empty($liquidityIn) && !in_array($liquidity, $liquidityIn, true)) continue;

            $result[] = [
                'company_id' => $cid,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector_id' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
                'sector' => $row['sector'],
                'trading_date' => $row['trading_date'],
                'close_price' => $row['close_price'],
                'variation_percent' => $row['variation_percent'],
                'volume' => $row['volume'],
                'rsi_14' => $row['rsi_14'],
                'score' => $signal['score'],
                'label' => $signal['label'],
                'liquidity' => $liquidity,
                'period_performance_percent' => $performanceByCompany[$cid] ?? null,
                'sector_rank' => $sectorRankByCompany[$cid] ?? null,
                'sector_size' => $sectorSizeByCompany[$cid] ?? null,
            ];
        }

        usort($result, function ($a, $b) {
            return abs($b['score'] ?? 0) <=> abs($a['score'] ?? 0);
        });

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Construit le score composite (-2 à +2) — copie de
     * api_signals.php::buildSignal(), sans le contexte ATR (pas utile ici,
     * la colonne "Signal" du screener sert juste de filtre/tri, pas
     * d'analyse détaillée d'une entreprise).
     */
    private function buildSignal($row, $liquidity = null) {
        $details = [];
        $sum = 0;
        $count = 0;

        if ($row['rsi_14'] !== null) {
            $rsi = (float) $row['rsi_14'];
            if ($rsi < 30) {
                $details['rsi'] = ['signal' => 1];
            } elseif ($rsi > 70) {
                $details['rsi'] = ['signal' => -1];
            } else {
                $details['rsi'] = ['signal' => 0];
            }
            $sum += $details['rsi']['signal'];
            $count++;
        }

        if ($row['macd_line'] !== null && $row['macd_signal'] !== null) {
            $macdLine = (float) $row['macd_line'];
            $macdSignal = (float) $row['macd_signal'];
            $details['macd'] = ['signal' => $macdLine > $macdSignal ? 1 : ($macdLine < $macdSignal ? -1 : 0)];
            $sum += $details['macd']['signal'];
            $count++;
        }

        $sma = $row['sma_20'] !== null ? $row['sma_20'] : $row['sma_10'];
        if ($sma !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            $smaValue = (float) $sma;
            $details['trend'] = ['signal' => $close > $smaValue ? 1 : ($close < $smaValue ? -1 : 0)];
            $sum += $details['trend']['signal'];
            $count++;
        }

        if ($row['bb_upper'] !== null && $row['bb_lower'] !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            if ($close < (float) $row['bb_lower']) {
                $details['bollinger'] = ['signal' => 1];
            } elseif ($close > (float) $row['bb_upper']) {
                $details['bollinger'] = ['signal' => -1];
            } else {
                $details['bollinger'] = ['signal' => 0];
            }
            $sum += $details['bollinger']['signal'];
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

            $label = $this->scoreLabel($score);
        }

        return ['score' => $score, 'label' => $label];
    }

    private function scoreLabel($score) {
        switch ($score) {
            case 2: return 'Achat fort';
            case 1: return 'Achat';
            case 0: return 'Neutre';
            case -1: return 'Vente';
            case -2: return 'Vente forte';
            default: return 'Indéterminé';
        }
    }

    /**
     * Classement de liquidité par entreprise — copie de
     * api_signals.php::getLiquidityByCompany() (mêmes seuils que
     * api_quotes.php::getLiquidity()).
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
$api = new ScreenerAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
