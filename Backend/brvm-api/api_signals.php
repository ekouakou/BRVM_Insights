<?php
/**
 * API de signaux composites achat/vente
 * Endpoint: api_signals.php
 *
 * Combine les indicateurs techniques déjà persistés dans `technical_indicators`
 * (RSI, MACD, SMA, Bollinger) en un score simple (-2 à +2) par entreprise, pour
 * une aide à la décision rapide. Ce n'est pas un conseil financier : c'est une
 * synthèse mécanique d'indicateurs techniques classiques.
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

class SignalsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'list':
                    return $this->listSignals($input);

                case 'get':
                    return $this->getSignal($input);

                case 'history':
                    return $this->getSignalHistory($input);

                case 'crossovers':
                    return $this->getCrossovers($input);

                case 'divergence':
                    return $this->getDivergence($input);

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
     * Score composite pour toutes les entreprises actives, à la date la plus
     * récente (ou `date` fournie). Si `start_date` est aussi fourni, chaque
     * entreprise reçoit en plus son score à cette date de début ET
     * l'évolution entre les deux (score_change) — un signal reste une
     * lecture à un instant donné (pas une série sur une période), donc
     * "entre deux dates" se traduit ici par une comparaison de deux
     * instantanés plutôt que par un calcul continu sur toute la plage.
     */
    private function listSignals($input) {
        $date = $input['date'] ?? $this->getLatestIndicatorsDate();
        $startDate = $input['start_date'] ?? null;

        if (!$date) {
            return [
                'success' => true,
                'data' => [],
                'message' => "Aucun indicateur technique calculé pour l'instant. Lancez une synchronisation (api_brvm_sync.php?action=sync_now)."
            ];
        }

        $signals = $this->getSignalsAtDate($date);
        $startDateHasData = null;

        if ($startDate && $startDate !== $date) {
            $startSignals = $this->getSignalsAtDate($startDate);
            $startDateHasData = !empty($startSignals);

            $startSignalsByCompany = [];
            foreach ($startSignals as $s) {
                $startSignalsByCompany[$s['company_id']] = $s;
            }
            foreach ($signals as &$signal) {
                $startSignal = $startSignalsByCompany[$signal['company_id']] ?? null;
                $signal['score_start'] = $startSignal['score'] ?? null;
                $signal['label_start'] = $startSignal['label'] ?? null;
                $signal['score_change'] = ($signal['score'] !== null && $signal['score_start'] !== null)
                    ? $signal['score'] - $signal['score_start']
                    : null;
            }
            unset($signal);
        } else {
            foreach ($signals as &$signal) {
                $signal['score_start'] = null;
                $signal['label_start'] = null;
                $signal['score_change'] = null;
            }
            unset($signal);
        }

        // Les plus forts signaux (achat ou vente) en premier, à la date de fin
        usort($signals, function ($a, $b) {
            return abs($b['score'] ?? 0) <=> abs($a['score'] ?? 0);
        });

        // "Signal (2026-07-31)" toujours vide malgré un start_date valide ?
        // Deux causes distinctes à ne pas confondre côté frontend : (1) la
        // date de début est hors de la plage couverte par technical_indicators
        // (ex: avant le tout premier jour de synchro de l'appli) — signalé
        // ici via earliest_indicators_date pour que l'UI l'explique plutôt
        // que d'afficher silencieusement des "—" ; (2) la date existe mais
        // les indicateurs y sont tous NULL faute d'historique suffisant pour
        // les calculer (RSI-14/SMA ont besoin de N jours de clôtures
        // précédentes) — dans ce cas $startDateHasData est true mais les
        // scores restent "Indéterminé", ce qui est correct, pas un bug.
        $earliestIndicatorsDate = null;
        if ($startDate && $startDateHasData === false) {
            $earliest = $this->crud->executeCustomQuery("SELECT MIN(trading_date) as d FROM technical_indicators");
            $earliestIndicatorsDate = $earliest[0]['d'] ?? null;
        }

        return [
            'success' => true,
            'date' => $date,
            'start_date' => $startDate,
            'start_date_has_data' => $startDateHasData,
            'earliest_indicators_date' => $earliestIndicatorsDate,
            'data' => $signals,
            'count' => count($signals)
        ];
    }

    /**
     * Score composite de toutes les entreprises actives à une date précise —
     * factorisé pour être appelé deux fois par listSignals() (date de début
     * + date de fin) sans dupliquer la requête SQL.
     */
    private function getSignalsAtDate($date) {
        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                s.name AS sector,
                sq.close_price,
                sq.variation_percent,
                ti.rsi_14,
                ti.macd_line,
                ti.macd_signal,
                ti.sma_10,
                ti.sma_20,
                ti.bb_upper,
                ti.bb_lower,
                ti.atr_14
            FROM technical_indicators ti
            INNER JOIN companies c ON c.id = ti.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN stock_quotes sq ON sq.company_id = ti.company_id AND sq.trading_date = ti.trading_date
            WHERE ti.trading_date = ?
            AND c.active = 1
            ORDER BY c.symbol
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$date]) ?: [];
        $liquidityByCompany = $this->getLiquidityByCompany();

        return array_map(
            fn($row) => $this->buildSignal($row, $liquidityByCompany[(int) $row['company_id']] ?? null),
            $rows
        );
    }

    /**
     * Score composite pour une seule entreprise
     */
    private function getSignal($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $date = $input['date'] ?? $this->getLatestIndicatorsDate($companyId);

        if (!$date) {
            throw new Exception("Aucun indicateur technique disponible pour cette entreprise");
        }

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                s.name AS sector,
                sq.close_price,
                sq.variation_percent,
                ti.rsi_14,
                ti.macd_line,
                ti.macd_signal,
                ti.sma_10,
                ti.sma_20,
                ti.bb_upper,
                ti.bb_lower,
                ti.atr_14
            FROM technical_indicators ti
            INNER JOIN companies c ON c.id = ti.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN stock_quotes sq ON sq.company_id = ti.company_id AND sq.trading_date = ti.trading_date
            WHERE ti.company_id = ? AND ti.trading_date = ?
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$companyId, $date]) ?: [];

        if (empty($rows)) {
            throw new Exception("Aucun indicateur technique disponible pour cette entreprise à cette date");
        }

        $liquidityByCompany = $this->getLiquidityByCompany([$companyId]);

        return [
            'success' => true,
            'data' => $this->buildSignal($rows[0], $liquidityByCompany[$companyId] ?? null)
        ];
    }

    /**
     * Historique du score composite pour une entreprise (pour tracer son évolution)
     */
    private function getSignalHistory($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $days = (int)($input['days'] ?? 90);
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                sq.close_price,
                sq.variation_percent,
                ti.trading_date,
                ti.rsi_14,
                ti.macd_line,
                ti.macd_signal,
                ti.sma_10,
                ti.sma_20,
                ti.bb_upper,
                ti.bb_lower
            FROM technical_indicators ti
            INNER JOIN companies c ON c.id = ti.company_id
            LEFT JOIN stock_quotes sq ON sq.company_id = ti.company_id AND sq.trading_date = ti.trading_date
            WHERE ti.company_id = ?
            AND ti.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND ti.trading_date <= ?
            ORDER BY ti.trading_date ASC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$companyId, $endDate, $days, $endDate]) ?: [];

        $history = array_map(function ($row) {
            $signal = $this->buildSignal($row);
            return [
                'date' => $row['trading_date'],
                'close_price' => $row['close_price'],
                'score' => $signal['score'],
                'label' => $signal['label']
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $history,
            'count' => count($history),
            'company_id' => $companyId
        ];
    }

    /**
     * Détecte les croisements de moyennes mobiles (golden cross / death
     * cross) sur une période, à partir des SMA déjà persistées dans
     * technical_indicators — voir TODO_ANALYSES.md, point 14. Un jour où
     * l'une des deux SMA manque (historique encore trop court) est
     * simplement ignoré, sans casser la continuité de comparaison entre le
     * jour précédent valide et le jour suivant valide.
     */
    private function getCrossovers($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $days = (int)($input['days'] ?? 180);

        $sql = "SELECT trading_date, sma_10, sma_20, sma_50 FROM technical_indicators WHERE company_id = ?";
        $params = [$companyId];

        if ($startDate) {
            $sql .= " AND trading_date >= ?";
            $params[] = $startDate;
        } else {
            $sql .= " AND trading_date >= DATE_SUB(?, INTERVAL ? DAY)";
            $params[] = $endDate;
            $params[] = $days;
        }
        $sql .= " AND trading_date <= ? ORDER BY trading_date ASC";
        $params[] = $endDate;

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        // Deux paires suivies : croisement rapide (10/20, plus réactif,
        // plus de faux signaux) et croisement de fond (20/50, plus lent,
        // plus fiable) — le "golden cross" classique le plus cité (50/200)
        // demande un historique que l'application n'a pas encore, ajoutable
        // plus tard sans changement de schéma.
        $pairs = [
            '10/20' => ['fast' => 'sma_10', 'slow' => 'sma_20'],
            '20/50' => ['fast' => 'sma_20', 'slow' => 'sma_50'],
        ];

        $crossovers = [];
        foreach ($pairs as $label => $cols) {
            $prevSign = null;
            foreach ($rows as $row) {
                $fast = $row[$cols['fast']];
                $slow = $row[$cols['slow']];
                if ($fast === null || $slow === null) {
                    continue;
                }

                $diff = (float) $fast - (float) $slow;
                $sign = $diff > 0 ? 1 : ($diff < 0 ? -1 : 0);
                if ($sign === 0) {
                    continue;
                }

                if ($prevSign !== null && $sign !== $prevSign) {
                    $crossovers[] = [
                        'date' => $row['trading_date'],
                        'pair' => $label,
                        'type' => $sign > 0 ? 'golden' : 'death',
                        'fast_value' => round((float) $fast, 4),
                        'slow_value' => round((float) $slow, 4),
                    ];
                }
                $prevSign = $sign;
            }
        }

        usort($crossovers, fn($a, $b) => $a['date'] <=> $b['date']);

        return [
            'success' => true,
            'data' => $crossovers,
            'count' => count($crossovers),
            'company_id' => $companyId
        ];
    }

    /**
     * Détecte les divergences entre le cours et le RSI sur une période —
     * souvent un signal d'essoufflement de tendance plus fiable qu'un
     * simple seuil RSI <30/>70 déjà utilisé dans le score composite (voir
     * TODO_ANALYSES.md, point 15).
     *
     * Méthode : repère d'abord les "pivots" du cours de clôture (un jour
     * est un sommet local si son cours est strictement supérieur à celui
     * des $pivotWindow jours avant ET après, symétriquement pour un creux
     * local), puis compare deux pivots consécutifs de même type :
     *   - Divergence baissière : le cours fait un sommet plus haut que le
     *     précédent, mais le RSI à ce moment-là est plus bas — la hausse de
     *     prix n'est plus confirmée par le momentum, signal d'essoufflement.
     *   - Divergence haussière : le cours fait un creux plus bas que le
     *     précédent, mais le RSI à ce moment-là est plus haut — la baisse
     *     perd de sa force.
     */
    private function getDivergence($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $days = (int)($input['days'] ?? 90);
        $pivotWindow = 3;

        $sql = "
            SELECT sq.trading_date, sq.close_price, ti.rsi_14
            FROM stock_quotes sq
            LEFT JOIN technical_indicators ti
                ON ti.company_id = sq.company_id AND ti.trading_date = sq.trading_date
            WHERE sq.company_id = ?
        ";
        $params = [$companyId];

        if ($startDate) {
            $sql .= " AND sq.trading_date >= ?";
            $params[] = $startDate;
        } else {
            $sql .= " AND sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)";
            $params[] = $endDate;
            $params[] = $days;
        }
        $sql .= " AND sq.trading_date <= ? ORDER BY sq.trading_date ASC";
        $params[] = $endDate;

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];
        $count = count($rows);

        $dates = array_column($rows, 'trading_date');
        $closes = array_map(fn($r) => (float) $r['close_price'], $rows);
        $rsis = array_map(fn($r) => $r['rsi_14'] !== null ? (float) $r['rsi_14'] : null, $rows);

        $pivotHighs = [];
        $pivotLows = [];
        for ($i = $pivotWindow; $i < $count - $pivotWindow; $i++) {
            if ($rsis[$i] === null) {
                continue;
            }

            $isHigh = true;
            $isLow = true;
            for ($j = $i - $pivotWindow; $j <= $i + $pivotWindow; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($closes[$j] >= $closes[$i]) $isHigh = false;
                if ($closes[$j] <= $closes[$i]) $isLow = false;
            }

            if ($isHigh) $pivotHighs[] = $i;
            if ($isLow) $pivotLows[] = $i;
        }

        $divergences = [];

        for ($k = 1; $k < count($pivotHighs); $k++) {
            $prev = $pivotHighs[$k - 1];
            $cur = $pivotHighs[$k];
            if ($rsis[$prev] === null || $rsis[$cur] === null) {
                continue;
            }
            if ($closes[$cur] > $closes[$prev] && $rsis[$cur] < $rsis[$prev]) {
                $divergences[] = [
                    'date' => $dates[$cur],
                    'type' => 'bearish',
                    'previous_date' => $dates[$prev],
                    'price' => round($closes[$cur], 4),
                    'previous_price' => round($closes[$prev], 4),
                    'rsi' => round($rsis[$cur], 4),
                    'previous_rsi' => round($rsis[$prev], 4),
                ];
            }
        }

        for ($k = 1; $k < count($pivotLows); $k++) {
            $prev = $pivotLows[$k - 1];
            $cur = $pivotLows[$k];
            if ($rsis[$prev] === null || $rsis[$cur] === null) {
                continue;
            }
            if ($closes[$cur] < $closes[$prev] && $rsis[$cur] > $rsis[$prev]) {
                $divergences[] = [
                    'date' => $dates[$cur],
                    'type' => 'bullish',
                    'previous_date' => $dates[$prev],
                    'price' => round($closes[$cur], 4),
                    'previous_price' => round($closes[$prev], 4),
                    'rsi' => round($rsis[$cur], 4),
                    'previous_rsi' => round($rsis[$prev], 4),
                ];
            }
        }

        usort($divergences, fn($a, $b) => $a['date'] <=> $b['date']);

        return [
            'success' => true,
            'data' => $divergences,
            'count' => count($divergences),
            'company_id' => $companyId
        ];
    }

    /**
     * Construit le score composite (-2 à +2) et son détail à partir d'une
     * ligne combinant cotation + indicateurs techniques.
     *
     * @param string|null $liquidity Classement de liquidité déjà calculé
     *   (Illiquide/Faible/Moyenne/Élevée, voir getLiquidityByCompany()) —
     *   null si non disponible (ex. entreprise sans historique de volume
     *   suffisant). Voir TODO_ANALYSES.md, point 16.
     */
    private function buildSignal($row, $liquidity = null) {
        $details = [];
        $sum = 0;
        $count = 0;

        // RSI : survendu = signal d'achat, suracheté = signal de vente
        if ($row['rsi_14'] !== null) {
            $rsi = (float) $row['rsi_14'];
            if ($rsi < 30) {
                $details['rsi'] = ['value' => $rsi, 'signal' => 1, 'reason' => 'RSI < 30 (survendu)'];
            } elseif ($rsi > 70) {
                $details['rsi'] = ['value' => $rsi, 'signal' => -1, 'reason' => 'RSI > 70 (suracheté)'];
            } else {
                $details['rsi'] = ['value' => $rsi, 'signal' => 0, 'reason' => 'RSI neutre'];
            }
            $sum += $details['rsi']['signal'];
            $count++;
        }

        // MACD : ligne au-dessus du signal = momentum haussier
        if ($row['macd_line'] !== null && $row['macd_signal'] !== null) {
            $macdLine = (float) $row['macd_line'];
            $macdSignal = (float) $row['macd_signal'];
            if ($macdLine > $macdSignal) {
                $details['macd'] = ['signal' => 1, 'reason' => 'MACD au-dessus de sa ligne de signal'];
            } elseif ($macdLine < $macdSignal) {
                $details['macd'] = ['signal' => -1, 'reason' => 'MACD en-dessous de sa ligne de signal'];
            } else {
                $details['macd'] = ['signal' => 0, 'reason' => 'MACD neutre'];
            }
            $sum += $details['macd']['signal'];
            $count++;
        }

        // Tendance : cours vs moyenne mobile (SMA20 en priorité, sinon SMA10)
        $sma = $row['sma_20'] !== null ? $row['sma_20'] : $row['sma_10'];
        if ($sma !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            $smaValue = (float) $sma;
            if ($close > $smaValue) {
                $details['trend'] = ['signal' => 1, 'reason' => 'Cours au-dessus de sa moyenne mobile'];
            } elseif ($close < $smaValue) {
                $details['trend'] = ['signal' => -1, 'reason' => 'Cours en-dessous de sa moyenne mobile'];
            } else {
                $details['trend'] = ['signal' => 0, 'reason' => 'Cours proche de sa moyenne mobile'];
            }
            $sum += $details['trend']['signal'];
            $count++;
        }

        // Bandes de Bollinger : cours hors bande = potentiel retour à la moyenne
        if ($row['bb_upper'] !== null && $row['bb_lower'] !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            if ($close < (float) $row['bb_lower']) {
                $details['bollinger'] = ['signal' => 1, 'reason' => 'Cours sous la bande basse de Bollinger'];
            } elseif ($close > (float) $row['bb_upper']) {
                $details['bollinger'] = ['signal' => -1, 'reason' => 'Cours au-dessus de la bande haute de Bollinger'];
            } else {
                $details['bollinger'] = ['signal' => 0, 'reason' => 'Cours dans le canal de Bollinger'];
            }
            $sum += $details['bollinger']['signal'];
            $count++;
        }

        $score = null;
        $label = 'Indéterminé';
        $confidencePenalized = false;

        if ($count > 0) {
            $score = (int) round(($sum / $count) * 2);
            $score = max(-2, min(2, $score));

            // Un signal "fort" (±2) sur un titre illiquide (cours figé
            // faute d'acheteur/vendeur) est trompeur : les indicateurs
            // techniques supposent un cours qui reflète l'offre/la demande
            // du jour, pas l'absence de transaction. On ne supprime pas le
            // signal (l'info reste utile), mais on le plafonne à ±1 et on
            // le signale explicitement plutôt que de laisser l'utilisateur
            // croiser manuellement avec le badge liquidité déjà affiché à
            // côté (voir TODO_ANALYSES.md, point 16).
            if ($liquidity === 'Illiquide' && abs($score) === 2) {
                $score = $score > 0 ? 1 : -1;
                $confidencePenalized = true;
            }

            $label = $this->scoreLabel($score);
        }

        // ATR en contexte (jamais utilisé dans le calcul du score
        // lui-même) : situe le signal par rapport à l'agitation récente du
        // titre — un "Achat" sur un titre très volatil (ATR élevé par
        // rapport au cours) mérite plus de prudence qu'un "Achat" sur un
        // titre stable, même à score composite égal.
        $atr = $row['atr_14'] ?? null;
        $closePrice = $row['close_price'] ?? null;
        $atrRelativePercent = ($atr !== null && $closePrice !== null && (float) $closePrice > 0)
            ? round(((float) $atr / (float) $closePrice) * 100, 2)
            : null;

        return [
            'company_id' => $row['company_id'],
            'symbol' => $row['symbol'],
            'name' => $row['name'],
            'sector' => $row['sector'] ?? null,
            'close_price' => $row['close_price'],
            'variation_percent' => $row['variation_percent'],
            'score' => $score,
            'label' => $label,
            'indicators_used' => $count,
            'details' => $details,
            'liquidity' => $liquidity,
            'confidence_penalized_by_liquidity' => $confidencePenalized,
            'atr_14' => $atr !== null ? round((float) $atr, 4) : null,
            'atr_relative_percent' => $atrRelativePercent
        ];
    }

    /**
     * Classement de liquidité par entreprise (Illiquide/Faible/Moyenne/
     * Élevée) sur 30 jours glissants — mêmes seuils et même méthode que
     * api_quotes.php::getLiquidity(), dupliqués ici volontairement plutôt
     * que d'instancier QuotesAPI depuis SignalsAPI (fichiers indépendants,
     * voir le reste du projet) : si les seuils changent un jour, mettre à
     * jour les deux endroits.
     */
    private function getLiquidityByCompany($companyIds = []) {
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
        ";
        $params = [$endDate, $days, $endDate];

        if (!empty($companyIds)) {
            $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
            $sql .= " AND c.id IN ($placeholders)";
            $params = array_merge($params, $companyIds);
        }

        $sql .= " GROUP BY c.id";

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

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
     * Dernière date pour laquelle des indicateurs techniques existent
     */
    private function getLatestIndicatorsDate($companyId = null) {
        if ($companyId) {
            $sql = "SELECT MAX(trading_date) as last_date FROM technical_indicators WHERE company_id = ?";
            $result = $this->crud->executeCustomQuery($sql, [$companyId]);
        } else {
            $sql = "SELECT MAX(trading_date) as last_date FROM technical_indicators";
            $result = $this->crud->executeCustomQuery($sql);
        }

        return $result[0]['last_date'] ?? null;
    }
}

// Exécution
$api = new SignalsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
