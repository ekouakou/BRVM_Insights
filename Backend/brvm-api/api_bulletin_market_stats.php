<?php
/**
 * API du volume et de la valeur transigés du marché des actions, extraits
 * (déterministe, sans IA) du tableau « Statistiques du marché » des
 * Bulletins Officiels de la Cote (BOC)
 * Endpoint: api_bulletin_market_stats.php
 *
 * Mirror léger de api_bulletin_stock_metrics.php / api_bulletin_bond_metrics.php,
 * mais une ligne par bulletin (pas par valeur/obligation) — délègue à
 * class/BulletinMarketStatsService.php.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();
require_once 'class/BulletinMarketStatsService.php';

class BulletinMarketStatsAPI {
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
                    return $this->list($input);

                case 'extract_all':
                    return $this->extractAll($input);

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
     * Série des statistiques marché déjà extraites sur une période choisie,
     * plus les bulletins encore en attente.
     */
    private function list($input) {
        $endDate = $input['end_date'] ?? date('Y-m-d');
        $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-90 days'));

        $service = new BulletinMarketStatsService($this->crud);
        $data = $service->list($startDate, $endDate);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Extraction déterministe (regex, pas d'IA) de tous les bulletins en
     * attente en un seul appel — même principe que
     * api_order_book.php::parseBulletins().
     */
    private function extractAll($input) {
        $service = new BulletinMarketStatsService($this->crud);
        $results = $service->extractAll(!empty($input['force']));

        $ok = 0;
        $failed = 0;
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'success') {
                $ok++;
            } else {
                $failed++;
            }
        }

        return ['success' => true, 'data' => ['bulletins_ok' => $ok, 'bulletins_failed' => $failed, 'results' => $results]];
    }
}

// Exécution
$api = new BulletinMarketStatsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
