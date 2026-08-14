<?php
/**
 * API dividendes — rendement, calendrier et historique
 * Endpoint: api_dividends.php
 *
 * Source unique : les dividendes extraits des Bulletins Officiels de la Cote
 * (market_bulletin_corporate_actions, action_type='dividende', voir
 * class/BulletinCorporateActionsService.php). Un même dividende étant
 * annoncé dans PLUSIEURS bulletins successifs, toute lecture est
 * dédoublonnée sur (company_id, event_date, amount) — sans quoi les
 * montants seraient comptés 3 ou 4 fois.
 *
 * Le rendement (dividende / cours) est un CALCUL sur le dernier cours de
 * clôture connu : il bouge donc avec le cours, ce n'est pas une donnée
 * publiée. Le montant par action et la date, eux, sont observés.
 *
 * Limite assumée : seuls les dividendes des bulletins déjà traités sont
 * connus — la couverture s'étend à mesure que des bulletins plus anciens
 * sont téléchargés et analysés (coverage renvoyé dans chaque réponse).
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

class DividendsAPI {
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
                case 'ranking':
                    return $this->ranking($input);
                case 'calendar':
                    return $this->calendar($input);
                case 'company_history':
                    return $this->companyHistory($input);
                case 'compare':
                    return $this->compare($input);
                default:
                    return ['success' => false, 'message' => 'Action inconnue: ' . $input['action']];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Classement des entreprises par rendement du dividende — la question
     * de l'investisseur : « combien ce titre me rapporte-t-il par an, en %
     * de ce que je paie aujourd'hui ? ». Les entreprises sans dividende
     * connu sont renvoyées à part (jamais transformées en rendement 0 :
     * « pas de dividende connu » ≠ « dividende nul »).
     */
    private function ranking($input) {
        $months = max(6, min(60, (int) ($input['months'] ?? 24)));
        $dividends = $this->dividendRows($months);
        $prices = $this->latestPrices();

        $byCompany = [];
        foreach ($dividends as $d) {
            $id = (int) $d['company_id'];
            if (!isset($byCompany[$id])) {
                $byCompany[$id] = [
                    'company_id' => $id,
                    'symbol' => $d['symbol'],
                    'name' => $d['name'],
                    'total_amount' => 0.0,
                    'payments' => 0,
                    'last_amount' => null,
                    'last_date' => null,
                    'first_date' => null,
                ];
            }
            $byCompany[$id]['total_amount'] += (float) $d['amount'];
            $byCompany[$id]['payments']++;
            // Les lignes sont triées par date croissante : la dernière écrase.
            $byCompany[$id]['last_amount'] = (float) $d['amount'];
            $byCompany[$id]['last_date'] = $d['event_date'];
            if ($byCompany[$id]['first_date'] === null) {
                $byCompany[$id]['first_date'] = $d['event_date'];
            }
        }

        $rows = [];
        foreach ($byCompany as $id => $c) {
            $price = isset($prices[$id]) ? (float) $prices[$id]['close_price'] : null;
            $c['last_price'] = $price;
            $c['price_date'] = isset($prices[$id]) ? $prices[$id]['trading_date'] : null;
            // Rendement du DERNIER dividende connu (le plus comparable entre
            // titres : le cumul dépend du nombre de bulletins traités).
            $c['yield_percent'] = ($price !== null && $price > 0 && $c['last_amount'] !== null)
                ? round($c['last_amount'] / $price * 100, 2) : null;
            $c['total_yield_percent'] = ($price !== null && $price > 0)
                ? round($c['total_amount'] / $price * 100, 2) : null;
            $rows[] = $c;
        }
        usort($rows, static function ($a, $b) {
            return ($b['yield_percent'] ?? -1) <=> ($a['yield_percent'] ?? -1);
        });

        // Entreprises actives sans aucun dividende connu sur la fenêtre.
        $known = array_keys($byCompany);
        $all = $this->crud->executeCustomQuery(
            'SELECT id AS company_id, symbol, name FROM companies WHERE active = 1 ORDER BY symbol'
        ) ?: [];
        $without = [];
        foreach ($all as $c) {
            if (!in_array((int) $c['company_id'], $known, true)) {
                $without[] = ['company_id' => (int) $c['company_id'], 'symbol' => $c['symbol'], 'name' => $c['name']];
            }
        }

        return ['success' => true, 'data' => [
            'rows' => $rows,
            'without_dividend' => $without,
            'months' => $months,
            'coverage' => $this->coverage(),
            'nature' => [
                'observe' => ['last_amount', 'total_amount', 'last_date', 'last_price'],
                'calcule' => ['yield_percent', 'total_yield_percent'],
            ],
            'note' => "Rendement = dernier dividende connu ÷ dernier cours de clôture. Il varie donc avec le cours. « Pas de dividende connu » ne signifie pas « aucun dividende versé » : seuls les bulletins déjà traités sont couverts, et un dividende annoncé hors de cette fenêtre n'apparaît pas.",
        ]];
    }

    /**
     * Calendrier des paiements : à venir et passés, dédoublonnés. Sert de
     * timeline pour anticiper les détachements (le cours baisse mécaniquement
     * du montant du dividende le jour du détachement).
     */
    private function calendar($input) {
        $months = max(6, min(60, (int) ($input['months'] ?? 24)));
        $rows = $this->dividendRows($months);
        $today = date('Y-m-d');

        $upcoming = [];
        $past = [];
        foreach ($rows as $r) {
            $entry = [
                'company_id' => (int) $r['company_id'],
                'symbol' => $r['symbol'],
                'name' => $r['name'],
                'event_date' => $r['event_date'],
                'amount' => (float) $r['amount'],
                'currency' => $r['currency'],
                'description' => $r['description'],
                'days_from_today' => (int) floor((strtotime($r['event_date']) - strtotime($today)) / 86400),
            ];
            if ($r['event_date'] >= $today) {
                $upcoming[] = $entry;
            } else {
                $past[] = $entry;
            }
        }
        usort($upcoming, static function ($a, $b) {
            return strcmp($a['event_date'], $b['event_date']);
        });
        usort($past, static function ($a, $b) {
            return strcmp($b['event_date'], $a['event_date']);
        });

        // Montants versés par mois (graphe de saisonnalité).
        $monthly = [];
        foreach ($rows as $r) {
            $key = substr($r['event_date'], 0, 7);
            if (!isset($monthly[$key])) {
                $monthly[$key] = ['month' => $key, 'total_amount' => 0.0, 'payments' => 0];
            }
            $monthly[$key]['total_amount'] += (float) $r['amount'];
            $monthly[$key]['payments']++;
        }
        ksort($monthly);

        return ['success' => true, 'data' => [
            'upcoming' => $upcoming,
            'past' => $past,
            'monthly' => array_values($monthly),
            'coverage' => $this->coverage(),
            'note' => "Le jour du détachement, le cours baisse mécaniquement d'environ le montant du dividende : ce n'est pas une chute du titre. Dates telles qu'annoncées dans les bulletins — un report par l'émetteur n'est visible qu'au bulletin suivant.",
        ]];
    }

    /** Historique des dividendes d'une entreprise (série pour graphe). */
    private function companyHistory($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception('company_id requis');
        }
        $months = max(6, min(120, (int) ($input['months'] ?? 60)));

        $rows = $this->crud->executeCustomQuery(
            "SELECT d.event_date, d.amount, d.currency, d.description
             FROM (SELECT company_id, event_date, amount, currency, MIN(description) AS description
                   FROM market_bulletin_corporate_actions
                   WHERE action_type = 'dividende' AND company_id = ?
                     AND amount IS NOT NULL AND event_date IS NOT NULL
                     AND event_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                   GROUP BY company_id, event_date, amount, currency) d
             ORDER BY d.event_date",
            [$companyId, $months]
        ) ?: [];

        $prices = $this->latestPrices();
        $price = isset($prices[$companyId]) ? (float) $prices[$companyId]['close_price'] : null;

        $series = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $amount = (float) $r['amount'];
            $total += $amount;
            $series[] = [
                'event_date' => $r['event_date'],
                'amount' => $amount,
                'yield_percent' => ($price !== null && $price > 0) ? round($amount / $price * 100, 2) : null,
                'description' => $r['description'],
            ];
        }

        return ['success' => true, 'data' => [
            'series' => $series,
            'total_amount' => $total,
            'last_price' => $price,
            'payments' => count($series),
            'coverage' => $this->coverage(),
            'note' => "Historique limité aux bulletins déjà traités — l'absence d'une année ne prouve pas l'absence de dividende cette année-là.",
        ]];
    }

    /**
     * Comparaison des dividendes entre plusieurs entreprises : une série de
     * versements par entreprise (montant et rendement à chaque date) plus un
     * récapitulatif — alimente l'onglet « Dividendes » de l'écran
     * Comparaison. Les entreprises sélectionnées SANS dividende connu sont
     * renvoyées telles quelles (série vide) : leur absence est une
     * information, elles ne doivent pas disparaître silencieusement du
     * comparateur.
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
        $months = max(6, min(120, (int) ($input['months'] ?? 60)));

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->crud->executeCustomQuery(
            "SELECT d.company_id, c.symbol, c.name, d.event_date, d.amount, d.description
             FROM (SELECT company_id, event_date, amount, MIN(description) AS description
                   FROM market_bulletin_corporate_actions
                   WHERE action_type = 'dividende' AND company_id IN ($placeholders)
                     AND amount IS NOT NULL AND event_date IS NOT NULL
                     AND event_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                   GROUP BY company_id, event_date, amount) d
             JOIN companies c ON c.id = d.company_id
             ORDER BY d.company_id, d.event_date",
            array_merge($ids, [$months])
        ) ?: [];

        $names = $this->crud->executeCustomQuery(
            "SELECT id AS company_id, symbol, name FROM companies WHERE id IN ($placeholders)",
            $ids
        ) ?: [];
        $prices = $this->latestPrices();

        $byCompany = [];
        foreach ($names as $n) {
            $id = (int) $n['company_id'];
            $price = isset($prices[$id]) ? (float) $prices[$id]['close_price'] : null;
            $byCompany[$id] = [
                'company_id' => $id,
                'symbol' => $n['symbol'],
                'name' => $n['name'],
                'last_price' => $price,
                'price_date' => isset($prices[$id]) ? $prices[$id]['trading_date'] : null,
                'payments' => [],
                'total_amount' => 0.0,
                'last_amount' => null,
                'last_date' => null,
                'yield_percent' => null,
                'total_yield_percent' => null,
            ];
        }
        foreach ($rows as $r) {
            $id = (int) $r['company_id'];
            if (!isset($byCompany[$id])) {
                continue;
            }
            $amount = (float) $r['amount'];
            $price = $byCompany[$id]['last_price'];
            $byCompany[$id]['payments'][] = [
                'event_date' => $r['event_date'],
                'amount' => $amount,
                'yield_percent' => ($price !== null && $price > 0) ? round($amount / $price * 100, 2) : null,
                'description' => $r['description'],
            ];
            $byCompany[$id]['total_amount'] += $amount;
            $byCompany[$id]['last_amount'] = $amount;
            $byCompany[$id]['last_date'] = $r['event_date'];
        }
        foreach ($byCompany as $id => $c) {
            $price = $c['last_price'];
            if ($price !== null && $price > 0) {
                if ($c['last_amount'] !== null) {
                    $byCompany[$id]['yield_percent'] = round($c['last_amount'] / $price * 100, 2);
                }
                if ($c['total_amount'] > 0) {
                    $byCompany[$id]['total_yield_percent'] = round($c['total_amount'] / $price * 100, 2);
                }
            }
            $byCompany[$id]['payments_count'] = count($c['payments']);
        }

        return ['success' => true, 'data' => [
            'companies' => array_values($byCompany),
            'months' => $months,
            'coverage' => $this->coverage(),
            'nature' => [
                'observe' => ['amount', 'event_date', 'last_price'],
                'calcule' => ['yield_percent', 'total_yield_percent', 'total_amount'],
            ],
            'note' => "Une entreprise sans versement listé n'est pas forcément une entreprise sans dividende : seuls les bulletins déjà analysés sont couverts. Le rendement rapporte le dividende au dernier cours connu — il bouge donc avec le cours.",
        ]];
    }

    // ------------------------------------------------------------------

    /**
     * Dividendes dédoublonnés, triés par date croissante. Le GROUP BY est
     * indispensable : un même paiement est répété dans chaque bulletin qui
     * l'annonce (constaté : 4 lignes pour un seul dividende NTLC).
     */
    private function dividendRows($months) {
        return $this->crud->executeCustomQuery(
            "SELECT d.company_id, c.symbol, c.name, d.event_date, d.amount, d.currency, d.description
             FROM (SELECT company_id, event_date, amount, currency, MIN(description) AS description
                   FROM market_bulletin_corporate_actions
                   WHERE action_type = 'dividende' AND company_id IS NOT NULL
                     AND amount IS NOT NULL AND event_date IS NOT NULL
                     AND event_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                   GROUP BY company_id, event_date, amount, currency) d
             JOIN companies c ON c.id = d.company_id
             ORDER BY d.event_date",
            [$months]
        ) ?: [];
    }

    /** @return array<int,array{close_price:string,trading_date:string}> */
    private function latestPrices() {
        $rows = $this->crud->executeCustomQuery(
            "SELECT q.company_id, q.close_price, q.trading_date
             FROM stock_quotes q
             JOIN (SELECT company_id, MAX(trading_date) AS m FROM stock_quotes GROUP BY company_id) t
               ON t.company_id = q.company_id AND t.m = q.trading_date"
        ) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['company_id']] = ['close_price' => $r['close_price'], 'trading_date' => $r['trading_date']];
        }
        return $map;
    }

    /** Étendue réelle des données de dividendes (honnêteté sur la couverture). */
    private function coverage() {
        $row = $this->crud->executeCustomQuery(
            "SELECT COUNT(DISTINCT CONCAT(company_id,'|',event_date,'|',amount)) AS distinct_payments,
                    COUNT(DISTINCT company_id) AS companies,
                    MIN(event_date) AS first_date, MAX(event_date) AS last_date
             FROM market_bulletin_corporate_actions
             WHERE action_type = 'dividende' AND company_id IS NOT NULL AND amount IS NOT NULL"
        );
        $bulletins = $this->crud->executeCustomQuery(
            "SELECT COUNT(*) AS n FROM market_bulletin_contents WHERE corporate_actions_status = 'success'"
        );
        return [
            'distinct_payments' => !empty($row) ? (int) $row[0]['distinct_payments'] : 0,
            'companies' => !empty($row) ? (int) $row[0]['companies'] : 0,
            'first_date' => !empty($row) ? $row[0]['first_date'] : null,
            'last_date' => !empty($row) ? $row[0]['last_date'] : null,
            'bulletins_processed' => !empty($bulletins) ? (int) $bulletins[0]['n'] : 0,
        ];
    }
}

// Exécution
$api = new DividendsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
