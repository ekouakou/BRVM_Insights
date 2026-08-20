<?php
/**
 * API de l'historique chiffré de la politique de rémunération (dividende)
 * d'une entreprise (company_dividend_yield_history, migration 037) —
 * pendant numérique des notes textuelles levier_remuneration/
 * perspective_remuneration (company_analysis_notes, migration 029). Deux
 * indicateurs : taux_distribution (payout ratio) et rendement_dividende
 * (dividend yield). Voir ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_dividend_yield.php
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

class CompanyDividendYieldAPI {
    private const METRIC_TYPES = ['taux_distribution', 'rendement_dividende'];

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

    /** Du plus récent au plus ancien, tous types confondus (le frontend sépare par metric_type). */
    private function listHistory($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT h.*, u.username AS created_by_username
             FROM company_dividend_yield_history h
             LEFT JOIN admin_users u ON u.id = h.created_by_admin_user_id
             WHERE h.company_id = ?
             ORDER BY h.record_date DESC, h.created_at DESC',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['history' => $rows, 'count' => count($rows)]];
    }

    private function addEntry($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $recordDate = $this->validDateOrNull($input['record_date'] ?? null);
        if (!$recordDate) {
            throw new Exception("record_date requis (format YYYY-MM-DD)");
        }

        $metricType = $input['metric_type'] ?? '';
        if (!in_array($metricType, self::METRIC_TYPES, true)) {
            throw new Exception("metric_type invalide (attendu : " . implode(', ', self::METRIC_TYPES) . ")");
        }

        $percentValue = is_numeric($input['percent_value'] ?? null) ? (float) $input['percent_value'] : null;
        if ($percentValue === null || $percentValue < 0) {
            throw new Exception("percent_value requis (nombre positif, en %)");
        }

        $id = $this->crud->persist('company_dividend_yield_history', [
            'company_id' => $companyId,
            'record_date' => $recordDate,
            'metric_type' => $metricType,
            'percent_value' => $percentValue,
            'fiscal_year' => !empty($input['fiscal_year']) ? (int) $input['fiscal_year'] : null,
            'note' => !empty($input['note']) ? trim($input['note']) : null,
            'source_note' => !empty($input['source_note']) ? mb_substr(trim($input['source_note']), 0, 255) : null,
            'source_url' => !empty($input['source_url']) ? mb_substr(trim($input['source_url']), 0, 500) : null,
            'created_by_admin_user_id' => AuthGuard::getCurrentUserId(),
        ]);

        return ['success' => true, 'data' => ['id' => (int) $id]];
    }

    private function updateEntry($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_dividend_yield_history', $id)) {
            throw new Exception("Entrée introuvable (id=$id)");
        }

        $update = [];
        if (array_key_exists('record_date', $input)) {
            $recordDate = $this->validDateOrNull($input['record_date']);
            if (!$recordDate) throw new Exception("record_date invalide (format YYYY-MM-DD)");
            $update['record_date'] = $recordDate;
        }
        if (array_key_exists('metric_type', $input)) {
            if (!in_array($input['metric_type'], self::METRIC_TYPES, true)) {
                throw new Exception("metric_type invalide");
            }
            $update['metric_type'] = $input['metric_type'];
        }
        if (array_key_exists('percent_value', $input)) {
            if (!is_numeric($input['percent_value']) || (float) $input['percent_value'] < 0) {
                throw new Exception("percent_value invalide (nombre positif)");
            }
            $update['percent_value'] = (float) $input['percent_value'];
        }
        if (array_key_exists('fiscal_year', $input)) {
            $update['fiscal_year'] = !empty($input['fiscal_year']) ? (int) $input['fiscal_year'] : null;
        }
        if (array_key_exists('note', $input)) {
            $update['note'] = !empty($input['note']) ? trim($input['note']) : null;
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
        $this->crud->merge('company_dividend_yield_history', $update, ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function deleteEntry($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id || !$this->crud->findById('company_dividend_yield_history', $id)) {
            throw new Exception("Entrée introuvable (id=$id)");
        }
        $this->crud->remove('company_dividend_yield_history', ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id]];
    }

    private function validDateOrNull($date): ?string {
        if (!$date || !is_string($date)) return null;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return ($d && $d->format('Y-m-d') === $date) ? $date : null;
    }
}

// Exécution
$api = new CompanyDividendYieldAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
