<?php
/**
 * API de comparaison de rapports sur une période (multi-fournisseurs)
 * Endpoint: api_report_comparison.php
 *
 * Compare les rapports de sociétés publiés sur une période donnée — une
 * seule entreprise dans le temps (tendance), plusieurs entreprises entre
 * elles, ou les deux. Réutilise les analyses individuelles déjà faites (voir
 * api_report_analysis.php) et déclenche celles qui manquent. Le résultat est
 * mis en cache par jour (par ensemble d'entreprises + période + fournisseur +
 * modèle) : rappeler 'compare' le même jour ne refacture pas l'IA, sauf avec
 * force_refresh.
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
require_once 'class/ReportAnalysisService.php';
require_once 'class/ReportComparisonService.php';

class ReportComparisonAPI {
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
     * Déclenche (ou réutilise le cache du jour pour) une comparaison de rapports.
     *
     * Deux modes : report_ids (sélection explicite de rapports précis, voir
     * Reports.tsx — prioritaire, ignore company_ids/dates) ou company_ids +
     * start_date/end_date (mode historique, période + entreprise(s), voir
     * Comparison.tsx).
     */
    private function compare($input) {
        $reportIds = !empty($input['report_ids']) ? array_map('intval', $input['report_ids']) : null;

        if ($reportIds !== null) {
            $companyIds = [];
            $startDate = '';
            $endDate = date('Y-m-d');
        } else {
            $companyIds = $this->resolveCompanyIds($input);
            [$startDate, $endDate] = $this->resolvePeriod($input);
        }

        $reportType = $input['report_type'] ?? null;
        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new ReportComparisonService($this->crud);

        try {
            $result = $service->compare($companyIds, $startDate, $endDate, $reportType, $provider, $model, $forceRefresh, $reportIds);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/données manquantes : pas un crash serveur
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Dernière comparaison en cache pour ces critères, sans jamais appeler l'IA
     * ni déclencher l'analyse d'un rapport manquant.
     */
    private function get($input) {
        $companyIds = $this->resolveCompanyIds($input);
        [$startDate, $endDate] = $this->resolvePeriod($input);
        $reportType = $input['report_type'] ?? null;

        $service = new ReportComparisonService($this->crud);
        $result = $service->getLatest($companyIds, $startDate, $endDate, $reportType);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune comparaison en cache pour ces critères"];
        }

        return ['success' => true, 'data' => $result];
    }

    /**
     * Accepte company_ids (entiers) ou symbols (résolus ici, même logique que
     * api_quotes.php::compareCompanies).
     */
    private function resolveCompanyIds($input): array {
        $companyIds = $input['company_ids'] ?? [];
        $symbols = $input['symbols'] ?? [];

        if (empty($companyIds) && empty($symbols)) {
            throw new Exception("company_ids ou symbols requis");
        }

        if (!empty($symbols) && empty($companyIds)) {
            $placeholders = implode(',', array_fill(0, count($symbols), '?'));
            $results = $this->crud->executeCustomQuery(
                "SELECT id FROM companies WHERE symbol IN ($placeholders)",
                $symbols
            );
            $companyIds = array_column($results, 'id');
        }

        if (empty($companyIds)) {
            throw new Exception("Aucune entreprise trouvée");
        }

        return array_map('intval', $companyIds);
    }

    private function resolvePeriod($input): array {
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');

        if (!$startDate) {
            throw new Exception("start_date requis");
        }

        return [$startDate, $endDate];
    }
}

// Exécution
$api = new ReportComparisonAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
