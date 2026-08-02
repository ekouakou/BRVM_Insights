<?php
/**
 * API d'analyse IA des Bulletins Officiels de la Cote (BOC)
 * Endpoint: api_bulletin_analysis.php
 *
 * Mirror de api_report_analysis.php, mais pour un bulletin de marché
 * quotidien (délègue à class/MarketBulletinAnalysisService.php).
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
require_once 'class/AiClientInterface.php';
require_once 'class/GeminiClient.php';
require_once 'class/AnthropicClient.php';
require_once 'class/MarketBulletinAnalysisService.php';

class BulletinAnalysisAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'analyze':
                    return $this->analyze($input);

                case 'get':
                    return $this->get($input);

                case 'history':
                    return $this->history($input);

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
     * Déclenche (ou réutilise le cache pour) une analyse IA d'un bulletin.
     */
    private function analyze($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new MarketBulletinAnalysisService($this->crud);

        try {
            $result = $service->analyze($bulletinId, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur, juste un échec d'analyse
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dernière analyse en cache d'un bulletin, sans jamais appeler de fournisseur IA.
     */
    private function get($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;

        $service = new MarketBulletinAnalysisService($this->crud);
        $result = $service->getLatest($bulletinId, $provider, $model);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune analyse en cache pour ce bulletin"];
        }

        return ['success' => true, 'data' => $result];
    }

    /**
     * Historique des analyses d'un bulletin.
     */
    private function history($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $service = new MarketBulletinAnalysisService($this->crud);
        $data = $service->history($bulletinId);

        return ['success' => true, 'data' => $data, 'count' => count($data)];
    }
}

// Exécution
$api = new BulletinAnalysisAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
