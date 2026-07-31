<?php
/**
 * API de gestion des cotations boursières
 * Endpoint: api_quotes.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';

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
     * Compare plusieurs entreprises
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

        // Organiser les données par entreprise
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

        return [
            'success' => true,
            'data' => array_values($organized),
            'companies_count' => count($organized)
        ];
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
}

// Exécution
$api = new QuotesAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);