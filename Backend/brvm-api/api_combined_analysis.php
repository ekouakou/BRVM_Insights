<?php
/**
 * API d'analyse IA combinée : rapports de sociétés ET bulletins de marché
 * sélectionnés librement ensemble.
 * Endpoint: api_combined_analysis.php
 *
 * Mirror de api_report_comparison.php / api_bulletin_comparison.php, mais
 * pour un ensemble mixte (délègue à class/CombinedAnalysisService.php).
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
require_once 'class/ReportAnalysisService.php';
require_once 'class/MarketBulletinAnalysisService.php';
require_once 'class/CombinedAnalysisService.php';

class CombinedAnalysisAPI {
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

                case 'history':
                    return $this->history($input);

                case 'rate':
                    return $this->rate($input);

                case 'delete':
                    return $this->delete($input);

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

    private function compare($input) {
        [$reportIds, $bulletinIds] = $this->resolveIds($input);

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new CombinedAnalysisService($this->crud);

        try {
            $result = $service->compare($reportIds, $bulletinIds, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/données manquantes : pas un crash serveur
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function get($input) {
        [$reportIds, $bulletinIds] = $this->resolveIds($input);

        $service = new CombinedAnalysisService($this->crud);
        $result = $service->getLatest($reportIds, $bulletinIds);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune analyse combinée en cache pour ces critères"];
        }

        return ['success' => true, 'data' => $result];
    }

    private function history($input) {
        [$reportIds, $bulletinIds] = $this->resolveIds($input);

        $service = new CombinedAnalysisService($this->crud);
        $data = $service->history($reportIds, $bulletinIds);

        return ['success' => true, 'data' => $data, 'count' => count($data)];
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse combinée
     * déjà enregistrée — rating/notes ne sont modifiés que s'ils sont
     * explicitement présents dans le payload.
     */
    private function rate($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("id requis");
        }

        $ratingProvided = array_key_exists('rating', $input);
        $notesProvided = array_key_exists('notes', $input);
        $rating = $ratingProvided ? ($input['rating'] !== null ? (int) $input['rating'] : null) : null;
        $notes = $notesProvided ? $input['notes'] : null;

        $service = new CombinedAnalysisService($this->crud);
        $result = $service->rate($id, $rating, $notes, $ratingProvided, $notesProvided);

        return ['success' => true, 'data' => $result];
    }

    /**
     * Supprime une analyse combinée enregistrée.
     */
    private function delete($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("id requis");
        }

        $service = new CombinedAnalysisService($this->crud);
        $service->remove($id);

        return ['success' => true];
    }

    private function resolveIds($input): array {
        $reportIds = $input['report_ids'] ?? [];
        $bulletinIds = $input['bulletin_ids'] ?? [];
        if (empty($reportIds) || empty($bulletinIds)) {
            throw new Exception("report_ids et bulletin_ids requis (au moins un de chaque)");
        }
        return [array_map('intval', $reportIds), array_map('intval', $bulletinIds)];
    }
}

// Exécution
$api = new CombinedAnalysisAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
