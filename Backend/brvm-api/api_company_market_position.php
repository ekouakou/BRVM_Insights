<?php
/**
 * API du positionnement / classement d'une entreprise face à la concurrence
 * (company_market_position, migration 034) — part de marché, rang
 * local/national/régional/mondial (ex. "1er réseau automobile du pays",
 * "7e banque de l'UMOA"). Voir ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_market_position.php
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

class CompanyMarketPositionAPI {
    private const SCOPES = ['local', 'national', 'regional', 'mondial'];

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
                    return $this->listPositions($input);

                case 'add':
                    return $this->addPosition($input);

                case 'update':
                    return $this->updatePosition($input);

                case 'delete':
                    return $this->deletePosition($input);

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

    /** Mondial en tête, puis régional, national, local. */
    private function listPositions($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT * FROM company_market_position
             WHERE company_id = ?
             ORDER BY FIELD(scope, 'mondial', 'regional', 'national', 'local'), rank_value IS NULL, rank_value",
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['positions' => $rows, 'count' => count($rows)]];
    }

    private function addPosition($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $scope = $input['scope'] ?? '';
        if (!in_array($scope, self::SCOPES, true)) {
            throw new Exception("scope invalide (attendu : " . implode(', ', self::SCOPES) . ")");
        }

        $category = trim($input['category'] ?? '');
        if ($category === '') {
            throw new Exception("category requis");
        }

        $rankLabel = trim($input['rank_label'] ?? '');
        if ($rankLabel === '') {
            throw new Exception("rank_label requis");
        }

        $id = $this->crud->persist('company_market_position', [
            'company_id' => $companyId,
            'scope' => $scope,
            'category' => mb_substr($category, 0, 150),
            'rank_value' => isset($input['rank_value']) && $input['rank_value'] !== '' ? max(1, (int) $input['rank_value']) : null,
            'rank_label' => mb_substr($rankLabel, 0, 255),
            'market_share_percent' => $this->validPercentOrNull($input['market_share_percent'] ?? null),
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'source_url' => !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updatePosition($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_market_position', $id)) {
            throw new Exception("Positionnement introuvable (id=$id)");
        }

        $update = [];
        if (array_key_exists('scope', $input)) {
            if (!in_array($input['scope'], self::SCOPES, true)) {
                throw new Exception("scope invalide");
            }
            $update['scope'] = $input['scope'];
        }
        if (array_key_exists('category', $input)) {
            $category = trim($input['category']);
            if ($category === '') throw new Exception("category ne peut pas être vide");
            $update['category'] = mb_substr($category, 0, 150);
        }
        if (array_key_exists('rank_value', $input)) {
            $update['rank_value'] = $input['rank_value'] !== '' && $input['rank_value'] !== null
                ? max(1, (int) $input['rank_value']) : null;
        }
        if (array_key_exists('rank_label', $input)) {
            $rankLabel = trim($input['rank_label']);
            if ($rankLabel === '') throw new Exception("rank_label ne peut pas être vide");
            $update['rank_label'] = mb_substr($rankLabel, 0, 255);
        }
        if (array_key_exists('market_share_percent', $input)) {
            $update['market_share_percent'] = $this->validPercentOrNull($input['market_share_percent']);
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
        $this->crud->merge('company_market_position', $update, ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function deletePosition($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_market_position', $id)) {
            throw new Exception("Positionnement introuvable (id=$id)");
        }
        $this->crud->remove('company_market_position', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validPercentOrNull($value): ?float {
        if ($value === null || $value === '') return null;
        $f = (float) $value;
        return ($f >= 0 && $f <= 100) ? $f : null;
    }
}

// Exécution
$api = new CompanyMarketPositionAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
