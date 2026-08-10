<?php
/**
 * API de backtesting simple
 * Endpoint: api_backtest.php
 *
 * Simule l'application d'une règle de trading simple, jour par jour, sur
 * l'historique déjà persisté (stock_quotes + technical_indicators) pour
 * UNE entreprise, et compare la performance obtenue à un simple "acheter
 * et garder" sur la même période. Voir TODO_ANALYSES.md, point 25.
 *
 * ⚠️ Le résultat n'a de valeur statistique qu'avec un historique long
 * (idéalement plusieurs mois/années de jours de bourse) — voir le champ
 * `insufficient_history` dans la réponse, toujours à afficher côté
 * frontend tant que l'historique de synchronisation de l'application
 * reste court (quelques jours au moment où ce fichier est écrit). Le
 * moteur fonctionne dès maintenant sur ce qui existe (0 ou 1 trade la
 * plupart du temps pour l'instant) plutôt que d'être bloqué en attendant
 * — il devient utile progressivement, sans changement de code, à mesure
 * que l'historique s'accumule au fil des synchronisations quotidiennes.
 *
 * Aucune persistance des résultats (pas de nouvelle table) : la
 * simulation est rejouée à la demande, peu coûteuse sur l'historique
 * actuel — à revoir si un historique de plusieurs années rend le
 * recalcul systématique trop lent, ou si comparer plusieurs runs entre
 * eux devient un besoin réel.
 *
 * La formule du score composite est dupliquée depuis api_signals.php
 * (comme déjà fait dans api_screener.php) — fichiers indépendants par
 * convention dans ce projet plutôt qu'un couplage entre classes API. Si
 * la formule change, mettre à jour les autres endroits qui la dupliquent.
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

class BacktestAPI {
    /** En-dessous de ce nombre de jours de bourse simulés, le résultat est signalé comme peu fiable statistiquement (mais reste calculé et affiché). */
    private const MIN_TRADING_DAYS_FOR_RELIABILITY = 60;

    /** Périodes de SMA disponibles pour la règle golden_cross (déjà persistées dans technical_indicators). */
    private const ALLOWED_SMA_PERIODS = ['10', '20', '50'];

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'run':
                    return $this->run($input);

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

    private function run($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }
        if (!$companyId && $symbol) {
            $found = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($found)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $found[0]['id'];
        }

        $company = $this->crud->find('companies', ['id' => $companyId]);
        if (empty($company)) {
            throw new Exception("Entreprise non trouvée");
        }

        $rule = $input['rule'] ?? 'signal_score';
        if (!in_array($rule, ['signal_score', 'golden_cross'], true)) {
            throw new Exception("Règle non reconnue: $rule (attendu: signal_score ou golden_cross)");
        }

        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-180 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $buyThreshold = isset($input['buy_threshold']) ? (int) $input['buy_threshold'] : 1;
        $sellThreshold = isset($input['sell_threshold']) ? (int) $input['sell_threshold'] : -1;

        $fastSma = (string) ($input['fast_sma'] ?? '10');
        $slowSma = (string) ($input['slow_sma'] ?? '20');
        if (!in_array($fastSma, self::ALLOWED_SMA_PERIODS, true)) $fastSma = '10';
        if (!in_array($slowSma, self::ALLOWED_SMA_PERIODS, true)) $slowSma = '20';
        if ($fastSma === $slowSma) {
            throw new Exception("Les deux moyennes mobiles doivent être de périodes différentes");
        }

        $sql = "
            SELECT
                sq.trading_date, sq.close_price,
                ti.rsi_14, ti.macd_line, ti.macd_signal,
                ti.sma_10, ti.sma_20, ti.sma_50,
                ti.bb_upper, ti.bb_lower
            FROM stock_quotes sq
            LEFT JOIN technical_indicators ti
                ON ti.company_id = sq.company_id AND ti.trading_date = sq.trading_date
            WHERE sq.company_id = ?
            AND sq.trading_date >= ? AND sq.trading_date <= ?
            ORDER BY sq.trading_date ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$companyId, $startDate, $endDate]) ?: [];

        if (count($rows) < 2) {
            throw new Exception("Historique insuffisant sur cette période pour simuler quoi que ce soit (minimum 2 jours de cotation).");
        }

        $simulation = $rule === 'golden_cross'
            ? $this->simulateGoldenCross($rows, $fastSma, $slowSma)
            : $this->simulateSignalScore($rows, $buyThreshold, $sellThreshold);

        $tradingDays = count($rows);

        return [
            'success' => true,
            'data' => array_merge([
                'company_id' => $companyId,
                'symbol' => $company[0]['symbol'],
                'name' => $company[0]['name'],
                'rule' => $rule,
                'rule_params' => $rule === 'golden_cross'
                    ? ['fast_sma' => $fastSma, 'slow_sma' => $slowSma]
                    : ['buy_threshold' => $buyThreshold, 'sell_threshold' => $sellThreshold],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'trading_days' => $tradingDays,
                'insufficient_history' => $tradingDays < self::MIN_TRADING_DAYS_FOR_RELIABILITY,
                'min_trading_days_for_reliability' => self::MIN_TRADING_DAYS_FOR_RELIABILITY,
            ], $simulation)
        ];
    }

    /**
     * Règle "signal composite" : entre en position LONGUE quand le score du
     * jour (même formule que api_signals.php::buildSignal(), voir note en
     * tête de fichier) atteint $buyThreshold, sort quand il retombe à
     * $sellThreshold ou en-dessous. En parallèle, simule une position
     * VENDEUSE à découvert avec la logique exactement inversée (entre à
     * $sellThreshold ou en-dessous, sort/rachète à $buyThreshold) — les deux
     * stratégies tournent indépendamment sur le même historique, ce n'est
     * pas un simple miroir mathématique de la première. Les jours à score
     * indéterminé (pas assez d'indicateurs disponibles) n'agissent sur
     * aucune des deux — la position en cours (ou l'absence de position) est
     * simplement maintenue.
     */
    private function simulateSignalScore($rows, $buyThreshold, $sellThreshold) {
        $position = null;
        $shortPosition = null;
        $trades = [];
        $shortTrades = [];
        $equity = 1.0;
        $shortEquity = 1.0;
        $curve = [];
        $firstClose = null;

        foreach ($rows as $row) {
            $close = (float) $row['close_price'];
            if ($firstClose === null) {
                $firstClose = $close;
            }
            $buyHold = $firstClose > 0 ? $close / $firstClose : 1.0;

            $score = $this->computeScore($row);

            if ($position === null && $score !== null && $score >= $buyThreshold) {
                $position = ['entry_date' => $row['trading_date'], 'entry_price' => $close];
            } elseif ($position !== null && $score !== null && $score <= $sellThreshold) {
                $trades[] = $this->closeTrade($position, $row['trading_date'], $close, 'long');
                $equity *= (1 + end($trades)['return_percent'] / 100);
                $position = null;
            }

            if ($shortPosition === null && $score !== null && $score <= $sellThreshold) {
                $shortPosition = ['entry_date' => $row['trading_date'], 'entry_price' => $close];
            } elseif ($shortPosition !== null && $score !== null && $score >= $buyThreshold) {
                $shortTrades[] = $this->closeTrade($shortPosition, $row['trading_date'], $close, 'short');
                $shortEquity *= (1 + end($shortTrades)['return_percent'] / 100);
                $shortPosition = null;
            }

            $curve[] = $this->equityPoint($row['trading_date'], $equity, $position, $shortEquity, $shortPosition, $close, $buyHold);
        }

        return $this->buildSummary($trades, $shortTrades, $curve, $position, $shortPosition);
    }

    /**
     * Règle "golden/death cross" : entre en position LONGUE au croisement
     * haussier de la paire de SMA choisie (rapide qui passe au-dessus de la
     * lente), sort au croisement baissier (l'inverse) — même détection que
     * api_signals.php::getCrossovers(), mais rejouée ici jour par jour dans
     * le cadre de la simulation plutôt que listée après coup. En parallèle,
     * simule une position VENDEUSE avec la logique inversée (entre au
     * croisement baissier/death cross, sort/rachète au croisement
     * haussier/golden cross) — deux simulations indépendantes sur le même
     * historique, pas un miroir mathématique.
     */
    private function simulateGoldenCross($rows, $fastKey, $slowKey) {
        $fastField = 'sma_' . $fastKey;
        $slowField = 'sma_' . $slowKey;

        $position = null;
        $shortPosition = null;
        $trades = [];
        $shortTrades = [];
        $equity = 1.0;
        $shortEquity = 1.0;
        $curve = [];
        $firstClose = null;
        $prevSign = null;

        foreach ($rows as $row) {
            $close = (float) $row['close_price'];
            if ($firstClose === null) {
                $firstClose = $close;
            }
            $buyHold = $firstClose > 0 ? $close / $firstClose : 1.0;

            $fast = $row[$fastField];
            $slow = $row[$slowField];
            $crossSignal = null;

            if ($fast !== null && $slow !== null) {
                $diff = (float) $fast - (float) $slow;
                $sign = $diff > 0 ? 1 : ($diff < 0 ? -1 : 0);
                if ($sign !== 0) {
                    if ($prevSign !== null && $sign !== $prevSign) {
                        $crossSignal = $sign > 0 ? 'buy' : 'sell';
                    }
                    $prevSign = $sign;
                }
            }

            if ($position === null && $crossSignal === 'buy') {
                $position = ['entry_date' => $row['trading_date'], 'entry_price' => $close];
            } elseif ($position !== null && $crossSignal === 'sell') {
                $trades[] = $this->closeTrade($position, $row['trading_date'], $close, 'long');
                $equity *= (1 + end($trades)['return_percent'] / 100);
                $position = null;
            }

            if ($shortPosition === null && $crossSignal === 'sell') {
                $shortPosition = ['entry_date' => $row['trading_date'], 'entry_price' => $close];
            } elseif ($shortPosition !== null && $crossSignal === 'buy') {
                $shortTrades[] = $this->closeTrade($shortPosition, $row['trading_date'], $close, 'short');
                $shortEquity *= (1 + end($shortTrades)['return_percent'] / 100);
                $shortPosition = null;
            }

            $curve[] = $this->equityPoint($row['trading_date'], $equity, $position, $shortEquity, $shortPosition, $close, $buyHold);
        }

        return $this->buildSummary($trades, $shortTrades, $curve, $position, $shortPosition);
    }

    /** $direction='short' inverse simplement le sens du gain (on gagne quand le cours baisse entre l'entrée et la sortie). */
    private function closeTrade($position, $exitDate, $exitPrice, $direction = 'long') {
        $rawReturn = $position['entry_price'] > 0
            ? (($exitPrice - $position['entry_price']) / $position['entry_price']) * 100
            : 0;
        $tradeReturn = $direction === 'short' ? -$rawReturn : $rawReturn;

        return [
            'entry_date' => $position['entry_date'],
            'entry_price' => $position['entry_price'],
            'exit_date' => $exitDate,
            'exit_price' => $exitPrice,
            'return_percent' => round($tradeReturn, 2)
        ];
    }

    /**
     * Valeur des trois portefeuilles base 100, en mark-to-market si une
     * position est ouverte ce jour-là. La position vendeuse ('short') est
     * valorisée par entry_price / close (et non close / entry_price comme
     * la position longue) : sa valeur augmente quand le cours baisse sous
     * le prix d'entrée, symétrique de la position longue.
     */
    private function equityPoint($date, $equity, $position, $shortEquity, $shortPosition, $close, $buyHold) {
        $strategyEquity = ($position !== null && $position['entry_price'] > 0)
            ? $equity * ($close / $position['entry_price'])
            : $equity;

        $shortStrategyEquity = ($shortPosition !== null && $close > 0)
            ? $shortEquity * ($shortPosition['entry_price'] / $close)
            : $shortEquity;

        return [
            'date' => $date,
            'strategy_equity_base100' => round($strategyEquity * 100, 2),
            'buy_hold_equity_base100' => round($buyHold * 100, 2),
            'short_equity_base100' => round($shortStrategyEquity * 100, 2)
        ];
    }

    private function buildSummary($trades, $shortTrades, $curve, $openPosition, $openShortPosition) {
        $totalTrades = count($trades);
        $winningTrades = count(array_filter($trades, fn($t) => $t['return_percent'] > 0));
        $winRate = $totalTrades > 0 ? round($winningTrades / $totalTrades * 100, 1) : null;
        $avgTradeReturn = $totalTrades > 0
            ? round(array_sum(array_column($trades, 'return_percent')) / $totalTrades, 2)
            : null;

        $shortTotalTrades = count($shortTrades);
        $shortWinningTrades = count(array_filter($shortTrades, fn($t) => $t['return_percent'] > 0));
        $shortWinRate = $shortTotalTrades > 0 ? round($shortWinningTrades / $shortTotalTrades * 100, 1) : null;
        $shortAvgTradeReturn = $shortTotalTrades > 0
            ? round(array_sum(array_column($shortTrades, 'return_percent')) / $shortTotalTrades, 2)
            : null;

        $lastPoint = end($curve) ?: null;
        $strategyReturn = $lastPoint ? round($lastPoint['strategy_equity_base100'] - 100, 2) : null;
        $buyHoldReturn = $lastPoint ? round($lastPoint['buy_hold_equity_base100'] - 100, 2) : null;
        $shortReturn = $lastPoint ? round($lastPoint['short_equity_base100'] - 100, 2) : null;

        return [
            'equity_curve' => $curve,
            'short_trades' => $shortTrades,
            'short_total_trades' => $shortTotalTrades,
            'short_winning_trades' => $shortWinningTrades,
            'short_win_rate_percent' => $shortWinRate,
            'short_avg_trade_return_percent' => $shortAvgTradeReturn,
            'open_short_position' => $openShortPosition,
            'trades' => $trades,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'win_rate_percent' => $winRate,
            'avg_trade_return_percent' => $avgTradeReturn,
            'strategy_return_percent' => $strategyReturn,
            'buy_hold_return_percent' => $buyHoldReturn,
            'short_return_percent' => $shortReturn,
            'open_position' => $openPosition
        ];
    }

    /**
     * Score composite -2..+2 — dupliqué de api_signals.php::buildSignal()
     * (voir note en tête de fichier), sans le contexte liquidité/ATR (pas
     * nécessaire pour rejouer une règle mécanique jour par jour).
     */
    private function computeScore($row) {
        $sum = 0;
        $count = 0;

        if ($row['rsi_14'] !== null) {
            $rsi = (float) $row['rsi_14'];
            $sum += $rsi < 30 ? 1 : ($rsi > 70 ? -1 : 0);
            $count++;
        }

        if ($row['macd_line'] !== null && $row['macd_signal'] !== null) {
            $macdLine = (float) $row['macd_line'];
            $macdSignal = (float) $row['macd_signal'];
            $sum += $macdLine > $macdSignal ? 1 : ($macdLine < $macdSignal ? -1 : 0);
            $count++;
        }

        $sma = $row['sma_20'] !== null ? $row['sma_20'] : $row['sma_10'];
        if ($sma !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            $smaValue = (float) $sma;
            $sum += $close > $smaValue ? 1 : ($close < $smaValue ? -1 : 0);
            $count++;
        }

        if ($row['bb_upper'] !== null && $row['bb_lower'] !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            if ($close < (float) $row['bb_lower']) {
                $sum += 1;
            } elseif ($close > (float) $row['bb_upper']) {
                $sum -= 1;
            }
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return max(-2, min(2, (int) round(($sum / $count) * 2)));
    }
}

// Exécution
$api = new BacktestAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
