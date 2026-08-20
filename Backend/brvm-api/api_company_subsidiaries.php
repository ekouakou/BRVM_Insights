<?php
/**
 * API des filiales détenues par une entreprise cotée
 * (company_subsidiaries, migration 034) — distinct de company_shareholders
 * (qui historise QUI détient le capital d'une société cotée) : ici, QUOI
 * une société cotée détient elle-même. Voir ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_subsidiaries.php
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

class CompanySubsidiariesAPI {
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
                    return $this->listSubsidiaries($input);

                case 'add':
                    return $this->addSubsidiary($input);

                case 'update':
                    return $this->updateSubsidiary($input);

                case 'delete':
                    return $this->deleteSubsidiary($input);

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

    private function listSubsidiaries($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT s.*, lc.symbol AS linked_company_symbol, lc.name AS linked_company_name
             FROM company_subsidiaries s
             LEFT JOIN companies lc ON lc.id = s.linked_company_id
             WHERE s.company_id = ?
             ORDER BY s.country, s.subsidiary_name',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['subsidiaries' => $rows, 'count' => count($rows)]];
    }

    private function addSubsidiary($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $name = trim($input['subsidiary_name'] ?? '');
        if ($name === '') {
            throw new Exception("subsidiary_name requis");
        }

        $linkedId = !empty($input['linked_company_id']) ? (int) $input['linked_company_id'] : null;
        if ($linkedId !== null && !$this->crud->findById('companies', $linkedId)) {
            throw new Exception("linked_company_id invalide (entreprise cotée introuvable)");
        }

        $id = $this->crud->persist('company_subsidiaries', [
            'company_id' => $companyId,
            'subsidiary_name' => mb_substr($name, 0, 200),
            'country' => !empty($input['country']) ? mb_substr(trim($input['country']), 0, 100) : null,
            'ownership_percent' => $this->validPercentOrNull($input['ownership_percent'] ?? null),
            'linked_company_id' => $linkedId,
            'description' => !empty($input['description']) ? trim($input['description']) : null,
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'source_url' => !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateSubsidiary($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_subsidiaries', $id)) {
            throw new Exception("Filiale introuvable (id=$id)");
        }

        $update = [];
        if (array_key_exists('subsidiary_name', $input)) {
            $name = trim($input['subsidiary_name']);
            if ($name === '') throw new Exception("subsidiary_name ne peut pas être vide");
            $update['subsidiary_name'] = mb_substr($name, 0, 200);
        }
        if (array_key_exists('country', $input)) {
            $update['country'] = !empty($input['country']) ? mb_substr(trim($input['country']), 0, 100) : null;
        }
        if (array_key_exists('ownership_percent', $input)) {
            $update['ownership_percent'] = $this->validPercentOrNull($input['ownership_percent']);
        }
        if (array_key_exists('linked_company_id', $input)) {
            $linkedId = !empty($input['linked_company_id']) ? (int) $input['linked_company_id'] : null;
            if ($linkedId !== null && !$this->crud->findById('companies', $linkedId)) {
                throw new Exception("linked_company_id invalide");
            }
            $update['linked_company_id'] = $linkedId;
        }
        if (array_key_exists('description', $input)) {
            $update['description'] = !empty($input['description']) ? trim($input['description']) : null;
        }
        if (array_key_exists('source_note', $input)) {
            $update['source_note'] = !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null;
        }
        if (array_key_exists('source_url', $input)) {
            $update['source_url'] = !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null;
        }

        if (empty($update)) {
            throw new Exception("Aucun champ à mettre à jour");
        }
        $this->crud->merge('company_subsidiaries', $update, ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function deleteSubsidiary($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_subsidiaries', $id)) {
            throw new Exception("Filiale introuvable (id=$id)");
        }
        $this->crud->remove('company_subsidiaries', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validPercentOrNull($value): ?float {
        if ($value === null || $value === '') return null;
        $f = (float) $value;
        return ($f >= 0 && $f <= 100) ? $f : null;
    }
}

// Exécution
$api = new CompanySubsidiariesAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
