<?php
/**
 * API de gestion des cotations boursières
 * Endpoint: api_quotes.php
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

class QuotesAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'latest':
                    return $this->getLatestQuotes($input);
                    
                case 'company_history':
                    return $this->getCompanyHistory($input);
                    
                case 'company_latest':
                    return $this->getCompanyLatest($input);
                    
                case 'date_range':
                    return $this->getQuotesByDateRange($input);
                    
                case 'compare':
                    return $this->compareCompanies($input);
                    
                case 'ohlc':
                    return $this->getOHLCData($input);

                case 'intraday':
                    return $this->getIntradayHistory($input);

                case 'liquidity':
                    return $this->getLiquidity($input);

                case 'sector_performance':
                    return $this->getSectorPerformance($input);

                case 'total_variation':
                    return $this->getTotalVariation($input);

                case 'market_breadth':
                    return $this->getMarketBreadth($input);

                case 'correlation':
                    return $this->getCorrelation($input);

                case 'risk_adjusted':
                    return $this->getRiskAdjustedPerformance($input);

                case 'relative_strength':
                    return $this->getRelativeStrength($input);

                case 'share_turnover':
                    return $this->getShareTurnover($input);

                case 'volume_ranking':
                    return $this->getVolumeRanking($input);

                case 'performance_ranking':
                    return $this->getPerformanceRanking($input);

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
     * Dernières cotations de toutes les actions
     */
    private function getLatestQuotes($input) {
        $page = (int)($input['page'] ?? 1);
        $perPage = (int)($input['per_page'] ?? 50);
        
        $sort = $input['sort'] ?? 'symbol';
        $order = strtoupper($input['order'] ?? 'ASC');
        
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'ASC';
        }

        $allowedSorts = [
            'symbol' => 'c.symbol',
            'name' => 'c.name',
            'price' => 'sq.close_price',
            'variation' => 'sq.variation_percent',
            'volume' => 'sq.volume'
        ];

        $orderByField = $allowedSorts[$sort] ?? 'c.symbol';

        $sql = "
            SELECT 
                c.id AS company_id,
                c.symbol,
                c.name,
                s.name AS sector,
                sq.trading_date,
                sq.open_price,
                sq.close_price,
                sq.high_price,
                sq.low_price,
                sq.previous_close,
                sq.volume,
                sq.variation_percent,
                sq.variation_value,
                sq.turnover
            FROM companies c
            LEFT JOIN sectors s ON c.sector_id = s.id
            INNER JOIN stock_quotes sq ON c.id = sq.company_id
            WHERE sq.trading_date = (
                SELECT MAX(trading_date) 
                FROM stock_quotes 
                WHERE company_id = c.id
            )
            AND c.active = 1
            ORDER BY $orderByField $order
            LIMIT ? OFFSET ?
        ";

        $offset = ($page - 1) * $perPage;
        $quotes = $this->crud->executeCustomQuery($sql, [$perPage, $offset]);

        // Compter le total
        $countSql = "
            SELECT COUNT(DISTINCT c.id) as total
            FROM companies c
            INNER JOIN stock_quotes sq ON c.id = sq.company_id
            WHERE sq.trading_date = (
                SELECT MAX(trading_date) 
                FROM stock_quotes 
                WHERE company_id = c.id
            )
            AND c.active = 1
        ";
        
        $countResult = $this->crud->executeCustomQuery($countSql);
        $total = $countResult[0]['total'] ?? 0;

        return [
            'success' => true,
            'data' => $quotes ?: [],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage)
            ]
        ];
    }

    /**
     * Historique des cotations d'une entreprise
     */
    private function getCompanyHistory($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';
        
        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        // Récupérer l'ID si on a le symbole
        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $days = (int)($input['days'] ?? 30);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT 
                trading_date,
                open_price,
                close_price,
                high_price,
                low_price,
                previous_close,
                volume,
                variation_percent,
                variation_value,
                turnover
            FROM stock_quotes
            WHERE company_id = ?
        ";

        $params = [$companyId];

        if ($startDate) {
            $sql .= " AND trading_date >= ?";
            $params[] = $startDate;
        } else {
            // Utiliser le nombre de jours
            $sql .= " AND trading_date >= DATE_SUB(?, INTERVAL ? DAY)";
            $params[] = $endDate;
            $params[] = $days;
        }

        if ($endDate) {
            $sql .= " AND trading_date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY trading_date ASC";

        $quotes = $this->crud->executeCustomQuery($sql, $params);

        return [
            'success' => true,
            'data' => $quotes ?: [],
            'count' => count($quotes ?: []),
            'company_id' => $companyId
        ];
    }

    /**
     * Dernière cotation d'une entreprise
     */
    private function getCompanyLatest($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';
        
        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        $where = $companyId ? ['company_id' => $companyId] : [];
        
        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $where['company_id'] = $company[0]['id'];
        }

        $quotes = $this->crud->find('stock_quotes', $where, PDO::FETCH_ASSOC, true, 'trading_date DESC');

        if (empty($quotes)) {
            throw new Exception("Aucune cotation trouvée");
        }

        return [
            'success' => true,
            'data' => $quotes[0]
        ];
    }

    /**
     * Cotations par plage de dates
     */
    private function getQuotesByDateRange($input) {
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');
        
        if (!$startDate) {
            throw new Exception("Date de début requise");
        }

        $sql = "
            SELECT 
                c.id AS company_id,
                c.symbol,
                c.name,
                sq.trading_date,
                sq.close_price,
                sq.volume,
                sq.variation_percent
            FROM stock_quotes sq
            INNER JOIN companies c ON sq.company_id = c.id
            WHERE sq.trading_date BETWEEN ? AND ?
            AND c.active = 1
            ORDER BY sq.trading_date DESC, c.symbol ASC
        ";

        $quotes = $this->crud->executeCustomQuery($sql, [$startDate, $endDate]);

        return [
            'success' => true,
            'data' => $quotes ?: [],
            'count' => count($quotes ?: []),
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ];
    }

    /**
     * Compare plusieurs entreprises — clôtures quotidiennes (stock_quotes) ou,
     * si granularity=intraday, relevés intrajournaliers (intraday_quotes,
     * bien plus fournis dans la même journée grâce au cron toutes les 10
     * minutes, utile tant que peu de jours de clôture se sont accumulés).
     */
    private function compareCompanies($input) {
        $companyIds = $input['company_ids'] ?? [];
        $symbols = $input['symbols'] ?? [];

        if (empty($companyIds) && empty($symbols)) {
            throw new Exception("IDs ou symboles des entreprises requis");
        }

        // Récupérer les IDs si on a les symboles
        if (!empty($symbols) && empty($companyIds)) {
            $placeholders = implode(',', array_fill(0, count($symbols), '?'));
            $sql = "SELECT id FROM companies WHERE symbol IN ($placeholders)";
            $results = $this->crud->executeCustomQuery($sql, $symbols);
            $companyIds = array_column($results, 'id');
        }

        if (empty($companyIds)) {
            throw new Exception("Aucune entreprise trouvée");
        }

        $granularity = ($input['granularity'] ?? 'daily') === 'intraday' ? 'intraday' : 'daily';

        $organized = $granularity === 'intraday'
            ? $this->compareCompaniesIntraday($companyIds, $input)
            : $this->compareCompaniesDaily($companyIds, $input);

        return [
            'success' => true,
            'data' => array_values($organized),
            'companies_count' => count($organized),
            'granularity' => $granularity
        ];
    }

    private function compareCompaniesDaily($companyIds, $input) {
        $days = (int)($input['days'] ?? 30);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                sq.trading_date,
                sq.close_price,
                sq.volume,
                sq.variation_percent
            FROM stock_quotes sq
            INNER JOIN companies c ON sq.company_id = c.id
            WHERE sq.company_id IN ($placeholders)
        ";

        $params = $companyIds;

        if ($startDate) {
            $sql .= " AND sq.trading_date >= ?";
            $params[] = $startDate;
        } else {
            $sql .= " AND sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)";
            $params[] = $endDate;
            $params[] = $days;
        }

        if ($endDate) {
            $sql .= " AND sq.trading_date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY sq.trading_date ASC, c.symbol ASC";

        $quotes = $this->crud->executeCustomQuery($sql, $params);

        $organized = [];
        foreach ($quotes as $quote) {
            $symbol = $quote['symbol'];
            if (!isset($organized[$symbol])) {
                $organized[$symbol] = [
                    'company_id' => $quote['company_id'],
                    'symbol' => $symbol,
                    'name' => $quote['name'],
                    'data' => []
                ];
            }
            $organized[$symbol]['data'][] = [
                'date' => $quote['trading_date'],
                'price' => $quote['close_price'],
                'volume' => $quote['volume'],
                'variation' => $quote['variation_percent']
            ];
        }

        return $organized;
    }

    private function compareCompaniesIntraday($companyIds, $input) {
        // Par défaut, la journée en cours seulement — un intervalle plus
        // large reste possible (ex: comparer plusieurs jours de relevés
        // intrajournaliers), mais le cas d'usage principal est "aujourd'hui".
        $startDate = $input['start_date'] ?? date('Y-m-d');
        $endDate = $input['end_date'] ?? $startDate;

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                iq.quote_datetime,
                iq.price,
                iq.volume,
                iq.variation_percent
            FROM intraday_quotes iq
            INNER JOIN companies c ON c.id = iq.company_id
            WHERE iq.company_id IN ($placeholders)
            AND iq.quote_datetime >= ?
            AND iq.quote_datetime <= ?
            ORDER BY iq.quote_datetime ASC, c.symbol ASC
        ";

        $params = array_merge($companyIds, ["$startDate 00:00:00", "$endDate 23:59:59"]);

        $snapshots = $this->crud->executeCustomQuery($sql, $params);

        $organized = [];
        foreach ($snapshots as $snap) {
            $symbol = $snap['symbol'];
            if (!isset($organized[$symbol])) {
                $organized[$symbol] = [
                    'company_id' => $snap['company_id'],
                    'symbol' => $symbol,
                    'name' => $snap['name'],
                    'data' => []
                ];
            }
            $organized[$symbol]['data'][] = [
                'date' => $snap['quote_datetime'],
                'price' => $snap['price'],
                'volume' => $snap['volume'],
                'variation' => $snap['variation_percent']
            ];
        }

        return $organized;
    }

    /**
     * Données OHLC (Open, High, Low, Close) pour les graphiques
     */
    private function getOHLCData($input) {
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
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT 
                trading_date as date,
                open_price as open,
                high_price as high,
                low_price as low,
                close_price as close,
                volume
            FROM stock_quotes
            WHERE company_id = ?
        ";

        $params = [$companyId];

        if ($startDate) {
            $sql .= " AND trading_date >= ?";
            $params[] = $startDate;
        } else {
            $sql .= " AND trading_date >= DATE_SUB(?, INTERVAL ? DAY)";
            $params[] = $endDate;
            $params[] = $days;
        }

        if ($endDate) {
            $sql .= " AND trading_date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY trading_date ASC";

        $ohlc = $this->crud->executeCustomQuery($sql, $params);

        return [
            'success' => true,
            'data' => $ohlc ?: [],
            'count' => count($ohlc ?: []),
            'company_id' => $companyId
        ];
    }
    /**
     * Historique intrajournalier d'une entreprise pour une journée donnée
     * (un point par synchronisation, ex: toutes les 15 minutes)
     */
    private function getIntradayHistory($input) {
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

        $tradingDate = $input['trading_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                quote_datetime,
                price,
                volume,
                variation_percent
            FROM intraday_quotes
            WHERE company_id = ?
            AND DATE(quote_datetime) = ?
            ORDER BY quote_datetime ASC
        ";

        $snapshots = $this->crud->executeCustomQuery($sql, [$companyId, $tradingDate]);

        return [
            'success' => true,
            'data' => $snapshots ?: [],
            'count' => count($snapshots ?: []),
            'company_id' => $companyId,
            'trading_date' => $tradingDate
        ];
    }

    /**
     * Score de liquidité par entreprise — volume moyen et part de jours sans
     * transaction sur une période. Caractéristique connue des marchés
     * frontière comme la BRVM (plusieurs titres à volume nul certains jours,
     * cf. TODO_ANALYSES.md point 2) : un cours qui ne bouge pas peut vouloir
     * dire "stable" ou "personne n'a tradé" — nuance à afficher à côté de
     * n'importe quel signal technique calculé sur ce cours.
     *
     * Seuils de classement volontairement simples (à ajuster une fois plus
     * d'historique accumulé) : illiquide si >30% des jours à volume nul,
     * sinon classé par volume moyen.
     */
    private function getLiquidity($input) {
        $companyIds = $input['company_ids'] ?? [];
        $days = (int)($input['days'] ?? 30);
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
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

        $sql .= " GROUP BY c.id, c.symbol, c.name ORDER BY avg_volume ASC";

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        $result = array_map(function ($row) {
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

            return [
                'company_id' => (int) $row['company_id'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'avg_volume' => round($avgVolume, 1),
                'zero_volume_days' => $zeroDays,
                'total_days' => $totalDays,
                'zero_volume_ratio' => round($zeroRatio * 100, 1),
                'liquidity' => $label
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'days' => $days,
            'end_date' => $endDate
        ];
    }

    /**
     * Performance moyenne par secteur — indice équipondéré base 100 au début
     * de la période (moyenne, par jour, de l'indice base-100 de chaque
     * entreprise du secteur) : compare des secteurs entiers comme le fait
     * déjà l'action `compare` pour des entreprises individuelles.
     */
    private function getSectorPerformance($input) {
        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                s.id AS sector_id,
                s.name AS sector_name,
                c.id AS company_id,
                sq.trading_date,
                sq.close_price
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            INNER JOIN sectors s ON s.id = c.sector_id
            WHERE sq.trading_date >= ? AND sq.trading_date <= ?
            AND c.active = 1
            ORDER BY s.id, c.id, sq.trading_date ASC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$startDate, $endDate]) ?: [];

        // Prix de référence par entreprise = sa première clôture de la période
        $baseByCompany = [];
        foreach ($rows as $row) {
            $cid = $row['company_id'];
            if (!isset($baseByCompany[$cid])) {
                $baseByCompany[$cid] = (float) $row['close_price'];
            }
        }

        // Indice base 100 par entreprise et par jour, moyenné par secteur
        $bySector = [];
        foreach ($rows as $row) {
            $base = $baseByCompany[$row['company_id']];
            if ($base <= 0) continue;

            $index = ((float) $row['close_price'] / $base) * 100;
            $sid = $row['sector_id'];
            $date = $row['trading_date'];

            if (!isset($bySector[$sid])) {
                $bySector[$sid] = ['sector_name' => $row['sector_name'], 'dates' => []];
            }
            if (!isset($bySector[$sid]['dates'][$date])) {
                $bySector[$sid]['dates'][$date] = ['sum' => 0, 'count' => 0];
            }
            $bySector[$sid]['dates'][$date]['sum'] += $index;
            $bySector[$sid]['dates'][$date]['count']++;
        }

        $result = [];
        foreach ($bySector as $sid => $info) {
            ksort($info['dates']);
            $series = [];
            foreach ($info['dates'] as $date => $agg) {
                $series[] = [
                    'date' => $date,
                    'index_value' => round($agg['sum'] / $agg['count'], 2),
                    'companies_count' => $agg['count']
                ];
            }
            $result[] = [
                'sector_id' => (int) $sid,
                'sector_name' => $info['sector_name'],
                'data' => $series
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Variation totale (churn/volatilité intrajournalière) par entreprise —
     * agrégat quotidien déjà accumulé en continu par
     * BRVMSyncService::accumulateTotalVariation() dans
     * intraday_total_variation (voir TODO_ANALYSES.md, point 8). Un point
     * par jour (pas de vue intrajournalière ici, contrairement à l'action
     * `compare`), même forme d'entrée que `compare` pour rester cohérent
     * côté frontend.
     */
    private function getTotalVariation($input) {
        $companyIds = $input['company_ids'] ?? [];

        if (empty($companyIds)) {
            throw new Exception("IDs des entreprises requis");
        }

        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                itv.trading_date,
                itv.total_gain_percent,
                itv.total_loss_percent,
                itv.total_variation_percent
            FROM intraday_total_variation itv
            INNER JOIN companies c ON c.id = itv.company_id
            WHERE itv.company_id IN ($placeholders)
            AND itv.trading_date >= ? AND itv.trading_date <= ?
            ORDER BY c.symbol ASC, itv.trading_date ASC
        ";

        $params = array_merge($companyIds, [$startDate, $endDate]);
        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        $organized = [];
        foreach ($rows as $row) {
            $symbol = $row['symbol'];
            if (!isset($organized[$symbol])) {
                $organized[$symbol] = [
                    'company_id' => $row['company_id'],
                    'symbol' => $symbol,
                    'name' => $row['name'],
                    'data' => []
                ];
            }
            $organized[$symbol]['data'][] = [
                'date' => $row['trading_date'],
                'total_gain_percent' => $row['total_gain_percent'],
                'total_loss_percent' => $row['total_loss_percent'],
                'total_variation_percent' => $row['total_variation_percent']
            ];
        }

        return [
            'success' => true,
            'data' => array_values($organized),
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Largeur de marché dans le temps (TODO_ANALYSES.md point 6) — pour
     * chaque jour de la période, part de titres en hausse/baisse/inchangés.
     * Un indice porté par 3 titres n'a pas le même sens qu'une hausse
     * partagée par 30 — généralise ce que market-movers.php calcule déjà
     * pour un seul jour à une série temporelle.
     */
    private function getMarketBreadth($input) {
        $days = (int)($input['days'] ?? 30);
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                sq.trading_date,
                SUM(CASE WHEN sq.variation_percent > 0 THEN 1 ELSE 0 END) AS gainers,
                SUM(CASE WHEN sq.variation_percent < 0 THEN 1 ELSE 0 END) AS losers,
                SUM(CASE WHEN sq.variation_percent = 0 THEN 1 ELSE 0 END) AS unchanged,
                COUNT(*) AS total
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND sq.trading_date <= ?
            AND c.active = 1
            GROUP BY sq.trading_date
            ORDER BY sq.trading_date ASC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$endDate, $days, $endDate]) ?: [];

        $result = array_map(function ($row) {
            $total = (int) $row['total'];
            return [
                'date' => $row['trading_date'],
                'gainers' => (int) $row['gainers'],
                'losers' => (int) $row['losers'],
                'unchanged' => (int) $row['unchanged'],
                'total' => $total,
                'breadth_percent' => $total > 0 ? round((int) $row['gainers'] / $total * 100, 1) : null
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $result,
            'days' => $days,
            'end_date' => $endDate
        ];
    }

    /**
     * Corrélation de Pearson entre les variations quotidiennes de plusieurs
     * entreprises (TODO_ANALYSES.md point 7) — quels titres bougent
     * ensemble, utile pour la diversification d'un portefeuille. Restreint
     * aux jours communs à toutes les entreprises sélectionnées (sinon des
     * jours manquants différents fausseraient la comparaison).
     */
    private function getCorrelation($input) {
        $companyIds = $input['company_ids'] ?? [];

        if (count($companyIds) < 2) {
            throw new Exception("Au moins 2 entreprises requises pour une corrélation");
        }

        $days = (int)($input['days'] ?? 90);
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $sql = "
            SELECT c.id AS company_id, c.symbol, sq.trading_date, sq.variation_percent
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.company_id IN ($placeholders)
            AND sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND sq.trading_date <= ?
            ORDER BY sq.trading_date ASC
        ";
        $params = array_merge($companyIds, [$endDate, $days, $endDate]);
        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        $bySymbol = [];
        foreach ($rows as $row) {
            $bySymbol[$row['symbol']][$row['trading_date']] = (float) $row['variation_percent'];
        }

        $commonDates = null;
        foreach ($bySymbol as $dates) {
            $ds = array_keys($dates);
            $commonDates = $commonDates === null ? $ds : array_intersect($commonDates, $ds);
        }
        $commonDates = array_values($commonDates ?? []);
        sort($commonDates);

        $symbols = array_keys($bySymbol);
        $series = [];
        foreach ($symbols as $symbol) {
            $series[$symbol] = array_map(fn($d) => $bySymbol[$symbol][$d], $commonDates);
        }

        $matrix = [];
        foreach ($symbols as $a) {
            foreach ($symbols as $b) {
                $matrix[$a][$b] = $a === $b ? 1.0 : $this->pearsonCorrelation($series[$a], $series[$b]);
            }
        }

        return [
            'success' => true,
            'data' => [
                'symbols' => $symbols,
                'matrix' => $matrix,
                'common_days' => count($commonDates)
            ],
            'days' => $days,
            'end_date' => $endDate
        ];
    }

    private function pearsonCorrelation($x, $y) {
        $n = count($x);
        if ($n < 2) {
            return null;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $num = 0;
        $denX = 0;
        $denY = 0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $num += $dx * $dy;
            $denX += $dx * $dx;
            $denY += $dy * $dy;
        }

        if ($denX == 0 || $denY == 0) {
            return null;
        }

        return round($num / sqrt($denX * $denY), 3);
    }

    /**
     * Performance ajustée au risque (TODO_ANALYSES.md point 9) — dépend du
     * point 8 (intraday_total_variation) comme mesure de risque : ratio
     * rendement net / volatilité cumulée sur la période. Un titre qui monte
     * de 10% sans bouger vaut mieux qu'un titre qui monte de 10% en
     * oscillant sans cesse (rendement identique, risque pris différent).
     */
    private function getRiskAdjustedPerformance($input) {
        $companyIds = $input['company_ids'] ?? [];

        if (empty($companyIds)) {
            throw new Exception("IDs des entreprises requis");
        }

        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $sqlPrices = "
            SELECT c.id AS company_id, c.symbol, c.name, sq.trading_date, sq.close_price
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.company_id IN ($placeholders)
            AND sq.trading_date >= ? AND sq.trading_date <= ?
            ORDER BY c.id ASC, sq.trading_date ASC
        ";
        $priceRows = $this->crud->executeCustomQuery($sqlPrices, array_merge($companyIds, [$startDate, $endDate])) ?: [];

        $firstPrice = [];
        $lastPrice = [];
        $names = [];
        foreach ($priceRows as $row) {
            $cid = $row['company_id'];
            if (!isset($firstPrice[$cid])) {
                $firstPrice[$cid] = (float) $row['close_price'];
            }
            $lastPrice[$cid] = (float) $row['close_price'];
            $names[$cid] = ['symbol' => $row['symbol'], 'name' => $row['name']];
        }

        $sqlVol = "
            SELECT company_id, SUM(total_variation_percent) AS total_volatility
            FROM intraday_total_variation
            WHERE company_id IN ($placeholders)
            AND trading_date >= ? AND trading_date <= ?
            GROUP BY company_id
        ";
        $volRows = $this->crud->executeCustomQuery($sqlVol, array_merge($companyIds, [$startDate, $endDate])) ?: [];
        $volatilityByCompany = [];
        foreach ($volRows as $row) {
            $volatilityByCompany[$row['company_id']] = (float) $row['total_volatility'];
        }

        $result = [];
        foreach ($firstPrice as $cid => $first) {
            $last = $lastPrice[$cid];
            $netReturn = $first > 0 ? (($last - $first) / $first) * 100 : null;
            $volatility = $volatilityByCompany[$cid] ?? null;
            $ratio = ($volatility !== null && $volatility > 0 && $netReturn !== null)
                ? round($netReturn / $volatility, 3)
                : null;

            $result[] = [
                'company_id' => $cid,
                'symbol' => $names[$cid]['symbol'],
                'name' => $names[$cid]['name'],
                'net_return_percent' => $netReturn !== null ? round($netReturn, 2) : null,
                'total_volatility_percent' => $volatility !== null ? round($volatility, 2) : null,
                'risk_adjusted_ratio' => $ratio
            ];
        }

        usort($result, fn($a, $b) => ($b['risk_adjusted_ratio'] ?? -999) <=> ($a['risk_adjusted_ratio'] ?? -999));

        return [
            'success' => true,
            'data' => array_values($result),
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Force relative vs indice BRVM-COMPOSITE (TODO_ANALYSES.md point 10) —
     * dépend du point 0 (index_composition), traité en amont via
     * scripts/populate_market_cap.php. Compare la variation quotidienne
     * d'une entreprise à celle de l'indice le même jour : positif =
     * surperformance, négatif = sous-performance. N'utilise pas directement
     * index_composition ici (le calcul repose sur index_values, déjà
     * synchronisé quotidiennement) — la composition sert surtout de
     * justification/traçabilité de ce que représente BRVM-COMPOSITE.
     */
    private function getRelativeStrength($input) {
        $companyIds = $input['company_ids'] ?? [];

        if (empty($companyIds)) {
            throw new Exception("IDs des entreprises requis");
        }

        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                sq.trading_date,
                sq.variation_percent AS company_variation,
                iv.variation_percent AS index_variation
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            LEFT JOIN market_indices mi ON mi.code = 'BRVM-COMPOSITE'
            LEFT JOIN index_values iv ON iv.trading_date = sq.trading_date AND iv.index_id = mi.id
            WHERE sq.company_id IN ($placeholders)
            AND sq.trading_date >= ? AND sq.trading_date <= ?
            ORDER BY c.symbol ASC, sq.trading_date ASC
        ";

        $rows = $this->crud->executeCustomQuery($sql, array_merge($companyIds, [$startDate, $endDate])) ?: [];

        $organized = [];
        foreach ($rows as $row) {
            $symbol = $row['symbol'];
            if (!isset($organized[$symbol])) {
                $organized[$symbol] = [
                    'company_id' => $row['company_id'],
                    'symbol' => $symbol,
                    'name' => $row['name'],
                    'data' => []
                ];
            }

            $companyVar = $row['company_variation'] !== null ? (float) $row['company_variation'] : null;
            $indexVar = $row['index_variation'] !== null ? (float) $row['index_variation'] : null;

            $organized[$symbol]['data'][] = [
                'date' => $row['trading_date'],
                'company_variation_percent' => $companyVar,
                'index_variation_percent' => $indexVar,
                'relative_strength' => ($companyVar !== null && $indexVar !== null)
                    ? round($companyVar - $indexVar, 4)
                    : null
            ];
        }

        return [
            'success' => true,
            'data' => array_values($organized),
            'index_code' => 'BRVM-COMPOSITE',
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Compare, par entreprise, le nombre d'actions en circulation
     * (companies.shares_outstanding — valeur quasi-fixe, scrapée
     * ponctuellement par scripts/populate_market_cap.php, pas à chaque
     * synchro) au volume total échangé sur la période donnée
     * (SUM(stock_quotes.volume)).
     *
     * Attention à ne pas lire "actions en circulation" comme un stock
     * "en attente de vente" : ce sont TOUTES les actions émises, déjà
     * détenues par quelqu'un (fondateurs, État, public...) dès l'introduction
     * en bourse — il n'y a pas "d'actions pas encore vendues" pour une
     * société déjà cotée. Trois métriques dérivées, chacune répondant à une
     * question différente :
     *   - turnover_percent : part du capital qui a changé de main sur la
     *     période (volume / actions en circulation).
     *   - shares_untouched_estimate : actions en circulation − volume
     *     échangé, càd le nombre d'actions n'ayant PAS retrouvé d'acheteur
     *     sur la période (restées chez leur détenteur actuel) — une
     *     ESTIMATION BASSE seulement (les mêmes titres peuvent être
     *     retradés plusieurs fois dans la période), plafonnée à 0 avec
     *     fully_rotated=true quand le volume dépasse les actions en
     *     circulation (le "capital" a été retradé au moins une fois en
     *     moyenne, la métrique perd son sens).
     *   - floating_percent : part RÉELLEMENT disponible au marché, hors
     *     participations stratégiques bloquées (floating_market_cap /
     *     market_cap, tel que publié par BRVM) — la notion la plus proche
     *     "d'actions disponibles à l'achat" pour une société déjà cotée.
     * shares_outstanding/floating_market_cap peuvent être NULL si
     * populate_market_cap.php n'a pas encore tourné pour cette entreprise —
     * les métriques dérivées valent alors null plutôt qu'une valeur trompeuse.
     */
    private function getShareTurnover($input) {
        $companyIds = $input['company_ids'] ?? [];

        if (empty($companyIds)) {
            throw new Exception("IDs des entreprises requis");
        }

        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                c.shares_outstanding,
                c.market_cap,
                c.floating_market_cap,
                COALESCE(SUM(sq.volume), 0) AS total_volume_traded
            FROM companies c
            LEFT JOIN stock_quotes sq
                ON sq.company_id = c.id
                AND sq.trading_date >= ? AND sq.trading_date <= ?
            WHERE c.id IN ($placeholders)
            GROUP BY c.id, c.symbol, c.name, c.shares_outstanding, c.market_cap, c.floating_market_cap
            ORDER BY c.symbol ASC
        ";

        $params = array_merge([$startDate, $endDate], $companyIds);
        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        $result = array_map(function ($row) {
            $sharesOutstanding = $row['shares_outstanding'] !== null ? (float) $row['shares_outstanding'] : null;
            $marketCap = $row['market_cap'] !== null ? (float) $row['market_cap'] : null;
            $floatingMarketCap = $row['floating_market_cap'] !== null ? (float) $row['floating_market_cap'] : null;
            $volumeTraded = (float) $row['total_volume_traded'];

            $turnoverPercent = ($sharesOutstanding && $sharesOutstanding > 0)
                ? round(($volumeTraded / $sharesOutstanding) * 100, 2)
                : null;

            $fullyRotated = $sharesOutstanding !== null && $volumeTraded > $sharesOutstanding;
            $sharesUntouchedEstimate = $sharesOutstanding !== null
                ? max(0, $sharesOutstanding - $volumeTraded)
                : null;

            $floatingPercent = ($marketCap && $marketCap > 0 && $floatingMarketCap !== null)
                ? round(($floatingMarketCap / $marketCap) * 100, 2)
                : null;

            return [
                'company_id' => (int) $row['company_id'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'shares_outstanding' => $sharesOutstanding,
                'total_volume_traded' => $volumeTraded,
                'turnover_percent' => $turnoverPercent,
                'shares_untouched_estimate' => $sharesUntouchedEstimate,
                'fully_rotated' => $fullyRotated,
                'floating_market_cap' => $floatingMarketCap,
                'market_cap' => $marketCap,
                'floating_percent' => $floatingPercent
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $result,
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Classement de toutes les entreprises actives par volume total échangé
     * sur une période — contrairement à `latest` (seulement la dernière
     * séance connue de chaque entreprise), agrège ici toutes les séances de
     * la plage donnée. LEFT JOIN comme dans getShareTurnover() pour que les
     * entreprises sans transaction sur la période apparaissent quand même à
     * 0 plutôt que d'être silencieusement exclues du classement.
     *
     * Reprend aussi la logique de getShareTurnover() pour situer le volume
     * échangé par rapport au capital total de l'entreprise (companies.
     * shares_outstanding) : "vendu" = volume échangé sur la période,
     * "restant" = actions en circulation - volume échangé (estimation basse,
     * voir la note détaillée sur getShareTurnover). Il n'existe PAS de
     * notion "à vendre" au sens carnet d'ordres (BRVM ne publie pas les
     * volumes achat/vente en attente) — "actions en circulation" est le
     * pool total déjà détenu par quelqu'un dès l'introduction en bourse,
     * pas un stock disponible à l'achat.
     */
    private function getVolumeRanking($input) {
        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                s.name AS sector,
                c.shares_outstanding,
                COALESCE(SUM(sq.volume), 0) AS total_volume,
                COALESCE(SUM(sq.turnover), 0) AS total_turnover,
                COUNT(sq.trading_date) AS trading_days
            FROM companies c
            LEFT JOIN sectors s ON c.sector_id = s.id
            LEFT JOIN stock_quotes sq
                ON sq.company_id = c.id
                AND sq.trading_date >= ? AND sq.trading_date <= ?
            WHERE c.active = 1
            GROUP BY c.id, c.symbol, c.name, s.name, c.shares_outstanding
            ORDER BY total_volume DESC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$startDate, $endDate]) ?: [];

        $result = array_map(function ($row) {
            $sharesOutstanding = $row['shares_outstanding'] !== null ? (float) $row['shares_outstanding'] : null;
            $totalVolume = (float) $row['total_volume'];

            $turnoverPercent = ($sharesOutstanding && $sharesOutstanding > 0)
                ? round(($totalVolume / $sharesOutstanding) * 100, 2)
                : null;
            $fullyRotated = $sharesOutstanding !== null && $totalVolume > $sharesOutstanding;
            $sharesRemainingEstimate = $sharesOutstanding !== null
                ? max(0, $sharesOutstanding - $totalVolume)
                : null;

            return [
                'company_id' => (int) $row['company_id'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector' => $row['sector'],
                'shares_outstanding' => $sharesOutstanding,
                'total_volume' => $totalVolume,
                'total_turnover' => (float) $row['total_turnover'],
                'trading_days' => (int) $row['trading_days'],
                'turnover_percent' => $turnoverPercent,
                'shares_remaining_estimate' => $sharesRemainingEstimate,
                'fully_rotated' => $fullyRotated
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Classement de toutes les entreprises actives par performance de cours
     * sur une période — même calcul que getRiskAdjustedPerformance()
     * (rendement net = (dernière clôture - première clôture) / première
     * clôture sur la période, en %), mais sans filtre company_ids ni volet
     * risque, pour classer l'univers complet plutôt qu'une sélection.
     * Une entreprise active sans aucune cotation sur la période apparaît
     * quand même, avec variation_percent à null (contrairement au volume,
     * il n'y a pas de valeur "neutre" à donner à une performance sans
     * donnée — 0% suggérerait à tort une stabilité observée).
     */
    private function getPerformanceRanking($input) {
        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $companies = $this->crud->executeCustomQuery(
            "SELECT c.id AS company_id, c.symbol, c.name, s.name AS sector
             FROM companies c
             LEFT JOIN sectors s ON c.sector_id = s.id
             WHERE c.active = 1"
        ) ?: [];

        $sql = "
            SELECT sq.company_id, sq.trading_date, sq.close_price
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.trading_date >= ? AND sq.trading_date <= ?
            AND c.active = 1
            ORDER BY sq.company_id ASC, sq.trading_date ASC
        ";
        $rows = $this->crud->executeCustomQuery($sql, [$startDate, $endDate]) ?: [];

        $firstPrice = [];
        $lastPrice = [];
        $tradingDays = [];
        foreach ($rows as $row) {
            $cid = $row['company_id'];
            if (!isset($firstPrice[$cid])) {
                $firstPrice[$cid] = (float) $row['close_price'];
            }
            $lastPrice[$cid] = (float) $row['close_price'];
            $tradingDays[$cid] = ($tradingDays[$cid] ?? 0) + 1;
        }

        $result = array_map(function ($c) use ($firstPrice, $lastPrice, $tradingDays) {
            $cid = $c['company_id'];
            $first = $firstPrice[$cid] ?? null;
            $last = $lastPrice[$cid] ?? null;
            $variation = ($first !== null && $first > 0) ? round((($last - $first) / $first) * 100, 2) : null;

            return [
                'company_id' => (int) $cid,
                'symbol' => $c['symbol'],
                'name' => $c['name'],
                'sector' => $c['sector'],
                'first_close_price' => $first,
                'last_close_price' => $last,
                'variation_percent' => $variation,
                'trading_days' => $tradingDays[$cid] ?? 0
            ];
        }, $companies);

        usort($result, function ($a, $b) {
            if ($a['variation_percent'] === null && $b['variation_percent'] === null) return 0;
            if ($a['variation_percent'] === null) return 1;
            if ($b['variation_percent'] === null) return -1;
            return $b['variation_percent'] <=> $a['variation_percent'];
        });

        return [
            'success' => true,
            'data' => array_values($result),
            'count' => count($result),
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }
}

// Exécution
$api = new QuotesAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);