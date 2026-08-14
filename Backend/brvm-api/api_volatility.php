<?php
/**
 * API volatilité — séries et graphes de risque
 * Endpoint: api_volatility.php
 *
 * Complète api_risk_metrics.php (qui ne renvoie qu'UN scalaire par
 * entreprise et par période, affiché en tuiles/tables) par ce qui manquait
 * pour DESSINER le risque : volatilité glissante, courbe de drawdown
 * (« sous l'eau »), nuage risque/rendement et distribution des rendements
 * quotidiens. Les calculs purs sont délégués à class/ReturnsCalculator.php
 * — même moteur que les métriques avancées, donc mêmes chiffres.
 *
 * Honnêteté sur la fiabilité : une volatilité calculée sur peu de séances
 * n'a pas la même valeur qu'une volatilité sur 60 séances. Chaque réponse
 * porte le nombre de rendements réellement utilisés et un drapeau
 * low_confidence (< MIN_RELIABLE_RETURNS) — le frontend l'affiche au lieu
 * de présenter un chiffre fragile comme une certitude.
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
require_once 'class/ReturnsCalculator.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class VolatilityAPI {
    /** En dessous, les statistiques restent affichées mais marquées peu fiables. */
    private const MIN_RELIABLE_RETURNS = 20;
    /** Minimum absolu pour qu'un écart-type ait un sens. */
    private const MIN_RETURNS = 3;

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
                case 'series':
                    return $this->series($input);
                case 'risk_return':
                    return $this->riskReturn($input);
                case 'distribution':
                    return $this->distribution($input);
                default:
                    return ['success' => false, 'message' => 'Action inconnue: ' . $input['action']];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** Classement de toutes les entreprises actives par volatilité. */
    private function ranking($input) {
        list($start, $end) = $this->period($input);
        $quotes = $this->quotesByCompany(null, $start, $end);

        $rows = [];
        $skipped = [];
        foreach ($quotes as $companyId => $q) {
            $stats = $this->computeStats($q['closes'], $q['dates'], $q['amplitudes']);
            if ($stats === null) {
                $skipped[] = ['symbol' => $q['symbol'], 'name' => $q['name'], 'trading_days' => count($q['closes'])];
                continue;
            }
            $rows[] = array_merge([
                'company_id' => $companyId,
                'symbol' => $q['symbol'],
                'name' => $q['name'],
            ], $stats);
        }
        usort($rows, static function ($a, $b) {
            return ($b['annualized_volatility_percent'] ?? -1) <=> ($a['annualized_volatility_percent'] ?? -1);
        });

        return ['success' => true, 'data' => [
            'rows' => $rows,
            'skipped' => $skipped,
            'period' => ['start_date' => $start, 'end_date' => $end],
            'min_reliable_returns' => self::MIN_RELIABLE_RETURNS,
            'nature' => ['calcule' => ['daily_volatility_percent', 'annualized_volatility_percent', 'max_drawdown_percent', 'avg_amplitude_percent', 'net_return_percent']],
            'note' => "Volatilité = écart-type des variations quotidiennes. Annualisée = × √250 (nombre de séances par an), pour comparer à des chiffres de marché usuels. Ce n'est PAS une prévision : elle décrit l'agitation passée du cours sur la période choisie.",
        ]];
    }

    /**
     * Volatilité glissante + courbe de drawdown pour une ou plusieurs
     * entreprises — les deux séries que l'application n'avait pas.
     */
    private function series($input) {
        $ids = $this->companyIds($input);
        list($start, $end) = $this->period($input);
        $window = max(3, min(120, (int) ($input['window'] ?? 20)));

        $quotes = $this->quotesByCompany($ids, $start, $end);
        $series = [];
        foreach ($quotes as $companyId => $q) {
            $returns = ReturnsCalculator::dailyLogReturns($q['closes']);
            $points = [];
            $peak = null;
            for ($i = 0; $i < count($q['closes']); $i++) {
                $close = $q['closes'][$i];
                $peak = ($peak === null || $close > $peak) ? $close : $peak;
                // Drawdown : recul en % depuis le plus haut atteint jusqu'ici.
                $drawdown = ($peak > 0) ? round(($close / $peak - 1) * 100, 3) : null;

                // Volatilité glissante : fenêtre des `window` derniers
                // rendements disponibles (null tant que la fenêtre n'est pas
                // pleine — on n'invente pas une valeur sur 2 points).
                $rollingVol = null;
                $availableReturns = $i; // returns[0] correspond à closes[1]
                if ($availableReturns >= $window) {
                    $slice = array_slice($returns, $i - $window, $window);
                    $rollingVol = ReturnsCalculator::annualizedVolatilityPercent($slice);
                    $rollingVol = $rollingVol !== null ? round($rollingVol, 2) : null;
                }

                $points[] = [
                    'date' => $q['dates'][$i],
                    'close' => $q['closes'][$i],
                    'rolling_volatility_percent' => $rollingVol,
                    'drawdown_percent' => $drawdown,
                ];
            }
            $series[] = [
                'company_id' => $companyId,
                'symbol' => $q['symbol'],
                'name' => $q['name'],
                'returns_count' => count($returns),
                'low_confidence' => count($returns) < self::MIN_RELIABLE_RETURNS,
                'window_reached' => count($returns) >= $window,
                'points' => $points,
            ];
        }

        return ['success' => true, 'data' => [
            'series' => $series,
            'window' => $window,
            'period' => ['start_date' => $start, 'end_date' => $end],
            'min_reliable_returns' => self::MIN_RELIABLE_RETURNS,
            'note' => "La volatilité glissante n'apparaît qu'à partir de la {$window}e séance de la période (fenêtre pleine) : avant, il n'y a pas assez de rendements pour un écart-type honnête. Le drawdown mesure le recul depuis le plus haut atteint depuis le début de la période — 0% = le titre est à son sommet.",
        ]];
    }

    /** Nuage risque/rendement : volatilité en X, performance en Y. */
    private function riskReturn($input) {
        list($start, $end) = $this->period($input);
        $ids = !empty($input['company_ids']) ? $this->companyIds($input) : null;
        $quotes = $this->quotesByCompany($ids, $start, $end);

        $points = [];
        $skipped = [];
        foreach ($quotes as $companyId => $q) {
            $stats = $this->computeStats($q['closes'], $q['dates'], $q['amplitudes']);
            if ($stats === null || $stats['annualized_volatility_percent'] === null) {
                $skipped[] = ['symbol' => $q['symbol'], 'trading_days' => count($q['closes'])];
                continue;
            }
            $points[] = [
                'company_id' => $companyId,
                'symbol' => $q['symbol'],
                'name' => $q['name'],
                'volatility_percent' => $stats['annualized_volatility_percent'],
                'return_percent' => $stats['net_return_percent'],
                'max_drawdown_percent' => $stats['max_drawdown_percent'],
                'returns_count' => $stats['returns_count'],
                'low_confidence' => $stats['low_confidence'],
                // Rendement par unité de risque 🟨 — le sens du nuage en un chiffre.
                'return_per_risk' => ($stats['annualized_volatility_percent'] > 0 && $stats['net_return_percent'] !== null)
                    ? round($stats['net_return_percent'] / $stats['annualized_volatility_percent'], 3) : null,
            ];
        }
        usort($points, static function ($a, $b) {
            return ($b['return_per_risk'] ?? -999) <=> ($a['return_per_risk'] ?? -999);
        });

        return ['success' => true, 'data' => [
            'points' => $points,
            'skipped' => $skipped,
            'period' => ['start_date' => $start, 'end_date' => $end],
            'min_reliable_returns' => self::MIN_RELIABLE_RETURNS,
            'note' => "Chaque point est une entreprise : à droite = cours plus agité, en haut = meilleure performance sur la période. En haut à gauche = le meilleur compromis (gain avec peu de secousses) ; en bas à droite = beaucoup de risque pour rien. Performance passée, pas une promesse.",
        ]];
    }

    /** Distribution des variations quotidiennes d'une entreprise + VaR/CVaR. */
    private function distribution($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new Exception('company_id requis');
        }
        list($start, $end) = $this->period($input);
        $confidence = min(0.99, max(0.80, (float) ($input['confidence'] ?? 0.95)));

        $quotes = $this->quotesByCompany([$companyId], $start, $end);
        if (empty($quotes[$companyId])) {
            throw new Exception('Aucune cotation pour cette entreprise sur la période');
        }
        $q = $quotes[$companyId];
        $returns = ReturnsCalculator::dailyLogReturns($q['closes']);
        if (count($returns) < self::MIN_RETURNS) {
            return ['success' => true, 'data' => [
                'bins' => [], 'returns_count' => count($returns), 'low_confidence' => true,
                'var_percent' => null, 'cvar_percent' => null, 'best_day' => null, 'worst_day' => null,
                'note' => "Pas assez de séances sur la période pour dessiner une distribution.",
            ]];
        }

        // Rendements en %, pour rester lisible côté frontend.
        $pct = array_map(static function ($r) {
            return $r * 100;
        }, $returns);
        $min = min($pct);
        $max = max($pct);
        $binCount = max(5, min(25, (int) round(sqrt(count($pct)) * 2)));
        $width = ($max - $min) > 0 ? ($max - $min) / $binCount : 1;

        $bins = [];
        for ($i = 0; $i < $binCount; $i++) {
            $bins[] = [
                'from' => round($min + $i * $width, 2),
                'to' => round($min + ($i + 1) * $width, 2),
                'count' => 0,
            ];
        }
        foreach ($pct as $v) {
            $idx = ($width > 0) ? (int) floor(($v - $min) / $width) : 0;
            $idx = max(0, min($binCount - 1, $idx));
            $bins[$idx]['count']++;
        }

        // Meilleure et pire séance, avec leur date (traçabilité).
        $worstIdx = array_keys($pct, min($pct))[0];
        $bestIdx = array_keys($pct, max($pct))[0];

        return ['success' => true, 'data' => [
            'symbol' => $q['symbol'],
            'name' => $q['name'],
            'bins' => $bins,
            'returns_count' => count($returns),
            'low_confidence' => count($returns) < self::MIN_RELIABLE_RETURNS,
            'var_percent' => ReturnsCalculator::historicalVaRPercent($returns, $confidence),
            'cvar_percent' => ReturnsCalculator::historicalCVaRPercent($returns, $confidence),
            'confidence' => $confidence,
            // returns[i] correspond à closes[i+1], donc dates[i+1].
            'worst_day' => ['date' => $q['dates'][$worstIdx + 1], 'variation_percent' => round($pct[$worstIdx], 2)],
            'best_day' => ['date' => $q['dates'][$bestIdx + 1], 'variation_percent' => round($pct[$bestIdx], 2)],
            'note' => "Chaque barre compte les séances dont la variation tombe dans cette tranche. La VaR répond à : « dans le pire des cas, hors " . round((1 - $confidence) * 100) . "% de séances exceptionnelles, combien puis-je perdre en un jour ? ». La CVaR est la perte moyenne quand ce pire cas se produit.",
        ]];
    }

    // ------------------------------------------------------------------

    /**
     * Statistiques d'une entreprise, ou null si l'historique est trop
     * court pour qu'un écart-type ait le moindre sens.
     */
    private function computeStats(array $closes, array $dates, array $amplitudes) {
        $returns = ReturnsCalculator::dailyLogReturns($closes);
        if (count($returns) < self::MIN_RETURNS) {
            return null;
        }
        $std = ReturnsCalculator::stdDev($returns);
        $drawdown = ReturnsCalculator::maxDrawdown($closes);
        $first = $closes[0];
        $last = $closes[count($closes) - 1];

        $avgAmplitude = null;
        $valid = array_values(array_filter($amplitudes, static function ($a) {
            return $a !== null;
        }));
        if (!empty($valid)) {
            $avgAmplitude = round(array_sum($valid) / count($valid), 3);
        }

        return [
            'trading_days' => count($closes),
            'returns_count' => count($returns),
            'low_confidence' => count($returns) < self::MIN_RELIABLE_RETURNS,
            'daily_volatility_percent' => $std !== null ? round($std * 100, 3) : null,
            'annualized_volatility_percent' => ReturnsCalculator::annualizedVolatilityPercent($returns) !== null
                ? round(ReturnsCalculator::annualizedVolatilityPercent($returns), 2) : null,
            'avg_amplitude_percent' => $avgAmplitude,
            'max_drawdown_percent' => $drawdown !== null ? round($drawdown['drawdown_percent'], 2) : null,
            'max_drawdown_peak_date' => $drawdown !== null && isset($dates[$drawdown['peak_index']]) ? $dates[$drawdown['peak_index']] : null,
            'max_drawdown_trough_date' => $drawdown !== null && isset($dates[$drawdown['trough_index']]) ? $dates[$drawdown['trough_index']] : null,
            'net_return_percent' => ($first > 0) ? round(($last / $first - 1) * 100, 2) : null,
        ];
    }

    /**
     * Cours de clôture par entreprise sur la période, avec l'amplitude
     * quotidienne (haut-bas)/clôture — une mesure d'agitation
     * complémentaire de l'écart-type, disponible même avec peu de séances.
     *
     * @param int[]|null $ids null = toutes les entreprises actives
     */
    private function quotesByCompany($ids, $start, $end) {
        $params = [$start, $end];
        $filter = '';
        if ($ids !== null && !empty($ids)) {
            $filter = 'AND q.company_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT q.company_id, c.symbol, c.name, q.trading_date, q.close_price, q.high_price, q.low_price
             FROM stock_quotes q
             JOIN companies c ON c.id = q.company_id
             WHERE q.trading_date BETWEEN ? AND ? AND c.active = 1 AND q.close_price > 0 $filter
             ORDER BY q.company_id, q.trading_date",
            $params
        ) ?: [];

        $byCompany = [];
        foreach ($rows as $r) {
            $id = (int) $r['company_id'];
            if (!isset($byCompany[$id])) {
                $byCompany[$id] = ['symbol' => $r['symbol'], 'name' => $r['name'], 'dates' => [], 'closes' => [], 'amplitudes' => []];
            }
            $close = (float) $r['close_price'];
            $byCompany[$id]['dates'][] = $r['trading_date'];
            $byCompany[$id]['closes'][] = $close;
            $byCompany[$id]['amplitudes'][] = ($r['high_price'] !== null && $r['low_price'] !== null && $close > 0)
                ? ((float) $r['high_price'] - (float) $r['low_price']) / $close * 100 : null;
        }
        return $byCompany;
    }

    private function companyIds($input) {
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
            throw new Exception('15 entreprises maximum');
        }
        return $ids;
    }

    private function period($input) {
        $start = $this->validDate($input['start_date'] ?? null) ?: date('Y-m-d', strtotime('-90 days'));
        $end = $this->validDate($input['end_date'] ?? null) ?: date('Y-m-d');
        if ($start > $end) {
            throw new Exception('start_date doit précéder end_date');
        }
        return [$start, $end];
    }

    private function validDate($d) {
        return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    }
}

// Exécution
$api = new VolatilityAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
