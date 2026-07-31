<?php
/**
 * API de synchronisation des données BRVM
 * Endpoint: api_brvm_sync.php
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
require_once 'class/BRVMScraperFixed.php';
require_once 'class/BRVMSyncService.php';
require_once 'class/TechnicalIndicatorsCalculator.php';

class BRVMSyncAPI {
    private $crud;
    private $scraper;
    private $syncService;
    private $indicatorsCalculator;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
        $this->scraper = new BRVMScraperFixed();
        $this->syncService = new BRVMSyncService($this->crud, $this->scraper);
        $this->indicatorsCalculator = new TechnicalIndicatorsCalculator($this->crud);
    }

    /**
     * Point d'entrée principal
     */
    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'sync_now':
                    return $this->syncNow($input);
                    
                case 'sync_status':
                    return $this->getSyncStatus($input);
                    
                case 'sync_history':
                    return $this->getSyncHistory($input);
                    
                case 'check_market_status':
                    return $this->checkMarketStatus();
                    
                default:
                    throw new Exception("Action non reconnue: $action");
            }
        } catch (Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Synchronise immédiatement les données (cotations + indices) et recalcule
     * les indicateurs techniques des entreprises mises à jour
     */
    private function syncNow($input) {
        $startTime = microtime(true);
        $result = [
            'success' => true,
            'message' => 'Synchronisation terminée',
            'quotes' => null,
            'indices' => null,
            'indicators_recomputed' => 0
        ];
        $hadError = false;

        try {
            $quoteStats = $this->syncService->syncQuotes();
            $result['quotes'] = $quoteStats;

            $this->crud->persist('sync_logs', [
                'sync_type' => 'quotes',
                'sync_status' => ($quoteStats['failed'] > 0) ? 'partial' : 'success',
                'records_processed' => $quoteStats['processed'],
                'records_inserted' => $quoteStats['inserted'],
                'records_updated' => $quoteStats['updated'],
                'records_failed' => $quoteStats['failed'],
                'error_message' => !empty($quoteStats['errors']) ? json_encode($quoteStats['errors']) : null,
                'started_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s')
            ]);

            $today = date('Y-m-d');
            foreach ($quoteStats['touched_company_ids'] as $companyId) {
                $this->indicatorsCalculator->computeAndPersist($companyId, $today);
                $result['indicators_recomputed']++;
            }
        } catch (Exception $e) {
            $hadError = true;
            $result['quotes'] = ['error' => $e->getMessage()];
            $this->crud->persist('sync_logs', [
                'sync_type' => 'quotes',
                'sync_status' => 'failed',
                'error_message' => $e->getMessage(),
                'started_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s')
            ]);
        }

        try {
            $indexStats = $this->syncService->syncIndices();
            $result['indices'] = $indexStats;

            $this->crud->persist('sync_logs', [
                'sync_type' => 'indices',
                'sync_status' => ($indexStats['failed'] > 0) ? 'partial' : 'success',
                'records_processed' => $indexStats['processed'],
                'records_inserted' => $indexStats['inserted'],
                'records_updated' => $indexStats['updated'],
                'records_failed' => $indexStats['failed'],
                'error_message' => !empty($indexStats['errors']) ? json_encode($indexStats['errors']) : null,
                'started_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $hadError = true;
            $result['indices'] = ['error' => $e->getMessage()];
            $this->crud->persist('sync_logs', [
                'sync_type' => 'indices',
                'sync_status' => 'failed',
                'error_message' => $e->getMessage(),
                'started_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s')
            ]);
        }

        $result['success'] = !$hadError;
        $result['execution_time'] = round(microtime(true) - $startTime, 2);

        return $result;
    }

    /**
     * Récupère le statut de la dernière synchronisation
     */
    private function getSyncStatus($input) {
        $lastSync = $this->crud->findAll('sync_logs', 'id', 'DESC', 1);
        
        if (empty($lastSync)) {
            return [
                'success' => true,
                'message' => 'Aucune synchronisation effectuée',
                'last_sync' => null
            ];
        }

        return [
            'success' => true,
            'last_sync' => $lastSync[0]
        ];
    }

    /**
     * Récupère l'historique des synchronisations
     */
    private function getSyncHistory($input) {
        $page = (int)($input['page'] ?? 1);
        $perPage = (int)($input['per_page'] ?? 20);
        
        $where = [];
        
        if (!empty($input['sync_type'])) {
            $where['sync_type'] = $input['sync_type'];
        }
        
        if (!empty($input['sync_status'])) {
            $where['sync_status'] = $input['sync_status'];
        }

        $result = $this->crud->paginate(
            'sync_logs',
            $page,
            $perPage,
            $where,
            ['started_at' => 'DESC']
        );

        return [
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta']
        ];
    }

    /**
     * Vérifie si le marché est ouvert
     */
    private function checkMarketStatus() {
        // Récupérer la configuration
        $config = $this->getSystemConfig();
        
        $timezone = new DateTimeZone($config['timezone'] ?? 'Africa/Abidjan');
        $now = new DateTime('now', $timezone);
        
        $currentTime = $now->format('H:i');
        $currentDay = strtolower($now->format('l'));
        
        $tradingDays = explode(',', $config['trading_days'] ?? 'monday,tuesday,wednesday,thursday,friday');
        
        $isOpenTime = ($currentTime >= $config['market_open_time'] && 
                      $currentTime <= $config['market_close_time']);
        
        $isTradingDay = in_array($currentDay, $tradingDays);
        
        $isOpen = $isOpenTime && $isTradingDay;

        return [
            'success' => true,
            'market_status' => [
                'is_open' => $isOpen,
                'current_time' => $currentTime,
                'current_day' => $currentDay,
                'market_open_time' => $config['market_open_time'],
                'market_close_time' => $config['market_close_time'],
                'next_sync' => $this->calculateNextSync($config, $now)
            ]
        ];
    }

    /**
     * Calcule le prochain temps de synchronisation
     */
    private function calculateNextSync($config, $now) {
        $syncInterval = (int)($config['sync_interval_minutes'] ?? 5);
        $nextSync = clone $now;
        $nextSync->modify("+{$syncInterval} minutes");
        
        return $nextSync->format('Y-m-d H:i:s');
    }

    /**
     * Récupère la configuration système
     */
    private function getSystemConfig() {
        $configs = $this->crud->findAll('system_config');
        $result = [];
        
        foreach ($configs as $config) {
            $result[$config['config_key']] = $config['config_value'];
        }
        
        return $result;
    }
}

// Exécution
$api = new BRVMSyncAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);