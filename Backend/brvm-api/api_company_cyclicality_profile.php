<?php
/**
 * API du profil de cyclicité d'une entreprise (company_cyclicality_profile,
 * migration 029) — une ligne par entreprise (company_id est la clé
 * primaire), mise à jour en place plutôt qu'historisée : la classification
 * "cyclique ou non" change rarement, contrairement à l'actionnariat ou au
 * prix international (voir api_company_international_pricing.php).
 * Endpoint: api_company_cyclicality_profile.php
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

class CompanyCyclicalityProfileAPI {
    private const LEVELS = ['non_cyclique', 'modere', 'fort'];

    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'get':
                    return $this->getProfile($input);

                case 'update':
                    return $this->updateProfile($input);

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

    private function getProfile($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT * FROM company_cyclicality_profile WHERE company_id = ? LIMIT 1',
            [$companyId]
        );

        return ['success' => true, 'data' => $rows[0] ?? null];
    }

    private function updateProfile($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $level = $input['cyclicality_level'] ?? '';
        if (!in_array($level, self::LEVELS, true)) {
            throw new Exception("cyclicality_level invalide (attendu : " . implode(', ', self::LEVELS) . ")");
        }

        $data = [
            'company_id' => $companyId,
            'cyclicality_level' => $level,
            'cycle_driver' => !empty($input['cycle_driver']) ? mb_substr(trim($input['cycle_driver']), 0, 100) : null,
            'commodity_reference' => !empty($input['commodity_reference']) ? mb_substr(trim($input['commodity_reference']), 0, 100) : null,
            'notes' => !empty($input['notes']) ? trim($input['notes']) : null,
        ];

        $existing = $this->crud->executeCustomQuery(
            'SELECT company_id FROM company_cyclicality_profile WHERE company_id = ? LIMIT 1',
            [$companyId]
        );

        if (!empty($existing)) {
            $this->crud->merge('company_cyclicality_profile', $data, ['company_id' => $companyId]);
        } else {
            $this->crud->persist('company_cyclicality_profile', $data);
        }

        return ['success' => true, 'data' => ['company_id' => $companyId]];
    }
}

// Exécution
$api = new CompanyCyclicalityProfileAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
