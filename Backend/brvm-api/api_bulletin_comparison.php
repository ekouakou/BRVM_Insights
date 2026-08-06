<?php
/**
 * API de comparaison de Bulletins Officiels de la Cote (BOC)
 * Endpoint: api_bulletin_comparison.php
 *
 * Mirror de api_report_comparison.php, mais pour un ensemble de bulletins de
 * marché sélectionnés (délègue à class/BulletinComparisonService.php).
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
require_once 'class/GrokClient.php';
require_once 'class/MarketBulletinAnalysisService.php';
require_once 'class/BulletinComparisonService.php';

class BulletinComparisonAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'compare':
                    return $this->compare($input);

                case 'get':
                    return $this->get($input);

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
     * Déclenche (ou réutilise le cache du jour pour) une comparaison de bulletins.
     */
    private function compare($input) {
        $bulletinIds = $this->resolveBulletinIds($input);

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new BulletinComparisonService($this->crud);

        try {
            $result = $service->compare($bulletinIds, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/données manquantes : pas un crash serveur
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dernière comparaison en cache pour cet ensemble de bulletins, sans
     * jamais appeler l'IA.
     */
    private function get($input) {
        $bulletinIds = $this->resolveBulletinIds($input);

        $service = new BulletinComparisonService($this->crud);
        $result = $service->getLatest($bulletinIds);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune comparaison en cache pour ces critères"];
        }

        return ['success' => true, 'data' => $result];
    }

    private function resolveBulletinIds($input): array {
        $bulletinIds = $input['bulletin_ids'] ?? [];
        if (empty($bulletinIds)) {
            throw new Exception("bulletin_ids requis");
        }
        return array_map('intval', $bulletinIds);
    }
}

// Exécution
$api = new BulletinComparisonAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
