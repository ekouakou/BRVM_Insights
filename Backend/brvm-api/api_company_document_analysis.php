<?php
/**
 * API d'analyse IA des documents complémentaires (multi-fournisseurs)
 * Endpoint: api_company_document_analysis.php
 *
 * Miroir de api_report_analysis.php (même schéma d'analyse, même
 * fonctionnement de cache), appliqué aux documents ajoutés manuellement
 * (company_documents) plutôt qu'aux rapports officiels scrapés depuis
 * brvm.org — voir class/CompanyDocumentAnalysisService.php.
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
require_once 'class/CompanyDocumentAnalysisService.php';

class CompanyDocumentAnalysisAPI {
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

    /**
     * Déclenche (ou réutilise le cache pour) une analyse IA d'un document complémentaire.
     */
    private function analyze($input) {
        $documentId = (int) ($input['document_id'] ?? 0);
        if (!$documentId) {
            throw new Exception("document_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new CompanyDocumentAnalysisService($this->crud);

        try {
            $result = $service->analyze($documentId, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dernière analyse en cache d'un document, sans jamais appeler de fournisseur IA.
     */
    private function get($input) {
        $documentId = (int) ($input['document_id'] ?? 0);
        if (!$documentId) {
            throw new Exception("document_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;

        $service = new CompanyDocumentAnalysisService($this->crud);
        $result = $service->getLatest($documentId, $provider, $model);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune analyse en cache pour ce document"];
        }

        return ['success' => true, 'data' => $result];
    }

    /**
     * Historique des analyses d'un document ou d'une société.
     */
    private function history($input) {
        $documentId = !empty($input['document_id']) ? (int) $input['document_id'] : null;
        $companyId = !empty($input['company_id']) ? (int) $input['company_id'] : null;

        $service = new CompanyDocumentAnalysisService($this->crud);
        $data = $service->history($documentId, $companyId);

        return ['success' => true, 'data' => $data, 'count' => count($data)];
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse déjà
     * enregistrée — rating/notes ne sont modifiés que s'ils sont
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

        $service = new CompanyDocumentAnalysisService($this->crud);
        $result = $service->rate($id, $rating, $notes, $ratingProvided, $notesProvided);

        return ['success' => true, 'data' => $result];
    }

    /**
     * Supprime une analyse enregistrée.
     */
    private function delete($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("id requis");
        }

        $service = new CompanyDocumentAnalysisService($this->crud);
        $service->remove($id);

        return ['success' => true];
    }
}

// Exécution
$api = new CompanyDocumentAnalysisAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
