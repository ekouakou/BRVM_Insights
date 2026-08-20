<?php
/**
 * API des partenaires et clients d'une entreprise
 * (company_business_relationships, migration 029) — partenaires
 * (actionnaire technique, licence de marque, fournisseur, équipementier) ET
 * clients (nommés ou par catégorie) : même forme, un type plutôt que deux
 * tables. Voir ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_business_relationships.php
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

class CompanyBusinessRelationshipsAPI {
    private const TYPES = [
        'actionnaire_technique', 'licence_marque', 'fournisseur_cle',
        'equipementier', 'distributeur', 'client_principal',
        'client_categorie', 'autre',
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
                    return $this->listRelationships($input);

                case 'add':
                    return $this->addRelationship($input);

                case 'update':
                    return $this->updateRelationship($input);

                case 'delete':
                    return $this->deleteRelationship($input);

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

    /** Relations actives (until_date NULL) en tête, triées par importance puis type. */
    private function listRelationships($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT * FROM company_business_relationships
             WHERE company_id = ?
             ORDER BY until_date IS NULL DESC, relationship_type, rank_importance IS NULL, rank_importance, counterparty_name',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['relationships' => $rows, 'count' => count($rows)]];
    }

    private function addRelationship($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $name = trim($input['counterparty_name'] ?? '');
        if ($name === '') {
            throw new Exception("counterparty_name requis");
        }

        $type = $input['relationship_type'] ?? '';
        if (!in_array($type, self::TYPES, true)) {
            throw new Exception("relationship_type invalide (attendu : " . implode(', ', self::TYPES) . ")");
        }

        $id = $this->crud->persist('company_business_relationships', [
            'company_id' => $companyId,
            'relationship_type' => $type,
            'counterparty_name' => mb_substr($name, 0, 200),
            'is_named' => array_key_exists('is_named', $input) ? (!empty($input['is_named']) ? 1 : 0) : 1,
            'rank_importance' => isset($input['rank_importance']) && $input['rank_importance'] !== ''
                ? max(1, (int) $input['rank_importance']) : null,
            'description' => !empty($input['description']) ? trim($input['description']) : null,
            'since_date' => $this->validDateOrNull($input['since_date'] ?? null),
            'until_date' => $this->validDateOrNull($input['until_date'] ?? null),
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'source_url' => !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null,
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateRelationship($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_business_relationships', $id)) {
            throw new Exception("Relation introuvable (id=$id)");
        }

        $update = [];
        if (array_key_exists('counterparty_name', $input)) {
            $name = trim($input['counterparty_name']);
            if ($name === '') throw new Exception("counterparty_name ne peut pas être vide");
            $update['counterparty_name'] = mb_substr($name, 0, 200);
        }
        if (array_key_exists('relationship_type', $input)) {
            if (!in_array($input['relationship_type'], self::TYPES, true)) {
                throw new Exception("relationship_type invalide");
            }
            $update['relationship_type'] = $input['relationship_type'];
        }
        if (array_key_exists('is_named', $input)) {
            $update['is_named'] = !empty($input['is_named']) ? 1 : 0;
        }
        if (array_key_exists('rank_importance', $input)) {
            $update['rank_importance'] = $input['rank_importance'] !== '' && $input['rank_importance'] !== null
                ? max(1, (int) $input['rank_importance']) : null;
        }
        if (array_key_exists('description', $input)) {
            $update['description'] = !empty($input['description']) ? trim($input['description']) : null;
        }
        if (array_key_exists('since_date', $input)) {
            $update['since_date'] = $this->validDateOrNull($input['since_date']);
        }
        if (array_key_exists('until_date', $input)) {
            $update['until_date'] = $this->validDateOrNull($input['until_date']);
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
        $this->crud->merge('company_business_relationships', $update, ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function deleteRelationship($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_business_relationships', $id)) {
            throw new Exception("Relation introuvable (id=$id)");
        }
        $this->crud->remove('company_business_relationships', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validDateOrNull($date): ?string {
        if (!$date || !is_string($date)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

// Exécution
$api = new CompanyBusinessRelationshipsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
