<?php
/**
 * API de l'actionnariat d'une entreprise (company_shareholders,
 * migration 029) — historise QUI détient le capital, avec période de
 * validité, pour capter un changement d'actionnaire sans écraser
 * l'ancienne donnée. Voir ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_shareholders.php
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

class CompanyShareholdersAPI {
    private const TYPES = [
        'etat', 'groupe_industriel', 'banque_institution_financiere',
        'fonds_investissement', 'flottant_public', 'salaries', 'autre',
    ];

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
                    return $this->listShareholders($input);

                case 'add':
                    return $this->addShareholder($input);

                case 'update':
                    return $this->updateShareholder($input);

                case 'delete':
                    return $this->deleteShareholder($input);

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

    /** Actionnaires actuels (valid_to NULL) en tête, puis anciens du plus récent au plus ancien. */
    private function listShareholders($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT * FROM company_shareholders
             WHERE company_id = ?
             ORDER BY valid_to IS NULL DESC, is_reference_shareholder DESC, ownership_percent DESC, valid_from DESC',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['shareholders' => $rows, 'count' => count($rows)]];
    }

    private function addShareholder($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $name = trim($input['shareholder_name'] ?? '');
        if ($name === '') {
            throw new Exception("shareholder_name requis");
        }

        $type = $input['shareholder_type'] ?? '';
        if (!in_array($type, self::TYPES, true)) {
            throw new Exception("shareholder_type invalide (attendu : " . implode(', ', self::TYPES) . ")");
        }

        $id = $this->crud->persist('company_shareholders', [
            'company_id' => $companyId,
            'shareholder_name' => mb_substr($name, 0, 200),
            'shareholder_type' => $type,
            'ownership_percent' => $this->validPercentOrNull($input['ownership_percent'] ?? null),
            'is_reference_shareholder' => !empty($input['is_reference_shareholder']) ? 1 : 0,
            'valid_from' => $this->validDateOrNull($input['valid_from'] ?? null),
            'valid_to' => $this->validDateOrNull($input['valid_to'] ?? null),
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'source_url' => !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null,
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateShareholder($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_shareholders', $id)) {
            throw new Exception("Actionnaire introuvable (id=$id)");
        }

        $update = [];
        if (array_key_exists('shareholder_name', $input)) {
            $name = trim($input['shareholder_name']);
            if ($name === '') throw new Exception("shareholder_name ne peut pas être vide");
            $update['shareholder_name'] = mb_substr($name, 0, 200);
        }
        if (array_key_exists('shareholder_type', $input)) {
            if (!in_array($input['shareholder_type'], self::TYPES, true)) {
                throw new Exception("shareholder_type invalide");
            }
            $update['shareholder_type'] = $input['shareholder_type'];
        }
        if (array_key_exists('ownership_percent', $input)) {
            $update['ownership_percent'] = $this->validPercentOrNull($input['ownership_percent']);
        }
        if (array_key_exists('is_reference_shareholder', $input)) {
            $update['is_reference_shareholder'] = !empty($input['is_reference_shareholder']) ? 1 : 0;
        }
        if (array_key_exists('valid_from', $input)) {
            $update['valid_from'] = $this->validDateOrNull($input['valid_from']);
        }
        if (array_key_exists('valid_to', $input)) {
            $update['valid_to'] = $this->validDateOrNull($input['valid_to']);
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
        $this->crud->merge('company_shareholders', $update, ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function deleteShareholder($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_shareholders', $id)) {
            throw new Exception("Actionnaire introuvable (id=$id)");
        }
        $this->crud->remove('company_shareholders', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validPercentOrNull($value): ?float {
        if ($value === null || $value === '') return null;
        $f = (float) $value;
        return ($f >= 0 && $f <= 100) ? $f : null;
    }

    private function validDateOrNull($date): ?string {
        if (!$date || !is_string($date)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

// Exécution
$api = new CompanyShareholdersAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
