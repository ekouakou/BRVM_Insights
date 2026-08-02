<?php
/**
 * API des statistiques de marché et indices
 * Endpoint: api_market.php
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

class MarketAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'overview':
                    return $this->getMarketOverview($input);
                    
                case 'top_gainers':
                    return $this->getTopGainers($input);
                    
                case 'top_losers':
                    return $this->getTopLosers($input);
                    
                case 'volume_leaders':
                    return $this->getVolumeLeaders($input);
                    
                case 'sector_performance':
                    return $this->getSectorPerformance($input);
                    
                case 'market_breadth':
                    return $this->getMarketBreadth($input);
                    
                case 'indices':
                    return $this->getIndices($input);
                    
                case 'heatmap':
                    return $this->getHeatmapData($input);
                    
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
     * Vue d'ensemble du marché
     */
    private function getMarketOverview($input) {
        $date = $input['date'] ?? null;
        
        if (!$date) {
            // Récupérer la dernière date de trading
            $sql = "SELECT MAX(trading_date) as last_date FROM stock_quotes";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        // Statistiques globales
        $statsSql = "
            SELECT 
                COUNT(DISTINCT sq.company_id) as total_companies,
                SUM(sq.volume) as total_volume,
                SUM(sq.turnover) as total_turnover,
                AVG(sq.variation_percent) as avg_variation,
                SUM(CASE WHEN sq.variation_percent > 0 THEN 1 ELSE 0 END) as gainers_count,
                SUM(CASE WHEN sq.variation_percent < 0 THEN 1 ELSE 0 END) as losers_count,
                SUM(CASE WHEN sq.variation_percent = 0 THEN 1 ELSE 0 END) as unchanged_count,
                MAX(sq.variation_percent) as max_gain,
                MIN(sq.variation_percent) as max_loss
            FROM stock_quotes sq
            INNER JOIN companies c ON sq.company_id = c.id
            WHERE sq.trading_date = ?
            AND c.active = 1
        ";

        $stats = $this->crud->executeCustomQuery($statsSql, [$date]);

        // Top 3 gainers
        $topGainers = $this->getTopN($date, 'variation_percent', 'DESC', 3);
        
        // Top 3 losers
        $topLosers = $this->getTopN($date, 'variation_percent', 'ASC', 3);
        
        // Top 3 volumes
        $volumeLeaders = $this->getTopN($date, 'volume', 'DESC', 3);

        return [
            'success' => true,
            'data' => [
                'date' => $date,
                'statistics' => $stats[0],
                'top_gainers' => $topGainers,
                'top_losers' => $topLosers,
                'volume_leaders' => $volumeLeaders
            ]
        ];
    }

    /**
     * Fonction utilitaire pour récupérer le top N
     */
    private function getTopN($date, $orderBy, $direction, $limit) {
        $sql = "
            SELECT 
                c.symbol,
                c.name,
                sq.close_price,
                sq.variation_percent,
                sq.volume,
                sq.turnover
            FROM stock_quotes sq
            INNER JOIN companies c ON sq.company_id = c.id
            WHERE sq.trading_date = ?
            AND c.active = 1
            ORDER BY sq.$orderBy $direction
            LIMIT $limit
        ";

        return $this->crud->executeCustomQuery($sql, [$date]);
    }

    /**
     * Top gainers
     */
    private function getTopGainers($input) {
        $limit = (int)($input['limit'] ?? 10);
        $date = $input['date'] ?? null;
        
        if (!$date) {
            $sql = "SELECT MAX(trading_date) as last_date FROM stock_quotes";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        $gainers = $this->getTopN($date, 'variation_percent', 'DESC', $limit);

        return [
            'success' => true,
            'data' => $gainers,
            'date' => $date,
            'count' => count($gainers)
        ];
    }

    /**
     * Top losers
     */
    private function getTopLosers($input) {
        $limit = (int)($input['limit'] ?? 10);
        $date = $input['date'] ?? null;
        
        if (!$date) {
            $sql = "SELECT MAX(trading_date) as last_date FROM stock_quotes";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        $losers = $this->getTopN($date, 'variation_percent', 'ASC', $limit);

        return [
            'success' => true,
            'data' => $losers,
            'date' => $date,
            'count' => count($losers)
        ];
    }

    /**
     * Leaders par volume
     */
    private function getVolumeLeaders($input) {
        $limit = (int)($input['limit'] ?? 10);
        $date = $input['date'] ?? null;
        
        if (!$date) {
            $sql = "SELECT MAX(trading_date) as last_date FROM stock_quotes";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        $leaders = $this->getTopN($date, 'volume', 'DESC', $limit);

        return [
            'success' => true,
            'data' => $leaders,
            'date' => $date,
            'count' => count($leaders)
        ];
    }

    /**
     * Performance par secteur
     */
    private function getSectorPerformance($input) {
        $date = $input['date'] ?? null;
        
        if (!$date) {
            $sql = "SELECT MAX(trading_date) as last_date FROM stock_quotes";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        $sql = "
            SELECT 
                s.id as sector_id,
                s.name as sector_name,
                COUNT(DISTINCT c.id) as companies_count,
                AVG(sq.variation_percent) as avg_variation,
                SUM(sq.volume) as total_volume,
                SUM(sq.turnover) as total_turnover,
                SUM(CASE WHEN sq.variation_percent > 0 THEN 1 ELSE 0 END) as gainers,
                SUM(CASE WHEN sq.variation_percent < 0 THEN 1 ELSE 0 END) as losers
            FROM sectors s
            INNER JOIN companies c ON s.id = c.sector_id
            INNER JOIN stock_quotes sq ON c.id = sq.company_id
            WHERE sq.trading_date = ?
            AND c.active = 1
            AND s.active = 1
            GROUP BY s.id, s.name
            ORDER BY avg_variation DESC
        ";

        $sectors = $this->crud->executeCustomQuery($sql, [$date]);

        return [
            'success' => true,
            'data' => $sectors ?: [],
            'date' => $date,
            'count' => count($sectors ?: [])
        ];
    }

    /**
     * Market breadth (amplitude du marché)
     */
    private function getMarketBreadth($input) {
        $days = (int)($input['days'] ?? 30);
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT 
                sq.trading_date as date,
                COUNT(DISTINCT sq.company_id) as total,
                SUM(CASE WHEN sq.variation_percent > 0 THEN 1 ELSE 0 END) as gainers,
                SUM(CASE WHEN sq.variation_percent < 0 THEN 1 ELSE 0 END) as losers,
                SUM(CASE WHEN sq.variation_percent = 0 THEN 1 ELSE 0 END) as unchanged,
                AVG(sq.variation_percent) as avg_change
            FROM stock_quotes sq
            INNER JOIN companies c ON sq.company_id = c.id
            WHERE sq.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND sq.trading_date <= ?
            AND c.active = 1
            GROUP BY sq.trading_date
            ORDER BY sq.trading_date ASC
        ";

        $breadth = $this->crud->executeCustomQuery($sql, [$endDate, $days, $endDate]);

        return [
            'success' => true,
            'data' => $breadth ?: [],
            'count' => count($breadth ?: [])
        ];
    }

    /**
     * Indices boursiers
     */
    private function getIndices($input) {
        $action = $input['sub_action'] ?? 'list';

        switch ($action) {
            case 'list':
                return $this->listIndices();
            case 'values':
                return $this->getIndexValues($input);
            case 'history':
                return $this->getIndexHistory($input);
            default:
                return $this->listIndices();
        }
    }

    /**
     * Liste des indices
     */
    private function listIndices() {
        $indices = $this->crud->find('market_indices', ['active' => 1]);

        return [
            'success' => true,
            'data' => $indices,
            'count' => count($indices)
        ];
    }

    /**
     * Valeurs actuelles des indices
     */
    private function getIndexValues($input) {
        $date = $input['date'] ?? null;
        
        if (!$date) {
            $sql = "SELECT MAX(trading_date) as last_date FROM index_values";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        $sql = "
            SELECT 
                mi.id as index_id,
                mi.code,
                mi.name,
                iv.trading_date,
                iv.open_value,
                iv.close_value,
                iv.high_value,
                iv.low_value,
                iv.variation_percent
            FROM market_indices mi
            LEFT JOIN index_values iv ON mi.id = iv.index_id AND iv.trading_date = ?
            WHERE mi.active = 1
            ORDER BY mi.code
        ";

        $values = $this->crud->executeCustomQuery($sql, [$date]);

        return [
            'success' => true,
            'data' => $values ?: [],
            'date' => $date
        ];
    }

    /**
     * Historique d'un indice
     */
    private function getIndexHistory($input) {
        $indexId = (int)($input['index_id'] ?? 0);
        $code = $input['code'] ?? '';
        
        if (!$indexId && !$code) {
            throw new Exception("ID ou code de l'indice requis");
        }

        if (!$indexId && $code) {
            $index = $this->crud->find('market_indices', ['code' => $code]);
            if (empty($index)) {
                throw new Exception("Indice non trouvé");
            }
            $indexId = $index[0]['id'];
        }

        $days = (int)($input['days'] ?? 90);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT 
                trading_date as date,
                open_value as open,
                close_value as close,
                high_value as high,
                low_value as low,
                variation_percent
            FROM index_values
            WHERE index_id = ?
        ";

        $params = [$indexId];

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

        $history = $this->crud->executeCustomQuery($sql, $params);

        return [
            'success' => true,
            'data' => $history ?: [],
            'count' => count($history ?: []),
            'index_id' => $indexId
        ];
    }

    /**
     * Données pour heatmap
     */
    private function getHeatmapData($input) {
        $date = $input['date'] ?? null;
        
        if (!$date) {
            $sql = "SELECT MAX(trading_date) as last_date FROM stock_quotes";
            $result = $this->crud->executeCustomQuery($sql);
            $date = $result[0]['last_date'] ?? date('Y-m-d');
        }

        $sql = "
            SELECT 
                c.symbol,
                c.name,
                s.name as sector,
                sq.close_price,
                sq.variation_percent,
                sq.volume,
                sq.turnover,
                c.market_cap
            FROM stock_quotes sq
            INNER JOIN companies c ON sq.company_id = c.id
            LEFT JOIN sectors s ON c.sector_id = s.id
            WHERE sq.trading_date = ?
            AND c.active = 1
            ORDER BY s.name, c.symbol
        ";

        $data = $this->crud->executeCustomQuery($sql, [$date]);

        // Organiser par secteur
        $organized = [];
        foreach ($data as $item) {
            $sector = $item['sector'] ?? 'Non classifié';
            if (!isset($organized[$sector])) {
                $organized[$sector] = [];
            }
            $organized[$sector][] = $item;
        }

        return [
            'success' => true,
            'data' => $organized,
            'date' => $date,
            'total_stocks' => count($data)
        ];
    }
}

// Exécution
$api = new MarketAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);