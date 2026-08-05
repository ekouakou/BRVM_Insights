<?php
/**
 * API d'analyse IA des graphes/tableaux de comparaison (multi-fournisseurs)
 * Endpoint: api_chart_analysis.php
 *
 * Voir TODO_CHART_AI_ANALYSIS.md pour la conception complète. Miroir de
 * api_report_analysis.php mais générique sur `chart_type` au lieu d'être
 * spécifique à un rapport — le frontend envoie les données déjà calculées
 * du graphe/tableau (pas de recalcul SQL ici), avec les paramètres exacts
 * de sa sélection (entreprises, dates, lignes cochées...).
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
require_once 'class/ChartAnalysisService.php';

class ChartAnalysisAPI {
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
     * Déclenche (ou réutilise le cache pour) une analyse IA d'un graphe/tableau.
     */
    private function analyze($input) {
        $chartType = $input['chart_type'] ?? '';
        $parameters = $input['parameters'] ?? [];
        $data = $input['data'] ?? [];

        if (!$chartType) {
            throw new Exception("chart_type requis");
        }
        if (!is_array($parameters)) {
            throw new Exception("parameters doit être un objet");
        }
        if (!is_array($data)) {
            throw new Exception("data doit être fourni (les données déjà calculées du graphe/tableau)");
        }

        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new ChartAnalysisService($this->crud);

        try {
            $result = $service->analyze($chartType, $parameters, $data, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur, juste un échec d'analyse
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function get($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("id requis");
        }

        $service = new ChartAnalysisService($this->crud);
        $result = $service->get($id);

        if (!$result) {
            throw new Exception("Analyse non trouvée");
        }

        return ['success' => true, 'data' => $result];
    }

    /**
     * Historique des analyses pour un chart_type et une sélection exacte
     * (paramètres) — pas une liste globale, voir ChartAnalysisService::history().
     */
    private function history($input) {
        $chartType = $input['chart_type'] ?? '';
        $parameters = $input['parameters'] ?? [];

        if (!$chartType) {
            throw new Exception("chart_type requis");
        }
        if (!is_array($parameters)) {
            throw new Exception("parameters doit être un objet");
        }

        $service = new ChartAnalysisService($this->crud);
        $data = $service->history($chartType, $parameters);

        return ['success' => true, 'data' => $data, 'count' => count($data)];
    }

    /**
     * Note (1-5 étoiles) et/ou commentaire libre sur une analyse déjà
     * enregistrée — rating/notes ne sont modifiés que s'ils sont
     * explicitement présents dans le payload (permet de mettre à jour l'un
     * sans effacer l'autre).
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

        $service = new ChartAnalysisService($this->crud);
        $result = $service->rate($id, $rating, $notes, $ratingProvided, $notesProvided);

        return ['success' => true, 'data' => $result];
    }
}

// Exécution
$api = new ChartAnalysisAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
