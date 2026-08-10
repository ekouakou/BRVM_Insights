<?php
/**
 * API du chat bot IA du tableau de bord entreprise
 * Endpoint: api_company_chat.php
 *
 * Miroir de api_chart_analysis.php côté structure (dispatch d'action, un
 * seul service), mais conversation continue par entreprise plutôt
 * qu'analyses versionnées par sélection de paramètres — voir
 * class/CompanyChatService.php.
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
require_once 'class/CompanyChatService.php';

class CompanyChatAPI {
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

                case 'send':
                    return $this->send($input);

                case 'clear':
                    return $this->clear($input);

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
     * Historique complet de la conversation d'une entreprise.
     */
    private function list($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $service = new CompanyChatService($this->crud);
        $data = $service->listMessages($companyId);

        return ['success' => true, 'data' => $data, 'count' => count($data)];
    }

    /**
     * Envoie un message utilisateur et retourne la réponse de l'IA
     * (données du tableau de bord + recherche internet native du fournisseur).
     */
    private function send($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $company = $input['company'] ?? [];
        $dashboardData = $input['dashboard_data'] ?? [];
        $message = $input['message'] ?? '';

        if (!$companyId) {
            throw new Exception("company_id requis");
        }
        if (!is_array($company)) {
            throw new Exception("company doit être un objet");
        }
        if (!is_array($dashboardData)) {
            throw new Exception("dashboard_data doit être un objet");
        }
        if (!is_string($message) || trim($message) === '') {
            throw new Exception("message requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;

        $service = new CompanyChatService($this->crud);

        try {
            $result = $service->sendMessage($companyId, $company, $dashboardData, $message, $provider, $model);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur, juste un échec de réponse
            // (le message utilisateur reste persisté même si la réponse échoue, voir CompanyChatService::sendMessage()).
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Efface toute la conversation d'une entreprise (remise à zéro du chat).
     */
    private function clear($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $service = new CompanyChatService($this->crud);
        $service->clearConversation($companyId);

        return ['success' => true];
    }
}

// Exécution
$api = new CompanyChatAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
