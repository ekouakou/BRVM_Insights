<?php
/**
 * API de suivi des dividendes et opérations sur titres extraites des
 * Bulletins Officiels de la Cote (BOC)
 * Endpoint: api_bulletin_corporate_actions.php
 *
 * Mirror de api_bulletin_analysis.php, mais pour une extraction structurée
 * (une ligne par opération) plutôt qu'un résumé de séance en texte libre —
 * délègue à class/BulletinCorporateActionsService.php.
 * Voir TODO_ANALYSES.md, point 12.
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
require_once 'class/CompanySlugMatcher.php';
require_once 'class/BulletinCorporateActionsService.php';

class BulletinCorporateActionsAPI {
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
     * Déclenche (ou réutilise le cache pour) l'extraction des opérations sur
     * titres d'un bulletin.
     */
    private function extract($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new BulletinCorporateActionsService($this->crud);

        try {
            $result = $service->extract($bulletinId, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur, juste un échec d'extraction
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Vue calendrier de toutes les opérations déjà extraites, filtrable par
     * entreprise/type/période. Inclut aussi la liste des bulletins dont le
     * texte est disponible mais qui n'ont pas encore été extraits.
     */
    private function list($input) {
        $filters = [
            'company_id' => !empty($input['company_id']) ? (int) $input['company_id'] : null,
            'action_type' => $input['action_type'] ?? null,
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
        ];

        $service = new BulletinCorporateActionsService($this->crud);
        $data = $service->listActions($filters);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Lecture cache-only des opérations déjà extraites pour un bulletin.
     */
    private function get($input) {
        $bulletinId = (int) ($input['bulletin_id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("bulletin_id requis");
        }

        $service = new BulletinCorporateActionsService($this->crud);
        $data = $service->getStoredActions($bulletinId);

        return ['success' => true, 'data' => $data];
    }
}

// Exécution
$api = new BulletinCorporateActionsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
