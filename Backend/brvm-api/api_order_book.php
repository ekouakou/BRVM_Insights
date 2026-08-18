<?php
/**
 * API du moteur de liquidité & dynamique du carnet d'ordres
 * Endpoint: api_order_book.php — voir TODO_CARNET_ORDRES.md
 *
 * Deux familles de données, jamais mélangées sans étiquette :
 *  - flux d'exécution intraday (intraday_execution_flow — 🟨 calculé depuis
 *    les cumuls observés de brvm.org, séance en cours calculée à la volée) ;
 *  - snapshots du carnet fin de séance (order_book_snapshots — 🟦 observé
 *    dans le Bulletin Officiel de la Cote, parsing déterministe sans IA).
 *
 * Chaque réponse porte la nature de ses chiffres (observe/calcule/estime)
 * — le frontend affiche ces étiquettes, ne jamais présenter une estimation
 * comme un fait (règle §2 du TODO).
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
require_once 'class/BulletinOrderBookService.php';
require_once 'class/ExecutionFlowBuilder.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class OrderBookAPI {
    /** Pondérations du score de liquidité 0-100 (TODO_CARNET_ORDRES.md §4.4). */
    private const SCORE_WEIGHTS = [
        'volume' => 25,       // valeur moyenne échangée / jour (percentile marché)
        'regularite' => 20,   // % de séances avec au moins un échange
        'spread' => 20,       // spread relatif moyen fin de séance
        'profondeur' => 15,   // valeur aux meilleures limites (percentile marché)
        'absorption' => 10,   // exécutions du jour vs offre résiduelle de la veille
        'stabilite' => 10,    // stabilité du spread (écart-type)
    ];
    /** Durée conventionnelle d'une séance BRVM en heures (cotation continue). */
    private const SESSION_HOURS = 4.5;

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['action'])) {
            return ['success' => false, 'message' => 'Action manquante'];
        }

        try {
            switch ($input['action']) {
                case 'parse_bulletins':
                    return $this->parseBulletins($input);
                case 'snapshots':
                    return $this->snapshots($input);
                case 'execution_flow':
                    return $this->executionFlow($input);
                case 'pressure':
                    return $this->pressure($input);
                case 'market_pressure':
                    return $this->marketPressure($input);
                case 'heatmap':
                    return $this->heatmap($input);
                case 'absorption':
                    return $this->absorption($input);
                case 'timeline':
                    return $this->timeline($input);
                case 'liquidity_score':
                    return $this->liquidityScore($input);
                case 'fixing_preview':
                    return $this->fixingPreview($input);
                case 'compare':
                    return $this->compare($input);
                case 'ranking':
                    return $this->ranking($input);
                case 'sell_simulation':
                    return $this->sellSimulation($input);
                case 'anomalies':
                    return $this->anomalies($input);
                default:
                    return ['success' => false, 'message' => 'Action inconnue: ' . $input['action']];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Ingestion
    // ------------------------------------------------------------------

    /** Extraction carnet des bulletins (un seul, ou tous ceux en attente). */
    private function parseBulletins($input) {
        $service = new BulletinOrderBookService($this->crud);
        if (!empty($input['bulletin_id'])) {
            $results = [$service->extract((int) $input['bulletin_id'])];
        } else {
            $results = $service->extractAll(!empty($input['force']));
        }
        $ok = 0;
        $failed = 0;
        $snapshots = 0;
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'success') {
                $ok++;
                $snapshots += $r['snapshots_count'];
            } else {
                $failed++;
            }
        }
        return ['success' => true, 'data' => [
            'bulletins_ok' => $ok,
            'bulletins_failed' => $failed,
            'snapshots_total' => $snapshots,
            'results' => $results,
        ]];
    }

    // ------------------------------------------------------------------
    // Lectures
    // ------------------------------------------------------------------

    /** Série des carnets fin de séance d'une entreprise (🟦 + dérivés 🟨). */
    private function snapshots($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);
        $rows = $this->crud->executeCustomQuery(
            "SELECT s.*, DATE(s.snapshot_datetime) AS snapshot_date
             FROM order_book_snapshots s
             WHERE s.company_id = ? AND DATE(s.snapshot_datetime) BETWEEN ? AND ?
             ORDER BY s.snapshot_datetime",
            [$companyId, $start, $end]
        ) ?: [];

        // Variations 🟨 entre snapshots consécutifs, avec lecture prudente 🟧
        // confrontée aux exécutions observées du jour (jamais « Δ = achats »).
        $execByDate = $this->dailyExecutedMap($companyId, $start, $end);
        $prev = null;
        foreach ($rows as $i => $row) {
            $rows[$i]['delta_bid_qty'] = null;
            $rows[$i]['delta_ask_qty'] = null;
            $rows[$i]['executed_volume_day'] = $execByDate[$row['snapshot_date']] ?? null;
            $rows[$i]['reading'] = null;
            if ($prev !== null) {
                $deltaBid = $this->nullableDelta($row['bid_residual_qty'], $prev['bid_residual_qty']);
                $deltaAsk = $this->nullableDelta($row['ask_residual_qty'], $prev['ask_residual_qty']);
                $rows[$i]['delta_bid_qty'] = $deltaBid;
                $rows[$i]['delta_ask_qty'] = $deltaAsk;
                $rows[$i]['reading'] = $this->prudentReading($deltaAsk, $rows[$i]['executed_volume_day']);
            }
            $prev = $row;
        }

        return ['success' => true, 'data' => [
            'snapshots' => $rows,
            'nature' => [
                'observe' => ['best_bid_price', 'best_ask_price', 'bid_residual_qty', 'ask_residual_qty', 'reference_price'],
                'calcule' => ['spread_abs', 'spread_percent', 'imbalance_ratio', 'delta_bid_qty', 'delta_ask_qty', 'executed_volume_day'],
                'estime' => ['reading'],
            ],
            'source' => 'Bulletin Officiel de la Cote (fin de séance) — meilleures limites uniquement, pas la profondeur complète du carnet',
        ]];
    }

    /**
     * Intervalles d'exécution (période + créneau horaire optionnel). La
     * séance du jour, si elle n'est pas encore consolidée, est calculée à la
     * volée depuis intraday_quotes (et marquée live=1, non persistée).
     */
    private function executionFlow($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);
        $timeStart = $this->validTimeOrNull($input['time_start'] ?? null);
        $timeEnd = $this->validTimeOrNull($input['time_end'] ?? null);

        $sql = "SELECT trading_date, interval_start, interval_end, price_start, price_end,
                       executed_volume, executed_value, price_direction, pressure_side, is_closing_auction
                FROM intraday_execution_flow
                WHERE company_id = ? AND trading_date BETWEEN ? AND ?";
        $params = [$companyId, $start, $end];
        if ($timeStart !== null) {
            $sql .= " AND TIME(interval_end) >= ?";
            $params[] = $timeStart;
        }
        if ($timeEnd !== null) {
            $sql .= " AND TIME(interval_end) <= ?";
            $params[] = $timeEnd;
        }
        $sql .= " ORDER BY interval_end";
        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];
        foreach ($rows as $i => $r) {
            $rows[$i]['live'] = 0;
        }

        $liveDate = $this->liveDateInRange($companyId, $start, $end, $rows);
        if ($liveDate !== null) {
            $builder = new ExecutionFlowBuilder($this->crud);
            $quotes = $this->crud->executeCustomQuery(
                'SELECT quote_datetime, price, volume FROM intraday_quotes
                 WHERE company_id = ? AND DATE(quote_datetime) = ? ORDER BY quote_datetime',
                [$companyId, $liveDate]
            ) ?: [];
            foreach ($builder->computeIntervals($quotes) as $iv) {
                $t = substr($iv['interval_end'], 11, 8);
                if (($timeStart !== null && $t < $timeStart) || ($timeEnd !== null && $t > $timeEnd)) {
                    continue;
                }
                $iv['trading_date'] = $liveDate;
                $iv['live'] = 1;
                $rows[] = $iv;
            }
        }

        return ['success' => true, 'data' => [
            'intervals' => $rows,
            'nature' => [
                'calcule' => ['executed_volume', 'executed_value', 'price_direction'],
                'estime' => ['pressure_side'],
            ],
            'note' => "executed_volume = delta du volume cumulé publié par brvm.org entre deux relevés (~10 min) — des échanges réels. pressure_side est une estimation par tick rule (sens du prix), pas une donnée du carnet.",
        ]];
    }

    /** Agrégats de pression par séance (volumes signés par tick rule 🟧). */
    private function pressure($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);
        $timeStart = $this->validTimeOrNull($input['time_start'] ?? null);
        $timeEnd = $this->validTimeOrNull($input['time_end'] ?? null);

        $sql = "SELECT trading_date,
                       SUM(executed_volume) AS total_volume,
                       SUM(CASE WHEN pressure_side = 'achat' THEN executed_volume ELSE 0 END) AS buy_volume,
                       SUM(CASE WHEN pressure_side = 'vente' THEN executed_volume ELSE 0 END) AS sell_volume,
                       SUM(CASE WHEN pressure_side IS NULL THEN executed_volume ELSE 0 END) AS neutral_volume,
                       SUM(executed_value) AS total_value
                FROM intraday_execution_flow
                WHERE company_id = ? AND trading_date BETWEEN ? AND ?";
        $params = [$companyId, $start, $end];
        if ($timeStart !== null) {
            $sql .= " AND TIME(interval_end) >= ?";
            $params[] = $timeStart;
        }
        if ($timeEnd !== null) {
            $sql .= " AND TIME(interval_end) <= ?";
            $params[] = $timeEnd;
        }
        $sql .= " GROUP BY trading_date ORDER BY trading_date";
        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        foreach ($rows as $i => $r) {
            $buy = (int) $r['buy_volume'];
            $sell = (int) $r['sell_volume'];
            $rows[$i]['net_volume'] = $buy - $sell;
            $rows[$i]['dominant'] = $buy > $sell ? 'achat' : ($sell > $buy ? 'vente' : 'equilibre');
        }

        return ['success' => true, 'data' => [
            'days' => $rows,
            'nature' => ['calcule' => ['total_volume', 'total_value'], 'estime' => ['buy_volume', 'sell_volume', 'net_volume', 'dominant']],
            'note' => "Le sens achat/vente est estimé par tick rule (le volume neutre n'a pas pu être classé) — un indicateur de tendance, pas un décompte d'ordres réels.",
        ]];
    }

    /**
     * Pression acheteur/vendeur du MARCHÉ ENTIER (pas une entreprise), sur
     * une période choisie — agrège les carnets fin de séance (🟦 observé,
     * voir order_book_snapshots) de toutes les entreprises d'un même
     * bulletin : la quantité résiduelle à l'achat de chacune (bid_residual_qty,
     * ce qui reste en attente côté acheteurs à la meilleure limite, non
     * exécuté faute de contrepartie) contre la quantité résiduelle à la
     * vente (ask_residual_qty). Plus la somme achat dépasse la somme vente
     * sur une séance, plus la demande non satisfaite excède l'offre à ces
     * niveaux de prix — et inversement. Contrairement à pressure() (une
     * entreprise, estimé par tick rule sur les exécutions intraday), ceci
     * est 100% observé dans le bulletin, mais UNIQUEMENT sur ce qui reste
     * affiché en fin de séance — pas un décompte de tous les ordres passés
     * dans la journée.
     */
    private function marketPressure($input) {
        list($start, $end) = $this->period($input);

        $rows = $this->crud->executeCustomQuery(
            "SELECT DATE(s.snapshot_datetime) AS snapshot_date,
                    s.bulletin_id,
                    b.title AS bulletin_title,
                    COUNT(*) AS companies_total,
                    SUM(CASE WHEN s.bid_residual_qty IS NULL AND s.ask_residual_qty IS NULL THEN 1 ELSE 0 END) AS companies_no_book,
                    SUM(COALESCE(s.bid_residual_qty, 0)) AS total_bid_qty,
                    SUM(COALESCE(s.ask_residual_qty, 0)) AS total_ask_qty,
                    SUM(CASE WHEN COALESCE(s.bid_residual_qty, 0) > COALESCE(s.ask_residual_qty, 0) THEN 1 ELSE 0 END) AS companies_buyer,
                    SUM(CASE WHEN COALESCE(s.ask_residual_qty, 0) > COALESCE(s.bid_residual_qty, 0) THEN 1 ELSE 0 END) AS companies_seller
             FROM order_book_snapshots s
             LEFT JOIN market_bulletins b ON b.id = s.bulletin_id
             WHERE DATE(s.snapshot_datetime) BETWEEN ? AND ?
             GROUP BY DATE(s.snapshot_datetime), s.bulletin_id, b.title
             ORDER BY snapshot_date",
            [$start, $end]
        ) ?: [];

        foreach ($rows as $i => $r) {
            $bid = (int) $r['total_bid_qty'];
            $ask = (int) $r['total_ask_qty'];
            $rows[$i]['net_qty'] = $bid - $ask;
            $rows[$i]['imbalance_ratio'] = ($bid + $ask) > 0 ? round($bid / ($bid + $ask), 4) : null;
            $rows[$i]['dominant'] = $bid > $ask ? 'acheteur' : ($ask > $bid ? 'vendeur' : 'equilibre');
        }

        $pending = $this->crud->executeCustomQuery(
            "SELECT b.id, b.title, b.publish_date
             FROM market_bulletins b
             JOIN market_bulletin_contents mbc ON mbc.bulletin_id = b.id
             WHERE (mbc.extracted_text IS NOT NULL AND mbc.extracted_text != '')
               AND (mbc.order_book_status IS NULL OR mbc.order_book_status != 'success')
             ORDER BY b.publish_date DESC"
        ) ?: [];

        return ['success' => true, 'data' => [
            'days' => $rows,
            'pending_bulletins' => $pending,
            'pending_count' => count($pending),
            'nature' => ['observe' => ['total_bid_qty', 'total_ask_qty', 'companies_buyer', 'companies_seller', 'imbalance_ratio']],
            'note' => "imbalance_ratio = quantité résiduelle à l'achat / (achat + vente), toutes entreprises confondues pour ce bulletin — 0,5 = équilibre, au-dessus = marché plutôt acheteur, en dessous = marché plutôt vendeur. Basé uniquement sur ce qui reste affiché en carnet fin de séance (pas un décompte de tous les ordres passés dans la journée).",
        ]];
    }

    /** Matrice jour × tranche horaire — volume exécuté et pression nette. */
    private function heatmap($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);
        $slotMinutes = max(10, min(120, (int) ($input['slot_minutes'] ?? 30)));

        $rows = $this->crud->executeCustomQuery(
            "SELECT trading_date,
                    SEC_TO_TIME(FLOOR(TIME_TO_SEC(TIME(interval_end)) / (? * 60)) * ? * 60) AS slot,
                    SUM(executed_volume) AS executed_volume,
                    SUM(CASE WHEN pressure_side = 'achat' THEN executed_volume
                             WHEN pressure_side = 'vente' THEN -executed_volume ELSE 0 END) AS net_pressure
             FROM intraday_execution_flow
             WHERE company_id = ? AND trading_date BETWEEN ? AND ? AND is_closing_auction = 0
             GROUP BY trading_date, slot
             ORDER BY trading_date, slot",
            [$slotMinutes, $slotMinutes, $companyId, $start, $end]
        ) ?: [];

        return ['success' => true, 'data' => [
            'cells' => $rows,
            'slot_minutes' => $slotMinutes,
            'note' => "Cellule absente = aucun échange détecté sur le créneau. Fixing de clôture exclu (pas d'heure fiable). Résolution limitée par l'échantillonnage (~10 min).",
        ]];
    }

    /**
     * Croisement carnet veille × exécutions du jour : taux d'absorption 🟧.
     * Ne conclut JAMAIS « Δ résiduel = achats » — voir prudentReading().
     */
    private function absorption($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);

        $rows = $this->crud->executeCustomQuery(
            "SELECT DATE(s.snapshot_datetime) AS snapshot_date, s.ask_residual_qty, s.bid_residual_qty
             FROM order_book_snapshots s
             WHERE s.company_id = ? AND DATE(s.snapshot_datetime) BETWEEN DATE_SUB(?, INTERVAL 14 DAY) AND ?
             ORDER BY s.snapshot_datetime",
            [$companyId, $start, $end]
        ) ?: [];
        $execByDate = $this->dailyExecutedMap($companyId, $start, $end);

        $days = [];
        $prev = null;
        foreach ($rows as $row) {
            if ($prev !== null && $row['snapshot_date'] >= $start) {
                $offered = $prev['ask_residual_qty'] !== null ? (int) $prev['ask_residual_qty'] : null;
                $executed = $execByDate[$row['snapshot_date']] ?? null;
                $rate = ($offered !== null && $offered > 0 && $executed !== null)
                    ? round($executed / $offered * 100, 1)
                    : null;
                $days[] = [
                    'trading_date' => $row['snapshot_date'],
                    'offered_prev_close' => $offered,
                    'executed_volume' => $executed,
                    'absorption_rate_percent' => $rate,
                    'reading' => $rate === null ? null : ($rate >= 100
                        ? "L'offre affichée la veille a tourné entièrement (voire plusieurs fois) — absorption élevée."
                        : "Les échanges du jour couvrent {$rate}% de l'offre résiduelle de la veille."),
                ];
            }
            $prev = $row;
        }

        return ['success' => true, 'data' => [
            'days' => $days,
            'nature' => ['observe' => ['offered_prev_close', 'executed_volume'], 'estime' => ['absorption_rate_percent', 'reading']],
            'note' => "Le taux compare l'offre résiduelle en clôture de la veille (meilleure limite uniquement) aux échanges observés du jour : une estimation de capacité d'absorption, pas un suivi d'ordres individuels — de nouveaux ordres ont pu arriver en séance.",
        ]];
    }

    /** Timeline fusionnée : exécutions intraday + variations du carnet. */
    private function timeline($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);

        $events = [];
        $flow = $this->crud->executeCustomQuery(
            "SELECT interval_end, executed_volume, price_end, pressure_side, is_closing_auction
             FROM intraday_execution_flow
             WHERE company_id = ? AND trading_date BETWEEN ? AND ?
             ORDER BY interval_end",
            [$companyId, $start, $end]
        ) ?: [];
        foreach ($flow as $f) {
            $events[] = [
                'datetime' => $f['interval_end'],
                'kind' => $f['is_closing_auction'] ? 'cloture' : 'execution',
                'nature' => 'calcule',
                'label' => ((int) $f['executed_volume']) . ' titres échangés'
                    . ($f['is_closing_auction'] ? ' (fixing de clôture)' : '')
                    . ($f['pressure_side'] !== null ? ' — pression ' . $f['pressure_side'] . ' (estimé)' : ''),
                'value' => (int) $f['executed_volume'],
            ];
        }

        $snaps = $this->crud->executeCustomQuery(
            "SELECT snapshot_datetime, bid_residual_qty, ask_residual_qty
             FROM order_book_snapshots
             WHERE company_id = ? AND DATE(snapshot_datetime) BETWEEN DATE_SUB(?, INTERVAL 14 DAY) AND ?
             ORDER BY snapshot_datetime",
            [$companyId, $start, $end]
        ) ?: [];
        $prev = null;
        foreach ($snaps as $s) {
            if ($prev !== null && substr($s['snapshot_datetime'], 0, 10) >= $start) {
                foreach ([['ask_residual_qty', 'à la vente'], ['bid_residual_qty', "à l'achat"]] as $side) {
                    $delta = $this->nullableDelta($s[$side[0]], $prev[$side[0]]);
                    if ($delta !== null && $delta !== 0) {
                        $events[] = [
                            'datetime' => $s['snapshot_datetime'],
                            'kind' => 'carnet',
                            'nature' => 'calcule',
                            'label' => ($delta > 0 ? '+' : '') . $delta . ' titres ' . $side[1] . ' (carnet fin de séance vs veille)',
                            'value' => $delta,
                        ];
                    }
                }
            }
            $prev = $s;
        }

        usort($events, static function ($a, $b) {
            return strcmp($a['datetime'], $b['datetime']);
        });

        return ['success' => true, 'data' => ['events' => $events]];
    }

    /**
     * Score de liquidité 0-100 + réponse à « vendre N actions » 🟧.
     * Renormalisé sur les sous-scores calculables (même philosophie de
     * couverture que api_composite_score.php). Le calcul pur vit dans
     * computeScoreParts()/buildScoreContext(), partagés avec l'action
     * compare (comparateur inter-entreprises).
     */
    private function liquidityScore($input) {
        $companyId = $this->requireCompanyId($input);
        $quantity = isset($input['quantity']) ? max(0, (int) $input['quantity']) : null;

        $ctx = $this->buildScoreContext();
        $parts = $this->computeScoreParts($companyId, $ctx);

        $sellEstimate = null;
        if ($quantity !== null && $quantity > 0) {
            $sellEstimate = $this->sellTimeEstimate($companyId, $quantity);
        }

        return ['success' => true, 'data' => [
            'score' => $parts['score'],
            'coverage_percent' => $parts['coverage'],
            'sub_scores' => $parts['sub'],
            'weights' => self::SCORE_WEIGHTS,
            'details' => $parts['details'],
            'sell_estimate' => $sellEstimate,
            'verdict' => $this->scoreVerdict($parts['score'], $parts['sub']),
            'nature' => ['estime' => ['score', 'sub_scores', 'sell_estimate', 'verdict']],
            'disclaimer' => "Score indicatif calculé sur l'historique récent — pas une garantie d'exécution future. Profondeur = meilleures limites publiées en fin de séance uniquement.",
        ]];
    }

    /**
     * Comparateur inter-entreprises : scores de liquidité + séries
     * quotidiennes (volume exécuté, spread, équilibre du carnet) pour
     * plusieurs entreprises en un seul appel — alimente l'onglet
     * « Liquidité & carnet » de l'écran Comparaison.
     */
    private function compare($input) {
        $ids = [];
        foreach ((array) ($input['company_ids'] ?? []) as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            throw new Exception('company_ids requis (liste non vide)');
        }
        if (count($ids) > 15) {
            throw new Exception('15 entreprises maximum par comparaison');
        }
        list($start, $end) = $this->period($input);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $companies = $this->crud->executeCustomQuery(
            "SELECT id, symbol, name FROM companies WHERE id IN ($placeholders)",
            $ids
        ) ?: [];

        // Scores : le contexte marché (percentiles) est construit UNE fois.
        $ctx = $this->buildScoreContext();
        $scored = [];
        foreach ($companies as $c) {
            $parts = $this->computeScoreParts((int) $c['id'], $ctx);
            $scored[] = [
                'company_id' => (int) $c['id'],
                'symbol' => $c['symbol'],
                'name' => $c['name'],
                'score' => $parts['score'],
                'coverage_percent' => $parts['coverage'],
                'sub_scores' => $parts['sub'],
            ];
        }
        usort($scored, static function ($a, $b) {
            return ($b['score'] ?? -1) <=> ($a['score'] ?? -1);
        });

        // Séries quotidiennes, en deux requêtes groupées.
        $execRows = $this->crud->executeCustomQuery(
            "SELECT company_id, trading_date AS d, SUM(executed_volume) AS v,
                    SUM(CASE WHEN pressure_side = 'achat' THEN executed_volume ELSE 0 END) AS buy_v,
                    SUM(CASE WHEN pressure_side = 'vente' THEN executed_volume ELSE 0 END) AS sell_v
             FROM intraday_execution_flow
             WHERE company_id IN ($placeholders) AND trading_date BETWEEN ? AND ?
             GROUP BY company_id, trading_date",
            array_merge($ids, [$start, $end])
        ) ?: [];
        $snapRows = $this->crud->executeCustomQuery(
            "SELECT company_id, DATE(snapshot_datetime) AS d, spread_percent, imbalance_ratio,
                    bid_residual_qty, ask_residual_qty
             FROM order_book_snapshots
             WHERE company_id IN ($placeholders) AND DATE(snapshot_datetime) BETWEEN ? AND ?",
            array_merge($ids, [$start, $end])
        ) ?: [];

        $byCompany = [];
        foreach ($ids as $id) {
            $byCompany[$id] = [];
        }
        foreach ($execRows as $r) {
            $cid = (int) $r['company_id'];
            $buy = (int) $r['buy_v'];
            $sell = (int) $r['sell_v'];
            $byCompany[$cid][$r['d']]['executed_volume'] = (int) $r['v'];
            $byCompany[$cid][$r['d']]['buy_volume'] = $buy;
            $byCompany[$cid][$r['d']]['sell_volume'] = $sell;
            $byCompany[$cid][$r['d']]['net_pressure'] = $buy - $sell;
            // Pression nette normalisée 🟧 : comparable entre titres de
            // tailles très différentes (-100 = tout vendeur, +100 = tout acheteur).
            $byCompany[$cid][$r['d']]['net_pressure_percent'] = ($buy + $sell) > 0
                ? round(($buy - $sell) / ($buy + $sell) * 100, 1) : null;
        }
        foreach ($snapRows as $r) {
            $cid = (int) $r['company_id'];
            $byCompany[$cid][$r['d']]['spread_percent'] = $r['spread_percent'] !== null ? (float) $r['spread_percent'] : null;
            $byCompany[$cid][$r['d']]['imbalance_ratio'] = $r['imbalance_ratio'] !== null ? (float) $r['imbalance_ratio'] : null;
            $byCompany[$cid][$r['d']]['bid_residual_qty'] = $r['bid_residual_qty'] !== null ? (int) $r['bid_residual_qty'] : null;
            $byCompany[$cid][$r['d']]['ask_residual_qty'] = $r['ask_residual_qty'] !== null ? (int) $r['ask_residual_qty'] : null;
        }

        $series = [];
        foreach ($byCompany as $cid => $days) {
            ksort($days);
            $list = [];
            foreach ($days as $d => $vals) {
                $list[] = array_merge([
                    'date' => $d,
                    'executed_volume' => null,
                    'buy_volume' => null,
                    'sell_volume' => null,
                    'net_pressure' => null,
                    'net_pressure_percent' => null,
                    'spread_percent' => null,
                    'imbalance_ratio' => null,
                    'bid_residual_qty' => null,
                    'ask_residual_qty' => null,
                ], $vals);
            }
            $series[] = ['company_id' => $cid, 'days' => $list];
        }

        return ['success' => true, 'data' => [
            'companies' => $scored,
            'series' => $series,
            'weights' => self::SCORE_WEIGHTS,
            'nature' => [
                'calcule' => ['executed_volume', 'spread_percent', 'imbalance_ratio'],
                'estime' => ['score', 'sub_scores', 'buy_volume', 'sell_volume', 'net_pressure', 'net_pressure_percent'],
            ],
            'note' => "Scores estimés sur l'historique récent (fenêtres 45-90 jours, indépendantes de la période affichée) ; les séries quotidiennes suivent la période sélectionnée.",
        ]];
    }

    /**
     * Simulateur de file d'attente et de stratégie de vente : « si je veux
     * vendre N titres (et que nous sommes plusieurs à vouloir vendre en même
     * temps), où est-ce que je me place, combien de temps j'attends, et que
     * me coûte le fait de passer devant ? »
     *
     * Fondé sur des faits observés : la file devant vous = quantité
     * résiduelle à la vente du dernier carnet publié (BOC), le débit = les
     * exécutions réellement reconstituées, la limite de fluctuation ±7,5 %
     * par séance est confirmée empiriquement sur les cotations en base
     * (maximum observé +7,50 % / -7,47 %).
     *
     * Ce qui reste HORS de portée et n'est jamais simulé : la file en temps
     * réel pendant la séance (le carnet n'est publié qu'en fin de journée),
     * l'horodatage des ordres individuels (la priorité temps entre deux
     * vendeurs au même prix n'est pas observable), et la profondeur au-delà
     * de la meilleure limite.
     */
    private function sellSimulation($input) {
        $companyId = $this->requireCompanyId($input);
        $quantity = max(1, (int) ($input['quantity'] ?? 1000));
        $groupQuantity = isset($input['group_quantity']) ? max(0, (int) $input['group_quantity']) : 0;

        $company = $this->crud->executeCustomQuery(
            'SELECT id, symbol, name FROM companies WHERE id = ?',
            [$companyId]
        );
        if (empty($company)) {
            throw new Exception('Entreprise introuvable');
        }

        // Dernier carnet publié : la file devant vous, telle qu'observée.
        $snap = $this->crud->executeCustomQuery(
            "SELECT DATE(snapshot_datetime) AS snapshot_date, best_bid_price, best_ask_price,
                    bid_at_market, ask_at_market, bid_residual_qty, ask_residual_qty, reference_price,
                    spread_abs, spread_percent
             FROM order_book_snapshots
             WHERE company_id = ? ORDER BY snapshot_datetime DESC LIMIT 1",
            [$companyId]
        );
        $book = !empty($snap) ? $snap[0] : null;

        // Débit d'exécution : médiane des séances actives (mêmes bases que
        // sellTimeEstimate, pour que les deux écrans concordent).
        $volumes = $this->crud->executeCustomQuery(
            "SELECT SUM(executed_volume) AS v FROM intraday_execution_flow
             WHERE company_id = ? AND trading_date >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
             GROUP BY trading_date HAVING v > 0 ORDER BY v",
            [$companyId]
        ) ?: [];
        $dailyVolumes = array_map(static function ($r) {
            return (int) $r['v'];
        }, $volumes);
        $medianDaily = !empty($dailyVolumes) ? $dailyVolumes[(int) floor(count($dailyVolumes) / 2)] : null;

        $tick = $this->tickSize($companyId);
        $queueAhead = ($book !== null && $book['ask_residual_qty'] !== null) ? (int) $book['ask_residual_qty'] : null;
        $bidQty = ($book !== null && $book['bid_residual_qty'] !== null) ? (int) $book['bid_residual_qty'] : null;
        $bestBid = ($book !== null && $book['best_bid_price'] !== null) ? (float) $book['best_bid_price'] : null;
        $bestAsk = ($book !== null && $book['best_ask_price'] !== null) ? (float) $book['best_ask_price'] : null;
        $reference = ($book !== null && $book['reference_price'] !== null) ? (float) $book['reference_price'] : null;

        $sessions = function ($titles) use ($medianDaily) {
            if ($medianDaily === null || $medianDaily <= 0 || $titles === null) {
                return null;
            }
            return round($titles / $medianDaily, 1);
        };

        // --- Position dans la file, toujours renseignée --------------------
        // Cas réel fréquent : le côté vente du carnet est VIDE (aucun
        // vendeur en attente) — c'est la meilleure nouvelle possible pour
        // qui veut vendre, il ne faut surtout pas la masquer parce que le
        // scénario « faire la queue » n'a alors pas de prix de référence.
        if ($book === null) {
            $queuePosition = ['status' => 'inconnu', 'ahead_of_me' => null];
        } elseif ($queueAhead === null || $queueAhead === 0) {
            $queuePosition = ['status' => 'premier', 'ahead_of_me' => 0];
        } else {
            $queuePosition = ['status' => 'derriere', 'ahead_of_me' => $queueAhead];
        }
        $queuePosition['sessions_to_reach_front'] = $sessions($queuePosition['ahead_of_me']);
        $queuePosition['buyers_waiting'] = $bidQty;

        // --- Scénario A : vendre tout de suite en traversant l'écart ------
        // On vend au prix des acheteurs déjà présents : exécution immédiate,
        // mais seulement à hauteur de ce qu'ils demandent, et en abandonnant
        // l'écart demande/offre.
        $scenarioImmediate = null;
        if ($bestBid !== null) {
            $servedNow = ($bidQty !== null) ? min($quantity, $bidQty) : null;
            $costPerShare = ($bestAsk !== null) ? $bestAsk - $bestBid : null;
            $scenarioImmediate = [
                'price' => $bestBid,
                'served_immediately' => $servedNow,
                'remaining_after' => ($servedNow !== null) ? max(0, $quantity - $servedNow) : null,
                'cost_per_share' => $costPerShare,
                'total_cost' => ($costPerShare !== null && $servedNow !== null) ? round($costPerShare * $servedNow) : null,
                'cost_percent' => ($costPerShare !== null && $bestAsk > 0) ? round($costPerShare / $bestAsk * 100, 2) : null,
                'queue_ahead' => 0,
            ];
        }

        // --- Scénario B : se placer au meilleur prix vendeur --------------
        // Vous prenez la file derrière les vendeurs déjà positionnés.
        $scenarioQueue = null;
        if ($bestAsk !== null) {
            $scenarioQueue = [
                'price' => $bestAsk,
                'queue_ahead' => $queueAhead,
                'sessions_to_reach_front' => $sessions($queueAhead),
                'sessions_to_complete' => $sessions(($queueAhead !== null) ? $queueAhead + $quantity : null),
                'cost_per_share' => 0,
                'total_cost' => 0,
            ];
        }

        // --- Scénario C : décoter d'un pas de cotation --------------------
        // Vous passez devant TOUS ceux qui sont au prix supérieur, au prix
        // d'une décote. Vous restez cependant derrière d'éventuels vendeurs
        // déjà placés à ce prix — invisible dans le carnet publié.
        $scenarioUndercut = null;
        if ($bestAsk !== null && $tick !== null && $bestAsk - $tick > 0) {
            $newPrice = $bestAsk - $tick;
            $scenarioUndercut = [
                'price' => $newPrice,
                'jumped_ahead_of' => $queueAhead,
                'cost_per_share' => $tick,
                'total_cost' => round($tick * $quantity),
                'cost_percent' => round($tick / $bestAsk * 100, 2),
                'still_above_bid' => ($bestBid !== null) ? $newPrice > $bestBid : null,
                'sessions_to_complete' => $sessions($quantity),
            ];
        }

        // --- Limite de fluctuation journalière ----------------------------
        // ±7,5 % par séance : au-delà, la cotation est bloquée et PERSONNE
        // ne vend plus, ni vous ni les autres.
        $floorPrice = ($reference !== null) ? round($reference * 0.925, 2) : null;
        $maxUndercutPerShare = ($reference !== null && $bestAsk !== null) ? round($bestAsk - $floorPrice, 2) : null;

        // --- Poids du groupe ----------------------------------------------
        $totalToSell = $groupQuantity > 0 ? $groupQuantity : $quantity;
        $groupImpact = null;
        if ($medianDaily !== null && $medianDaily > 0) {
            $daysOfVolume = round($totalToSell / $medianDaily, 2);
            if ($daysOfVolume >= 2) {
                $level = 'critique';
            } elseif ($daysOfVolume >= 0.5) {
                $level = 'eleve';
            } elseif ($daysOfVolume >= 0.2) {
                $level = 'modere';
            } else {
                $level = 'faible';
            }
            $groupImpact = [
                'total_to_sell' => $totalToSell,
                'median_daily_volume' => $medianDaily,
                'days_of_volume' => $daysOfVolume,
                'percent_of_daily_volume' => round($totalToSell / $medianDaily * 100, 1),
                'level' => $level,
                'my_share_percent' => $totalToSell > 0 ? round($quantity / $totalToSell * 100, 1) : null,
            ];
        }

        // --- Recommandation d'étalement ------------------------------------
        // Une tranche par séance qui reste sous ~20 % du débit habituel :
        // au-delà, le vendeur devient lui-même la cause de la baisse.
        $stagger = null;
        if ($medianDaily !== null && $medianDaily > 0) {
            $tranche = max(1, (int) round($medianDaily * 0.2));
            $stagger = [
                'tranche_per_session' => $tranche,
                'sessions_needed' => (int) ceil($quantity / $tranche),
                'group_sessions_needed' => (int) ceil($totalToSell / $tranche),
                'basis_percent_of_daily_volume' => 20,
            ];
        }

        return ['success' => true, 'data' => [
            'company' => ['company_id' => $companyId, 'symbol' => $company[0]['symbol'], 'name' => $company[0]['name']],
            'quantity' => $quantity,
            'group_quantity' => $groupQuantity,
            'book' => $book !== null ? [
                'snapshot_date' => $book['snapshot_date'],
                'best_bid_price' => $bestBid,
                'best_ask_price' => $bestAsk,
                'bid_residual_qty' => $bidQty,
                'ask_residual_qty' => $queueAhead,
                'reference_price' => $reference,
                'spread_percent' => $book['spread_percent'] !== null ? (float) $book['spread_percent'] : null,
                'bid_at_market' => (int) $book['bid_at_market'],
                'ask_at_market' => (int) $book['ask_at_market'],
            ] : null,
            'tick_size' => $tick,
            'median_daily_volume' => $medianDaily,
            'active_days_basis' => count($dailyVolumes),
            'queue_position' => $queuePosition,
            'scenarios' => [
                'immediate' => $scenarioImmediate,
                'queue' => $scenarioQueue,
                'undercut' => $scenarioUndercut,
            ],
            'daily_limit' => [
                'percent' => 7.5,
                'floor_price' => $floorPrice,
                'max_undercut_per_share' => $maxUndercutPerShare,
            ],
            'group_impact' => $groupImpact,
            'stagger' => $stagger,
            'nature' => [
                'observe' => ['book', 'tick_size'],
                'calcule' => ['median_daily_volume', 'cost_per_share', 'total_cost', 'days_of_volume'],
                'estime' => ['sessions_to_reach_front', 'sessions_to_complete', 'stagger', 'served_immediately'],
            ],
            'limits' => [
                "Le carnet n'est publié qu'en fin de séance : la file affichée date du " . ($book !== null ? $book['snapshot_date'] : 'dernier bulletin traité') . ", elle a pu changer depuis.",
                "Seule la meilleure limite est publiée : d'autres vendeurs peuvent attendre à des prix supérieurs, invisibles ici.",
                "L'ordre d'arrivée des ordres au même prix n'est pas observable — impossible de dire qui, de vous ou d'un autre vendeur au même prix, passera devant.",
                "Les ordres « au marché » (sans prix limite) passent avant tous les ordres à cours limité, y compris le vôtre.",
            ],
        ]];
    }

    /**
     * Pas de cotation d'un titre : plus petit écart de prix réellement
     * observé dans ses carnets (le pas dépend du niveau de cours à la
     * BRVM). Repli sur 5 F, plus petit pas constaté sur le marché.
     */
    private function tickSize(int $companyId) {
        $rows = $this->crud->executeCustomQuery(
            "SELECT best_bid_price, best_ask_price, reference_price
             FROM order_book_snapshots WHERE company_id = ?",
            [$companyId]
        ) ?: [];

        $prices = [];
        foreach ($rows as $r) {
            foreach (['best_bid_price', 'best_ask_price', 'reference_price'] as $col) {
                if ($r[$col] !== null) {
                    $prices[] = (float) $r[$col];
                }
            }
        }
        $prices = array_values(array_unique($prices));
        sort($prices);
        $minDiff = null;
        for ($i = 1; $i < count($prices); $i++) {
            $diff = round($prices[$i] - $prices[$i - 1], 2);
            if ($diff > 0 && ($minDiff === null || $diff < $minDiff)) {
                $minDiff = $diff;
            }
        }
        return $minDiff !== null ? $minDiff : 5.0;
    }

    /**
     * Classement de TOUTES les entreprises actives sur les dimensions du
     * moteur de liquidité (écran Classements) : facilité de vente (score),
     * pression vendeuse / acheteuse, offre et demande en attente, volume
     * exécuté, spread. Une seule requête pour tout le marché — les
     * agrégats sont calculés en SQL, le tri final côté PHP.
     */
    private function ranking($input) {
        list($start, $end) = $this->period($input);

        $companies = $this->crud->executeCustomQuery(
            "SELECT id AS company_id, symbol, name FROM companies WHERE active = 1 ORDER BY symbol"
        ) ?: [];
        if (empty($companies)) {
            return ['success' => true, 'data' => ['rows' => [], 'weights' => self::SCORE_WEIGHTS]];
        }

        // Flux d'exécution sur la période (volumes + sens estimé par tick rule).
        $flow = $this->crud->executeCustomQuery(
            "SELECT company_id,
                    SUM(executed_volume) AS executed_volume,
                    SUM(executed_value) AS executed_value,
                    SUM(CASE WHEN pressure_side = 'achat' THEN executed_volume ELSE 0 END) AS buy_volume,
                    SUM(CASE WHEN pressure_side = 'vente' THEN executed_volume ELSE 0 END) AS sell_volume,
                    COUNT(DISTINCT trading_date) AS active_days
             FROM intraday_execution_flow
             WHERE trading_date BETWEEN ? AND ?
             GROUP BY company_id",
            [$start, $end]
        ) ?: [];
        $flowBy = [];
        foreach ($flow as $f) {
            $flowBy[(int) $f['company_id']] = $f;
        }

        // Carnet : moyennes de la période + dernier carnet connu.
        $book = $this->crud->executeCustomQuery(
            "SELECT company_id,
                    AVG(bid_residual_qty) AS avg_bid_qty,
                    AVG(ask_residual_qty) AS avg_ask_qty,
                    AVG(spread_percent) AS avg_spread_percent,
                    AVG(imbalance_ratio) AS avg_imbalance,
                    COUNT(*) AS snapshots
             FROM order_book_snapshots
             WHERE DATE(snapshot_datetime) BETWEEN ? AND ?
             GROUP BY company_id",
            [$start, $end]
        ) ?: [];
        $bookBy = [];
        foreach ($book as $b) {
            $bookBy[(int) $b['company_id']] = $b;
        }

        $lastBook = $this->crud->executeCustomQuery(
            "SELECT s.company_id, DATE(s.snapshot_datetime) AS snapshot_date,
                    s.bid_residual_qty, s.ask_residual_qty, s.spread_percent, s.imbalance_ratio
             FROM order_book_snapshots s
             JOIN (SELECT company_id, MAX(snapshot_datetime) AS m FROM order_book_snapshots GROUP BY company_id) t
               ON t.company_id = s.company_id AND t.m = s.snapshot_datetime"
        ) ?: [];
        $lastBy = [];
        foreach ($lastBook as $l) {
            $lastBy[(int) $l['company_id']] = $l;
        }

        // Volume ÉCHANGÉ de la dernière séance cotée — à ne pas confondre
        // avec la quantité résiduelle à la vente ci-dessus : l'un compte des
        // transactions conclues, l'autre des ordres encore en attente.
        $lastSession = $this->crud->executeCustomQuery(
            "SELECT q.company_id, q.volume, q.trading_date
             FROM stock_quotes q
             JOIN (SELECT company_id, MAX(trading_date) AS m FROM stock_quotes GROUP BY company_id) t
               ON t.company_id = q.company_id AND t.m = q.trading_date"
        ) ?: [];
        $sessionBy = [];
        foreach ($lastSession as $s) {
            $sessionBy[(int) $s['company_id']] = $s;
        }

        $ctx = $this->buildScoreContext();

        $rows = [];
        foreach ($companies as $c) {
            $id = (int) $c['company_id'];
            $f = $flowBy[$id] ?? null;
            $b = $bookBy[$id] ?? null;
            $l = $lastBy[$id] ?? null;
            $ls = $sessionBy[$id] ?? null;
            $parts = $this->computeScoreParts($id, $ctx);

            $buy = $f !== null ? (int) $f['buy_volume'] : 0;
            $sell = $f !== null ? (int) $f['sell_volume'] : 0;
            $classified = $buy + $sell;

            $rows[] = [
                'company_id' => $id,
                'symbol' => $c['symbol'],
                'name' => $c['name'],
                // Facilité de vente 🟧
                'liquidity_score' => $parts['score'],
                'coverage_percent' => $parts['coverage'],
                'sub_scores' => $parts['sub'],
                // Flux 🟨 / pression 🟧
                'executed_volume' => $f !== null ? (int) $f['executed_volume'] : 0,
                'executed_value' => $f !== null ? (float) $f['executed_value'] : 0,
                'active_days' => $f !== null ? (int) $f['active_days'] : 0,
                'buy_volume' => $buy,
                'sell_volume' => $sell,
                'net_pressure' => $buy - $sell,
                // Part du volume classé qui est à l'initiative des vendeurs 🟧
                'sell_pressure_percent' => $classified > 0 ? round($sell / $classified * 100, 1) : null,
                'buy_pressure_percent' => $classified > 0 ? round($buy / $classified * 100, 1) : null,
                // Carnet 🟦 / 🟨
                'avg_ask_qty' => $b !== null && $b['avg_ask_qty'] !== null ? round((float) $b['avg_ask_qty']) : null,
                'avg_bid_qty' => $b !== null && $b['avg_bid_qty'] !== null ? round((float) $b['avg_bid_qty']) : null,
                'avg_spread_percent' => $b !== null && $b['avg_spread_percent'] !== null ? round((float) $b['avg_spread_percent'], 3) : null,
                'avg_imbalance' => $b !== null && $b['avg_imbalance'] !== null ? round((float) $b['avg_imbalance'], 4) : null,
                'last_book_date' => $l !== null ? $l['snapshot_date'] : null,
                'last_bid_qty' => $l !== null && $l['bid_residual_qty'] !== null ? (int) $l['bid_residual_qty'] : null,
                'last_ask_qty' => $l !== null && $l['ask_residual_qty'] !== null ? (int) $l['ask_residual_qty'] : null,
                // Transactions conclues lors de la dernière séance cotée
                // (à distinguer de last_ask_qty, des ordres en attente).
                'last_session_volume' => $ls !== null && $ls['volume'] !== null ? (int) $ls['volume'] : null,
                'last_session_date' => $ls !== null ? $ls['trading_date'] : null,
            ];
        }

        return ['success' => true, 'data' => [
            'rows' => $rows,
            'weights' => self::SCORE_WEIGHTS,
            'period' => ['start_date' => $start, 'end_date' => $end],
            'nature' => [
                'observe' => ['avg_ask_qty', 'avg_bid_qty', 'last_bid_qty', 'last_ask_qty'],
                'calcule' => ['executed_volume', 'executed_value', 'avg_spread_percent', 'avg_imbalance'],
                'estime' => ['liquidity_score', 'buy_volume', 'sell_volume', 'net_pressure', 'sell_pressure_percent', 'buy_pressure_percent'],
            ],
            'note' => "Le score de liquidité est calculé sur l'historique récent (45-90 jours), indépendamment de la période choisie ; les volumes, pressions et moyennes de carnet suivent la période. Le sens acheteur/vendeur est estimé par tick rule — un titre dont tous les échanges se font à prix inchangé n'a pas de pression classable.",
        ]];
    }

    /**
     * Contexte marché partagé du score : distributions servant aux
     * percentiles (valeur échangée, profondeur) — construit une seule fois
     * même quand on score plusieurs entreprises.
     */
    private function buildScoreContext(): array {
        $ctx = ['turnover' => [], 'turnover_values' => [], 'depth' => [], 'depth_values' => [], 'market_days' => 0];

        $turnovers = $this->crud->executeCustomQuery(
            "SELECT company_id, AVG(turnover) AS avg_turnover
             FROM stock_quotes
             WHERE trading_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             GROUP BY company_id"
        ) ?: [];
        foreach ($turnovers as $t) {
            $v = (float) $t['avg_turnover'];
            $ctx['turnover'][(int) $t['company_id']] = $v;
            $ctx['turnover_values'][] = $v;
        }

        $depths = $this->crud->executeCustomQuery(
            "SELECT company_id,
                    AVG(COALESCE(bid_residual_qty,0) * COALESCE(best_bid_price, reference_price, 0)
                      + COALESCE(ask_residual_qty,0) * COALESCE(best_ask_price, reference_price, 0)) AS avg_depth
             FROM order_book_snapshots
             WHERE snapshot_datetime >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             GROUP BY company_id"
        ) ?: [];
        foreach ($depths as $d) {
            $v = (float) $d['avg_depth'];
            $ctx['depth'][(int) $d['company_id']] = $v;
            $ctx['depth_values'][] = $v;
        }

        $md = $this->crud->executeCustomQuery(
            "SELECT COUNT(DISTINCT trading_date) AS n FROM stock_quotes
             WHERE trading_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
        );
        $ctx['market_days'] = !empty($md) ? (int) $md[0]['n'] : 0;

        return $ctx;
    }

    /** Sous-scores + score pondéré d'une entreprise (voir SCORE_WEIGHTS). */
    private function computeScoreParts(int $companyId, array $ctx): array {
        $sub = [];
        $details = [];

        // 1. Volume : percentile marché de la valeur moyenne échangée / jour
        if (isset($ctx['turnover'][$companyId]) && count($ctx['turnover_values']) > 1) {
            $mine = $ctx['turnover'][$companyId];
            $sub['volume'] = $this->percentile($ctx['turnover_values'], $mine);
            $details['volume'] = ['avg_daily_value_fcfa' => round($mine), 'basis' => 'percentile marché, 90 derniers jours'];
        } else {
            $sub['volume'] = null;
        }

        // 2. Régularité : % de séances du marché où le titre a échangé
        $reg = $this->crud->executeCustomQuery(
            "SELECT COUNT(*) AS active_days FROM stock_quotes
             WHERE company_id = ? AND volume > 0 AND trading_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)",
            [$companyId]
        );
        $activeDays = !empty($reg) ? (int) $reg[0]['active_days'] : 0;
        $sub['regularite'] = $ctx['market_days'] > 0 ? round($activeDays / $ctx['market_days'] * 100, 1) : null;
        $details['regularite'] = ['active_days' => $activeDays, 'market_days' => $ctx['market_days']];

        // 3 & 6. Spread moyen et stabilité (snapshots BOC)
        $spreadRows = $this->crud->executeCustomQuery(
            "SELECT spread_percent FROM order_book_snapshots
             WHERE company_id = ? AND spread_percent IS NOT NULL
             AND snapshot_datetime >= DATE_SUB(NOW(), INTERVAL 60 DAY)",
            [$companyId]
        ) ?: [];
        $spreads = array_map(static function ($r) {
            return (float) $r['spread_percent'];
        }, $spreadRows);
        if (count($spreads) >= 2) {
            $avgSpread = array_sum($spreads) / count($spreads);
            $sub['spread'] = $this->clamp(100 - $avgSpread * 20); // 0% → 100 ; ≥5% → 0
            $variance = 0.0;
            foreach ($spreads as $sp) {
                $variance += ($sp - $avgSpread) * ($sp - $avgSpread);
            }
            $std = sqrt($variance / count($spreads));
            $sub['stabilite'] = $this->clamp(100 - $std * 50); // écart-type ≥2 pts → 0
            $details['spread'] = ['avg_spread_percent' => round($avgSpread, 2), 'snapshots' => count($spreads)];
            $details['stabilite'] = ['spread_std' => round($std, 2)];
        } else {
            $sub['spread'] = null;
            $sub['stabilite'] = null;
        }

        // 4. Profondeur : percentile marché de la valeur aux meilleures limites
        if (isset($ctx['depth'][$companyId]) && count($ctx['depth_values']) > 1) {
            $sub['profondeur'] = $this->percentile($ctx['depth_values'], $ctx['depth'][$companyId]);
            $details['profondeur'] = ['avg_best_limits_value_fcfa' => round($ctx['depth'][$companyId])];
        } else {
            $sub['profondeur'] = null;
        }

        // 5. Absorption : moyenne des min(1, exécuté J / offre résiduelle J-1)
        $absorptionRates = $this->absorptionRates($companyId, 60);
        if (count($absorptionRates) >= 3) {
            $sub['absorption'] = round(min(100, array_sum($absorptionRates) / count($absorptionRates)), 1);
            $details['absorption'] = ['days_measured' => count($absorptionRates)];
        } else {
            $sub['absorption'] = null;
        }

        // Score pondéré, renormalisé sur les dimensions disponibles
        $weighted = 0.0;
        $weightUsed = 0;
        foreach (self::SCORE_WEIGHTS as $key => $weight) {
            if ($sub[$key] !== null) {
                $weighted += $sub[$key] * $weight;
                $weightUsed += $weight;
            }
        }

        return [
            'score' => $weightUsed > 0 ? round($weighted / $weightUsed, 1) : null,
            'coverage' => $weightUsed,
            'sub' => $sub,
            'details' => $details,
        ];
    }


    /**
     * Données RÉELLES autour du fixing de clôture, pour le panneau
     * pédagogique de l'onglet « Flux & pression » : dernier fixing observé
     * (volume exécuté à l'enchère, prix de clôture officiel, part du volume
     * du jour) + carnet résiduel après l'enchère = le point de départ de la
     * PROCHAINE séance (« une longueur d'avance »). La grille complète des
     * prix testés par la bourse n'étant pas publiée, on ne la reconstitue
     * jamais — on montre ce qui est observable, étiqueté comme d'habitude.
     */
    private function fixingPreview($input) {
        $companyId = $this->requireCompanyId($input);

        // Dernier fixing détecté : l'écart entre le cumul intraday et le
        // volume officiel du jour (intervalle synthétique is_closing_auction).
        $fix = $this->crud->executeCustomQuery(
            "SELECT trading_date, executed_volume FROM intraday_execution_flow
             WHERE company_id = ? AND is_closing_auction = 1
             ORDER BY trading_date DESC LIMIT 1",
            [$companyId]
        );
        $lastFixing = null;
        if (!empty($fix)) {
            $fixDate = $fix[0]['trading_date'];
            $day = $this->crud->executeCustomQuery(
                "SELECT SUM(executed_volume) AS day_volume FROM intraday_execution_flow
                 WHERE company_id = ? AND trading_date = ?",
                [$companyId, $fixDate]
            );
            $official = $this->crud->executeCustomQuery(
                "SELECT close_price, volume FROM stock_quotes WHERE company_id = ? AND trading_date = ?",
                [$companyId, $fixDate]
            );
            $dayVolume = !empty($day) ? (int) $day[0]['day_volume'] : null;
            $fixVolume = (int) $fix[0]['executed_volume'];
            $lastFixing = [
                'trading_date' => $fixDate,
                'fixing_volume' => $fixVolume,                    // 🟨 calculé (volume officiel - cumul relevés)
                'fixing_price' => !empty($official) && $official[0]['close_price'] !== null
                    ? (float) $official[0]['close_price'] : null, // 🟦 cours de clôture officiel
                'day_volume' => $dayVolume,                       // 🟨
                'fixing_share_percent' => ($dayVolume !== null && $dayVolume > 0)
                    ? round($fixVolume / $dayVolume * 100, 1) : null, // 🟨
            ];
            // Honnêteté : quand l'écart représente presque tout le volume du
            // jour, nos relevés n'ont couvert qu'une fraction de la séance —
            // ce chiffre inclut alors bien plus que la seule enchère de
            // clôture, il ne faut pas le présenter comme « le fixing ».
            $lastFixing['coverage_caveat'] = $lastFixing['fixing_share_percent'] !== null
                && $lastFixing['fixing_share_percent'] >= 90;
        }

        // Carnet résiduel le plus récent = ce qui attend déjà pour la
        // prochaine séance (les ordres non servis restent au carnet).
        $snap = $this->crud->executeCustomQuery(
            "SELECT DATE(snapshot_datetime) AS snapshot_date, best_bid_price, best_ask_price,
                    bid_at_market, ask_at_market, bid_residual_qty, ask_residual_qty,
                    reference_price, spread_percent, imbalance_ratio
             FROM order_book_snapshots
             WHERE company_id = ? ORDER BY snapshot_datetime DESC LIMIT 1",
            [$companyId]
        );
        $residual = !empty($snap) ? $snap[0] : null;

        $nextSession = null;
        if ($residual !== null) {
            $nextSession = [
                'reference_price' => $residual['reference_price'],       // 🟦
                'spread_percent' => $residual['spread_percent'],         // 🟨
                'imbalance_ratio' => $residual['imbalance_ratio'],       // 🟨
                // 🟧 capacité immédiate : ce qu'un ordre « au marché » trouverait
                // en face à l'ouverture, si le carnet n'a pas bougé entre temps.
                'immediate_sell_capacity' => $residual['bid_residual_qty'] !== null ? [
                    'qty' => (int) $residual['bid_residual_qty'],
                    'price' => $residual['best_bid_price'],
                    'at_market' => (int) $residual['bid_at_market'],
                ] : null,
                'immediate_buy_capacity' => $residual['ask_residual_qty'] !== null ? [
                    'qty' => (int) $residual['ask_residual_qty'],
                    'price' => $residual['best_ask_price'],
                    'at_market' => (int) $residual['ask_at_market'],
                ] : null,
            ];
        }

        return ['success' => true, 'data' => [
            'last_fixing' => $lastFixing,
            'residual_book' => $residual,
            'same_date' => $lastFixing !== null && $residual !== null
                && $lastFixing['trading_date'] === $residual['snapshot_date'],
            'next_session' => $nextSession,
            'note' => "Aucun fixing distinct n'apparaît quand nos relevés intraday couvraient déjà tout le volume du jour (écart nul). La grille des prix testés par l'enchère n'est pas publiée par la BRVM — seuls le résultat (prix, volume) et le carnet résiduel le sont.",
        ]];
    }

    /** Anomalies : z-score sur volume quotidien, spread et résiduels. */
    private function anomalies($input) {
        $companyId = $this->requireCompanyId($input);
        list($start, $end) = $this->period($input);
        $threshold = max(1.5, min(4, (float) ($input['z'] ?? 2)));

        $found = [];
        $series = [
            ['sql' => "SELECT trading_date AS d, SUM(executed_volume) AS v FROM intraday_execution_flow
                       WHERE company_id = ? GROUP BY trading_date ORDER BY d",
             'label' => 'Volume exécuté', 'unit' => 'titres'],
            ['sql' => "SELECT DATE(snapshot_datetime) AS d, ask_residual_qty AS v FROM order_book_snapshots
                       WHERE company_id = ? AND ask_residual_qty IS NOT NULL ORDER BY d",
             'label' => 'Offre résiduelle à la vente', 'unit' => 'titres'],
            ['sql' => "SELECT DATE(snapshot_datetime) AS d, bid_residual_qty AS v FROM order_book_snapshots
                       WHERE company_id = ? AND bid_residual_qty IS NOT NULL ORDER BY d",
             'label' => "Demande résiduelle à l'achat", 'unit' => 'titres'],
            ['sql' => "SELECT DATE(snapshot_datetime) AS d, spread_percent AS v FROM order_book_snapshots
                       WHERE company_id = ? AND spread_percent IS NOT NULL ORDER BY d",
             'label' => 'Spread', 'unit' => '%'],
        ];
        foreach ($series as $serie) {
            $rows = $this->crud->executeCustomQuery($serie['sql'], [$companyId]) ?: [];
            if (count($rows) < 5) {
                continue;
            }
            $values = array_map(static function ($r) {
                return (float) $r['v'];
            }, $rows);
            $mean = array_sum($values) / count($values);
            $variance = 0.0;
            foreach ($values as $v) {
                $variance += ($v - $mean) * ($v - $mean);
            }
            $std = sqrt($variance / count($values));
            if ($std <= 0) {
                continue;
            }
            foreach ($rows as $i => $r) {
                if ($r['d'] < $start || $r['d'] > $end) {
                    continue;
                }
                $z = ($values[$i] - $mean) / $std;
                if (abs($z) >= $threshold) {
                    $found[] = [
                        'date' => $r['d'],
                        'metric' => $serie['label'],
                        'value' => $values[$i],
                        'unit' => $serie['unit'],
                        'average' => round($mean, 1),
                        'z_score' => round($z, 2),
                        'direction' => $z > 0 ? 'hausse' : 'baisse',
                    ];
                }
            }
        }
        usort($found, static function ($a, $b) {
            return strcmp($b['date'], $a['date']) ?: (abs($b['z_score']) <=> abs($a['z_score']));
        });

        return ['success' => true, 'data' => [
            'anomalies' => $found,
            'threshold' => $threshold,
            'note' => "Écarts statistiques (z-score ≥ $threshold) par rapport à la moyenne de l'historique du titre — des points à examiner, pas des alertes de trading.",
        ]];
    }

    // ------------------------------------------------------------------
    // Aides
    // ------------------------------------------------------------------

    private function requireCompanyId($input) {
        $id = (int) ($input['company_id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('company_id requis');
        }
        return $id;
    }

    private function period($input) {
        $start = $this->validDate($input['start_date'] ?? null) ?: date('Y-m-d', strtotime('-30 days'));
        $end = $this->validDate($input['end_date'] ?? null) ?: date('Y-m-d');
        if ($start > $end) {
            throw new Exception('start_date doit précéder end_date');
        }
        return [$start, $end];
    }

    private function validDate($d) {
        return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    }

    private function validTimeOrNull($t) {
        if (!is_string($t) || $t === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
            return strlen($t) === 5 ? $t . ':00' : $t;
        }
        return null;
    }

    private function nullableDelta($cur, $prev) {
        if ($cur === null || $prev === null) {
            return null;
        }
        return (int) $cur - (int) $prev;
    }

    /**
     * Lecture PRUDENTE d'une baisse d'offre résiduelle : confrontée aux
     * exécutions observées — jamais « -500 = 500 achats » (règle §2).
     */
    private function prudentReading($deltaAsk, $executedDay) {
        if ($deltaAsk === null) {
            return null;
        }
        if ($deltaAsk > 0) {
            return "+" . $deltaAsk . " titres à la vente vs veille : nouveaux ordres de vente probables (ou retours d'ordres modifiés).";
        }
        if ($deltaAsk === 0) {
            return "Offre résiduelle inchangée vs veille.";
        }
        $drop = -$deltaAsk;
        if ($executedDay !== null && $executedDay >= $drop) {
            return "-$drop titres à la vente vs veille — compatible avec une absorption par le marché ($executedDay titres échangés ce jour). Une part d'annulations reste possible.";
        }
        if ($executedDay !== null) {
            return "-$drop titres à la vente vs veille, mais seulement $executedDay titres échangés : annulations ou modifications d'ordres probables pour le surplus.";
        }
        return "-$drop titres à la vente vs veille — volume exécuté du jour inconnu, exécution et annulations indistinguables.";
    }

    /** @return array<string,int> date => volume exécuté total (flux consolidé) */
    private function dailyExecutedMap($companyId, $start, $end) {
        $rows = $this->crud->executeCustomQuery(
            "SELECT trading_date, SUM(executed_volume) AS v
             FROM intraday_execution_flow
             WHERE company_id = ? AND trading_date BETWEEN ? AND ?
             GROUP BY trading_date",
            [$companyId, $start, $end]
        ) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[$r['trading_date']] = (int) $r['v'];
        }
        return $map;
    }

    /** Taux d'absorption quotidiens (%) plafonnés à 100, sur N jours. */
    private function absorptionRates($companyId, $days) {
        $rows = $this->crud->executeCustomQuery(
            "SELECT DATE(snapshot_datetime) AS d, ask_residual_qty
             FROM order_book_snapshots
             WHERE company_id = ? AND snapshot_datetime >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY snapshot_datetime",
            [$companyId, $days]
        ) ?: [];
        $exec = $this->dailyExecutedMap($companyId, date('Y-m-d', strtotime("-$days days")), date('Y-m-d'));
        $rates = [];
        $prev = null;
        foreach ($rows as $r) {
            if ($prev !== null && $prev['ask_residual_qty'] !== null && (int) $prev['ask_residual_qty'] > 0
                && isset($exec[$r['d']])) {
                $rates[] = min(100, $exec[$r['d']] / (int) $prev['ask_residual_qty'] * 100);
            }
            $prev = $r;
        }
        return $rates;
    }

    /** Estimation 🟧 du temps pour vendre N actions, formule affichée. */
    private function sellTimeEstimate($companyId, $quantity) {
        $rows = $this->crud->executeCustomQuery(
            "SELECT SUM(executed_volume) AS v FROM intraday_execution_flow
             WHERE company_id = ? AND trading_date >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
             GROUP BY trading_date HAVING v > 0 ORDER BY v",
            [$companyId]
        ) ?: [];
        if (count($rows) < 3) {
            return [
                'quantity' => $quantity,
                'estimate' => null,
                'reason' => "Pas assez de séances actives récentes pour estimer un débit d'exécution.",
            ];
        }
        $volumes = array_map(static function ($r) {
            return (int) $r['v'];
        }, $rows);
        $median = $volumes[(int) floor(count($volumes) / 2)];
        $perHour = $median / self::SESSION_HOURS;
        $hours = $perHour > 0 ? $quantity / $perHour : null;

        // Contexte carnet : dernière demande résiduelle connue
        $lastSnap = $this->crud->executeCustomQuery(
            "SELECT DATE(snapshot_datetime) AS d, bid_residual_qty FROM order_book_snapshots
             WHERE company_id = ? ORDER BY snapshot_datetime DESC LIMIT 1",
            [$companyId]
        );

        return [
            'quantity' => $quantity,
            'median_daily_volume' => $median,
            'active_days_basis' => count($volumes),
            'estimated_hours' => $hours !== null ? round($hours, 1) : null,
            'estimated_sessions' => $hours !== null ? round($hours / self::SESSION_HOURS, 1) : null,
            'last_bid_residual' => !empty($lastSnap) && $lastSnap[0]['bid_residual_qty'] !== null
                ? ['date' => $lastSnap[0]['d'], 'qty' => (int) $lastSnap[0]['bid_residual_qty']] : null,
            'formula' => "N / (volume médian des séances actives sur 45 j ÷ " . self::SESSION_HOURS . " h de séance) — suppose que votre ordre n'altère pas le marché, ce qui est optimiste pour les grosses quantités.",
        ];
    }

    private function scoreVerdict($score, $sub) {
        if ($score === null) {
            return "Données insuffisantes pour un verdict de liquidité.";
        }
        $level = $score >= 70 ? 'élevée' : ($score >= 45 ? 'moyenne' : 'faible');
        $parts = ["Liquidité $level ($score/100)."];
        if ($sub['absorption'] !== null) {
            $parts[] = $sub['absorption'] >= 70
                ? "Les volumes offerts à la vente sont généralement absorbés rapidement."
                : ($sub['absorption'] >= 40
                    ? "L'absorption des ventes est partielle : compter plusieurs séances pour de gros volumes."
                    : "Les titres offerts à la vente restent souvent longtemps au carnet.");
        }
        if ($sub['regularite'] !== null && $sub['regularite'] < 50) {
            $parts[] = "Le titre n'échange que " . $sub['regularite'] . "% des séances — risque de rester bloqué certains jours.";
        }
        return implode(' ', $parts);
    }

    private function percentile(array $values, $mine) {
        $below = 0;
        foreach ($values as $v) {
            if ($v < $mine) {
                $below++;
            }
        }
        return round($below / max(1, count($values) - 1) * 100, 1);
    }

    private function clamp($v) {
        return round(max(0, min(100, $v)), 1);
    }

    /**
     * Date de la séance EN COURS à calculer à la volée : aujourd'hui, si la
     * période la couvre, qu'il existe des relevés intraday et qu'aucune ligne
     * consolidée n'existe encore.
     */
    private function liveDateInRange($companyId, $start, $end, array $persistedRows) {
        $today = date('Y-m-d');
        if ($today < $start || $today > $end) {
            return null;
        }
        foreach ($persistedRows as $r) {
            if (($r['trading_date'] ?? '') === $today) {
                return null;
            }
        }
        $has = $this->crud->executeCustomQuery(
            'SELECT 1 FROM intraday_quotes WHERE company_id = ? AND DATE(quote_datetime) = ? LIMIT 1',
            [$companyId, $today]
        );
        return !empty($has) ? $today : null;
    }
}

// Exécution
$api = new OrderBookAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
