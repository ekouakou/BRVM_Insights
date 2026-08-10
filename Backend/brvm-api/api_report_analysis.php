<?php
/**
 * API d'analyse IA des rapports de sociétés cotées (multi-fournisseurs)
 * Endpoint: api_report_analysis.php
 *
 * Envoie le texte extrait d'un rapport (voir api_reports.php) + le contexte
 * marché récent (cours, indicateurs techniques) à un fournisseur IA
 * (Anthropic par défaut, ou Gemini via "provider": "gemini"), et persiste un
 * résultat structuré et expliqué dans company_report_analyses. Le résultat
 * est mis en cache par jour (par fournisseur+modèle) : ré-appeler 'analyze'
 * le même jour ne refacture pas l'IA, sauf avec force_refresh.
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
require_once 'class/ReportAnalysisService.php';

class ReportAnalysisAPI {
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

                case 'stats':
                    return $this->stats($input);

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
     * Déclenche (ou réutilise le cache du jour pour) une analyse IA d'un rapport.
     */
    private function analyze($input) {
        $reportId = (int) ($input['report_id'] ?? 0);
        if (!$reportId) {
            throw new Exception("report_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new ReportAnalysisService($this->crud);

        try {
            $result = $service->analyze($reportId, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur, juste un échec d'analyse
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dernière analyse en cache d'un rapport, sans jamais appeler de fournisseur IA.
     */
    private function get($input) {
        $reportId = (int) ($input['report_id'] ?? 0);
        if (!$reportId) {
            throw new Exception("report_id requis");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;

        $service = new ReportAnalysisService($this->crud);
        $result = $service->getLatest($reportId, $provider, $model);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune analyse en cache pour ce rapport"];
        }

        return ['success' => true, 'data' => $result];
    }

    /**
     * Historique des analyses d'un rapport ou d'une société.
     */
    private function history($input) {
        $reportId = !empty($input['report_id']) ? (int) $input['report_id'] : null;
        $companyId = !empty($input['company_id']) ? (int) $input['company_id'] : null;

        $service = new ReportAnalysisService($this->crud);
        $data = $service->history($reportId, $companyId);

        return ['success' => true, 'data' => $data, 'count' => count($data)];
    }

    /**
     * Statistiques agrégées des analyses IA déjà réalisées pour une
     * entreprise (répartition des verdicts, catégories de risque, tendance
     * financière) — voir ReportAnalysisService::getCompanyAnalysisStats().
     */
    private function stats($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }
        $includeDocuments = !empty($input['include_documents']);

        $service = new ReportAnalysisService($this->crud);
        $data = $service->getCompanyAnalysisStats($companyId, $includeDocuments);

        return ['success' => true, 'data' => $data];
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

        $service = new ReportAnalysisService($this->crud);
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

        $service = new ReportAnalysisService($this->crud);
        $service->remove($id);

        return ['success' => true];
    }
}

// Exécution
$api = new ReportAnalysisAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
