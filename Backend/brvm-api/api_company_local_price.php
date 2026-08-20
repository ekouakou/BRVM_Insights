<?php
/**
 * API de l'historique du prix local d'une entreprise
 * (company_local_price_history, migration 033) — pertinent pour les
 * entreprises dont le produit/intrant suit (au moins partiellement) un
 * cours mondial (voir api_company_international_pricing.php) : le prix
 * réellement payé/perçu localement est une série temporelle distincte du
 * cours mondial. Endpoint: api_company_local_price.php
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

class CompanyLocalPriceAPI {
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

                case 'update':
                    return $this->updateEntry($input);

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

    /** Du plus récent au plus ancien. */
    private function listHistory($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT h.*, u.username AS created_by_username
             FROM company_local_price_history h
             LEFT JOIN admin_users u ON u.id = h.created_by_admin_user_id
             WHERE h.company_id = ?
             ORDER BY h.price_date DESC, h.created_at DESC',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['history' => $rows, 'count' => count($rows)]];
    }

    private function addEntry($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $priceDate = $this->validDateOrNull($input['price_date'] ?? null);
        if (!$priceDate) {
            throw new Exception("price_date requis (format YYYY-MM-DD)");
        }

        $priceValue = is_numeric($input['price_value'] ?? null) ? (float) $input['price_value'] : null;
        if ($priceValue === null || $priceValue < 0) {
            throw new Exception("price_value requis (nombre positif)");
        }

        $unit = trim($input['unit'] ?? '');
        if ($unit === '') {
            throw new Exception("unit requis (ex. FCFA/kg)");
        }

        $id = $this->crud->persist('company_local_price_history', [
            'company_id' => $companyId,
            'price_date' => $priceDate,
            'price_value' => $priceValue,
            'unit' => mb_substr($unit, 0, 30),
            'product_label' => !empty($input['product_label']) ? mb_substr(trim($input['product_label']), 0, 150) : null,
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'source_url' => !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateEntry($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_local_price_history', $id)) {
            throw new Exception("Entrée introuvable (id=$id)");
        }

        $update = [];
        if (array_key_exists('price_date', $input)) {
            $priceDate = $this->validDateOrNull($input['price_date']);
            if (!$priceDate) throw new Exception("price_date invalide (format YYYY-MM-DD)");
            $update['price_date'] = $priceDate;
        }
        if (array_key_exists('price_value', $input)) {
            if (!is_numeric($input['price_value']) || (float) $input['price_value'] < 0) {
                throw new Exception("price_value invalide (nombre positif)");
            }
            $update['price_value'] = (float) $input['price_value'];
        }
        if (array_key_exists('unit', $input)) {
            $unit = trim($input['unit']);
            if ($unit === '') throw new Exception("unit ne peut pas être vide");
            $update['unit'] = mb_substr($unit, 0, 30);
        }
        if (array_key_exists('product_label', $input)) {
            $update['product_label'] = !empty($input['product_label']) ? mb_substr(trim($input['product_label']), 0, 150) : null;
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
        $this->crud->merge('company_local_price_history', $update, ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function deleteEntry($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_local_price_history', $id)) {
            throw new Exception("Entrée introuvable (id=$id)");
        }
        $this->crud->remove('company_local_price_history', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validDateOrNull($date): ?string {
        if (!$date || !is_string($date)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

// Exécution
$api = new CompanyLocalPriceAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
