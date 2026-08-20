<?php
/**
 * API de l'historique « Prix fixés à l'international ? » d'une entreprise
 * (company_international_pricing_history, migration 032) — historisé comme
 * l'actionnariat (company_shareholders) plutôt qu'une simple colonne sur
 * `companies` : ce critère peut changer (ex. fin d'une protection tarifaire
 * régionale, bascule d'un intrant vers un fournisseur mondial).
 * Endpoint: api_company_international_pricing.php
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

class CompanyInternationalPricingAPI {
    private const LEVELS = ['non', 'partiellement', 'oui'];

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
                    return $this->listHistory($input);

                case 'add':
                    return $this->addEntry($input);

                case 'delete':
                    return $this->deleteEntry($input);

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
     * Historique complet d'une entreprise, du plus récent au plus ancien —
     * l'entrée courante (valid_to IS NULL) est toujours en tête.
     */
    private function listHistory($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT h.*, u.username AS created_by_username
             FROM company_international_pricing_history h
             LEFT JOIN admin_users u ON u.id = h.created_by_admin_user_id
             WHERE h.company_id = ?
             ORDER BY h.valid_to IS NULL DESC, h.valid_from DESC, h.created_at DESC',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['history' => $rows, 'count' => count($rows)]];
    }

    /**
     * Ajoute une nouvelle classification — clôture automatiquement l'entrée
     * actuellement ouverte (valid_to IS NULL) à la date de début de la
     * nouvelle, pour ne jamais avoir deux entrées "courantes" en même temps.
     */
    private function addEntry($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $level = $input['pricing_level'] ?? '';
        if (!in_array($level, self::LEVELS, true)) {
            throw new Exception("pricing_level invalide (attendu : " . implode(', ', self::LEVELS) . ")");
        }

        $validFrom = $this->validDateOrNull($input['valid_from'] ?? null) ?? date('Y-m-d');

        $openEntries = $this->crud->executeCustomQuery(
            'SELECT id FROM company_international_pricing_history WHERE company_id = ? AND valid_to IS NULL',
            [$companyId]
        ) ?: [];
        foreach ($openEntries as $entry) {
            $this->crud->merge('company_international_pricing_history', ['valid_to' => $validFrom], ['id' => $entry['id']]);
        }

        $id = $this->crud->persist('company_international_pricing_history', [
            'company_id' => $companyId,
            'pricing_level' => $level,
            'explanation' => !empty($input['explanation']) ? trim($input['explanation']) : null,
            'valid_from' => $validFrom,
            'valid_to' => null,
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function deleteEntry($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_international_pricing_history', $id)) {
            throw new Exception("Entrée introuvable (id=$id)");
        }
        $this->crud->remove('company_international_pricing_history', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validDateOrNull($date): ?string {
        if (!$date || !is_string($date)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

// Exécution
$api = new CompanyInternationalPricingAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
