<?php
/**
 * API de gestion des entreprises cotées
 * Endpoint: api_companies.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class CompaniesAPI {
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
                    return $this->listCompanies($input);
                    
                case 'get':
                    return $this->getCompany($input);
                    
                case 'search':
                    return $this->searchCompanies($input);
                    
                case 'by_sector':
                    return $this->getCompaniesBySector($input);
                    
                case 'by_country':
                    return $this->getCompaniesByCountry($input);
                    
                case 'create':
                    return $this->createCompany($input);
                    
                case 'update':
                    return $this->updateCompany($input);
                    
                case 'delete':
                    return $this->deleteCompany($input);
                    
                case 'stats':
                    return $this->getCompanyStats($input);
                    
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
     * Liste toutes les entreprises avec pagination
     */
    private function listCompanies($input) {
        $page = (int)($input['page'] ?? 1);
        $perPage = (int)($input['per_page'] ?? 20);
        
        $where = [];
        
        if (isset($input['active'])) {
            $where['active'] = (int)$input['active'];
        }

        // Utiliser une jointure pour inclure les informations du secteur et pays
        $tables = ['c' => 'companies'];
        $joins = [
            [
                'type' => 'LEFT',
                'table' => 'sectors',
                'alias' => 's',
                'on' => 'c.sector_id = s.id'
            ],
            [
                'type' => 'LEFT',
                'table' => 'countries',
                'alias' => 'co',
                'on' => 'c.country_id = co.id'
            ]
        ];
        
        $fields = [
            'company_id' => 'c.id',
            'symbol' => 'c.symbol',
            'name' => 'c.name',
            'full_name' => 'c.full_name',
            'sector_id' => 'c.sector_id',
            'sector_name' => 's.name',
            'country_id' => 'c.country_id',
            'country_name' => 'co.name',
            'country_code' => 'co.code',
            'isin_code' => 'c.isin_code',
            'market_cap' => 'c.market_cap',
            'shares_outstanding' => 'c.shares_outstanding',
            'listing_date' => 'c.listing_date',
            'website' => 'c.website',
            'logo_url' => 'c.logo_url',
            'active' => 'c.active',
            'created_at' => 'c.created_at'
        ];
        
        $whereConditions = [];
        if (!empty($where)) {
            foreach ($where as $field => $value) {
                $whereConditions["c.$field = ?"] = $value;
            }
        }
        
        $orderBy = ['c.symbol' => 'ASC'];
        
        $limit = $perPage;
        $offset = ($page - 1) * $perPage;
        
        $companies = $this->crud->findWithJoin($tables, $joins, $fields, $whereConditions, $orderBy, $limit, $offset);
        
        // Compter le total
        $total = $this->crud->countWithJoin($tables, $joins, $whereConditions);
        
        return [
            'success' => true,
            'data' => $companies,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage)
            ]
        ];
    }

    /**
     * Récupère les détails d'une entreprise
     */
    private function getCompany($input) {
        $companyId = (int)($input['id'] ?? 0);
        $symbol = $input['symbol'] ?? '';
        
        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole requis");
        }

        $where = $companyId ? ['c.id = ?' => $companyId] : ['c.symbol = ?' => $symbol];

        $tables = ['c' => 'companies'];
        $joins = [
            [
                'type' => 'LEFT',
                'table' => 'sectors',
                'alias' => 's',
                'on' => 'c.sector_id = s.id'
            ],
            [
                'type' => 'LEFT',
                'table' => 'countries',
                'alias' => 'co',
                'on' => 'c.country_id = co.id'
            ]
        ];
        
        $fields = [
            'company_id' => 'c.id',
            'symbol' => 'c.symbol',
            'name' => 'c.name',
            'full_name' => 'c.full_name',
            'description' => 'c.description',
            'sector_id' => 'c.sector_id',
            'sector_name' => 's.name',
            'country_id' => 'c.country_id',
            'country_name' => 'co.name',
            'country_code' => 'co.code',
            'isin_code' => 'c.isin_code',
            'market_cap' => 'c.market_cap',
            'shares_outstanding' => 'c.shares_outstanding',
            'listing_date' => 'c.listing_date',
            'website' => 'c.website',
            'logo_url' => 'c.logo_url',
            'active' => 'c.active',
            'created_at' => 'c.created_at',
            'updated_at' => 'c.updated_at'
        ];

        $company = $this->crud->findWithJoin($tables, $joins, $fields, $where, [], 1);
        
        if (empty($company)) {
            throw new Exception("Entreprise non trouvée");
        }

        // Récupérer la dernière cotation
        $lastQuote = $this->crud->findAll(
            'stock_quotes',
            'trading_date',
            'DESC',
            1,
            null,
            PDO::FETCH_ASSOC
        );

        return [
            'success' => true,
            'data' => array_merge($company[0], [
                'last_quote' => !empty($lastQuote) ? $lastQuote[0] : null
            ])
        ];
    }

    /**
     * Recherche des entreprises
     */
    private function searchCompanies($input) {
        $query = trim($input['query'] ?? '');
        
        if (empty($query)) {
            throw new Exception("Terme de recherche requis");
        }

        $searchTerm = '%' . $query . '%';
        
        $sql = "
            SELECT 
                c.id AS company_id,
                c.symbol,
                c.name,
                c.full_name,
                s.name AS sector_name,
                co.name AS country_name,
                c.active
            FROM companies c
            LEFT JOIN sectors s ON c.sector_id = s.id
            LEFT JOIN countries co ON c.country_id = co.id
            WHERE (
                c.symbol LIKE ? 
                OR c.name LIKE ? 
                OR c.full_name LIKE ?
            )
            AND c.active = 1
            ORDER BY c.symbol ASC
            LIMIT 50
        ";

        $results = $this->crud->executeCustomQuery($sql, [$searchTerm, $searchTerm, $searchTerm]);

        return [
            'success' => true,
            'data' => $results ?: [],
            'count' => count($results ?: [])
        ];
    }

    /**
     * Entreprises par secteur
     */
    private function getCompaniesBySector($input) {
        $sectorId = (int)($input['sector_id'] ?? 0);
        
        if (!$sectorId) {
            throw new Exception("ID du secteur requis");
        }

        $companies = $this->crud->find('companies', ['sector_id' => $sectorId, 'active' => 1]);

        return [
            'success' => true,
            'data' => $companies,
            'count' => count($companies)
        ];
    }

    /**
     * Entreprises par pays
     */
    private function getCompaniesByCountry($input) {
        $countryId = (int)($input['country_id'] ?? 0);
        
        if (!$countryId) {
            throw new Exception("ID du pays requis");
        }

        $companies = $this->crud->find('companies', ['country_id' => $countryId, 'active' => 1]);

        return [
            'success' => true,
            'data' => $companies,
            'count' => count($companies)
        ];
    }

    /**
     * Créer une entreprise
     */
    private function createCompany($input) {
        if (empty($input['symbol']) || empty($input['name'])) {
            throw new Exception("Symbole et nom requis");
        }

        // Vérifier si le symbole existe déjà
        if ($this->crud->exists('companies', ['symbol' => $input['symbol']])) {
            throw new Exception("Une entreprise avec ce symbole existe déjà");
        }

        $data = [
            'symbol' => strtoupper(trim($input['symbol'])),
            'name' => trim($input['name']),
            'full_name' => trim($input['full_name'] ?? $input['name']),
            'sector_id' => (int)($input['sector_id'] ?? 0) ?: null,
            'country_id' => (int)($input['country_id'] ?? 0) ?: null,
            'isin_code' => trim($input['isin_code'] ?? ''),
            'market_cap' => (float)($input['market_cap'] ?? 0) ?: null,
            'shares_outstanding' => (int)($input['shares_outstanding'] ?? 0) ?: null,
            'listing_date' => $input['listing_date'] ?? null,
            'website' => trim($input['website'] ?? ''),
            'description' => trim($input['description'] ?? ''),
            'logo_url' => trim($input['logo_url'] ?? ''),
            'active' => (int)($input['active'] ?? 1)
        ];

        $companyId = $this->crud->persist('companies', $data);

        if (!$companyId) {
            throw new Exception("Erreur lors de la création de l'entreprise");
        }

        return [
            'success' => true,
            'message' => 'Entreprise créée avec succès',
            'company_id' => $companyId
        ];
    }

    /**
     * Mettre à jour une entreprise
     */
    private function updateCompany($input) {
        $companyId = (int)($input['id'] ?? 0);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        if (!$this->crud->exists('companies', ['id' => $companyId])) {
            throw new Exception("Entreprise non trouvée");
        }

        $data = [];
        
        $allowedFields = [
            'name', 'full_name', 'sector_id', 'country_id', 'isin_code',
            'market_cap', 'shares_outstanding', 'listing_date', 'website',
            'description', 'logo_url', 'active'
        ];

        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $data[$field] = $input[$field];
            }
        }

        if (empty($data)) {
            throw new Exception("Aucune donnée à mettre à jour");
        }

        $result = $this->crud->merge('companies', $data, ['id' => $companyId]);

        if ($result === false) {
            throw new Exception("Erreur lors de la mise à jour");
        }

        return [
            'success' => true,
            'message' => 'Entreprise mise à jour avec succès'
        ];
    }

    /**
     * Supprimer une entreprise
     */
    private function deleteCompany($input) {
        $companyId = (int)($input['id'] ?? 0);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        // Soft delete - marquer comme inactive
        $result = $this->crud->merge('companies', ['active' => 0], ['id' => $companyId]);

        if ($result === false) {
            throw new Exception("Erreur lors de la suppression");
        }

        return [
            'success' => true,
            'message' => 'Entreprise désactivée avec succès'
        ];
    }

    /**
     * Statistiques d'une entreprise
     */
    private function getCompanyStats($input) {
        $companyId = (int)($input['id'] ?? 0);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        // Statistiques générales
        $sql = "
            SELECT 
                COUNT(*) as trading_days,
                MIN(close_price) as min_price,
                MAX(close_price) as max_price,
                AVG(close_price) as avg_price,
                SUM(volume) as total_volume,
                SUM(turnover) as total_turnover,
                MIN(trading_date) as first_date,
                MAX(trading_date) as last_date
            FROM stock_quotes
            WHERE company_id = ?
        ";

        $stats = $this->crud->executeCustomQuery($sql, [$companyId]);

        return [
            'success' => true,
            'data' => !empty($stats) ? $stats[0] : null
        ];
    }
}

// Exécution
$api = new CompaniesAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);