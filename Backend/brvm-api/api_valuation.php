<?php
/**
 * API de valorisation intrinsèque d'une entreprise
 * Endpoint: api_valuation.php
 *
 * DCF, DDM, ROIC/EVA et WACC — voir class/ValuationModelService.php pour la
 * méthodologie et les hypothèses de marché utilisées. Reçoit en entrée la
 * fiche de ratios déjà calculée par api_fundamentals.php (action 'list')
 * plutôt que de la recalculer, pour ne jamais diverger de ce que
 * l'utilisateur voit déjà affiché à l'écran.
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
require_once 'class/ReturnsCalculator.php';
require_once 'class/ValuationModelService.php';

class ValuationAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'compute':
                    return $this->compute($input);

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

    private function compute($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $fundamentals = $input['fundamentals'] ?? null;
        if (!is_array($fundamentals)) {
            throw new Exception("fundamentals requis (la ligne déjà calculée par api_fundamentals.php, action 'list')");
        }

        $service = new ValuationModelService($this->crud);
        $result = $service->compute($companyId, $fundamentals);

        return ['success' => true, 'data' => $result];
    }
}

// Exécution
$api = new ValuationAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
