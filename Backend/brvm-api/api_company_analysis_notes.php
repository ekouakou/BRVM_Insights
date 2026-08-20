<?php
/**
 * API des notes qualitatives structurées d'une entreprise
 * (company_analysis_notes, migration 029) — perspective générale, facteurs
 * de hausse/baisse, signaux d'achat/vente, leviers et perspective de la
 * politique de rémunération. Voir ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_analysis_notes.php
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

class CompanyAnalysisNotesAPI {
    private const NOTE_TYPES = [
        'perspective_generale', 'facteur_hausse', 'facteur_baisse',
        'signal_achat', 'signal_vente',
        'levier_remuneration', 'perspective_remuneration',
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
                    return $this->listNotes($input);

                case 'add':
                    return $this->addNote($input);

                case 'update':
                    return $this->updateNote($input);

                case 'delete':
                    return $this->deleteNote($input);

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
     * Notes actives d'une entreprise, groupées par type côté frontend —
     * renvoyées à plat, triées par type puis display_order.
     */
    private function listNotes($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            "SELECT n.*, u.username AS created_by_username
             FROM company_analysis_notes n
             LEFT JOIN admin_users u ON u.id = n.created_by_admin_user_id
             WHERE n.company_id = ? AND n.is_active = 1
             ORDER BY n.note_type, n.display_order, n.created_at",
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['notes' => $rows, 'count' => count($rows)]];
    }

    private function addNote($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $noteType = $input['note_type'] ?? '';
        if (!in_array($noteType, self::NOTE_TYPES, true)) {
            throw new Exception("note_type invalide (attendu : " . implode(', ', self::NOTE_TYPES) . ")");
        }

        $content = trim($input['content'] ?? '');
        if ($content === '') {
            throw new Exception("content requis");
        }

        $id = $this->crud->persist('company_analysis_notes', [
            'company_id' => $companyId,
            'note_type' => $noteType,
            'content' => $content,
            'display_order' => (int) ($input['display_order'] ?? 0),
            'is_active' => 1,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateNote($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_analysis_notes', $id)) {
            throw new Exception("Note introuvable (id=$id)");
        }

        $content = trim($input['content'] ?? '');
        if ($content === '') {
            throw new Exception("content requis");
        }

        $this->crud->merge('company_analysis_notes', ['content' => $content], ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    /** Désactive plutôt que supprime — voir le commentaire de is_active dans la migration 029. */
    private function deleteNote($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_analysis_notes', $id)) {
            throw new Exception("Note introuvable (id=$id)");
        }
        $this->crud->merge('company_analysis_notes', ['is_active' => 0], ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }
}

// Exécution
$api = new CompanyAnalysisNotesAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
