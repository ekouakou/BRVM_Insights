<?php
/**
 * API du PER et rendement net officiels BRVM, extraits du tableau par
 * valeur des Bulletins Officiels de la Cote (BOC)
 * Endpoint: api_bulletin_stock_metrics.php
 *
 * Mirror de api_bulletin_corporate_actions.php, mais pour les métriques par
 * valeur (PER, cours, rendement) plutôt que les opérations sur titres —
 * délègue à class/BulletinStockMetricsService.php.
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
require_once 'class/AiChatClientInterface.php';
require_once 'class/GeminiClient.php';
require_once 'class/AnthropicClient.php';
require_once 'class/GrokClient.php';
require_once 'class/BulletinStockMetricsService.php';

class BulletinStockMetricsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'extract':
                    return $this->extract($input);

                case 'list':
                    return $this->list($input);

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
     * Déclenche (ou réutilise le cache pour) l'extraction des métriques par
     * valeur (PER, cours, rendement net) d'un bulletin.
     */
    private function extract($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new BulletinStockMetricsService($this->crud);

        try {
            $result = $service->extract($bulletinId, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur, juste un échec d'extraction
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Vue d'ensemble de toutes les métriques déjà extraites, filtrable par
     * entreprise/période. Inclut aussi la liste des bulletins dont le texte
     * est disponible mais qui n'ont pas encore été extraits.
     */
    private function list($input) {
        $filters = [
            'company_id' => !empty($input['company_id']) ? (int) $input['company_id'] : null,
            'bulletin_id' => !empty($input['bulletin_id']) ? (int) $input['bulletin_id'] : null,
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
        ];

        $service = new BulletinStockMetricsService($this->crud);
        $data = $service->listMetrics($filters);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Lecture cache-only des métriques déjà extraites pour un bulletin.
     */
    private function get($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $service = new BulletinStockMetricsService($this->crud);
        $data = $service->getStoredMetrics($bulletinId);

        return ['success' => true, 'data' => $data];
    }
}

// Exécution
$api = new BulletinStockMetricsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
