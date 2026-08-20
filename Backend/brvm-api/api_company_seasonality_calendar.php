<?php
/**
 * API du calendrier saisonnier mensuel structuré d'une entreprise
 * (company_seasonality_calendar, migration 029) — version chiffrée du
 * « détail saisonnier » en texte libre (companies.seasonal_detail),
 * utilisée pour un calendrier visuel dans l'application. Voir
 * ANALYSE_ENTREPRISES_BRVM.md.
 * Endpoint: api_company_seasonality_calendar.php
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

class CompanySeasonalityCalendarAPI {
    private const LEVELS = ['haute', 'normale', 'basse'];

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
                    return $this->listCalendar($input);

                case 'set':
                    return $this->setMonth($input);

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
     * Les 12 mois d'une entreprise — un mois absent de la table est
     * implicitement "normale" (convention côté frontend, pas de ligne
     * stockée pour ne pas alourdir la table de 47 x 12 lignes dont la
     * plupart seraient "normale").
     */
    private function listCalendar($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("company_id requis");
        }

        $rows = $this->crud->executeCustomQuery(
            'SELECT month, activity_level, note FROM company_seasonality_calendar WHERE company_id = ? ORDER BY month',
            [$companyId]
        ) ?: [];

        return ['success' => true, 'data' => ['months' => $rows]];
    }

    /**
     * Définit le niveau d'un mois — 'normale' supprime la ligne (retour à
     * la convention par défaut) plutôt que de stocker une ligne inutile.
     */
    private function setMonth($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId || !$this->crud->findById('companies', $companyId)) {
            throw new Exception("company_id requis (entreprise existante)");
        }

        $month = (int) ($input['month'] ?? 0);
        if ($month < 1 || $month > 12) {
            throw new Exception("month invalide (1 à 12 attendu)");
        }

        $level = $input['activity_level'] ?? '';
        if (!in_array($level, self::LEVELS, true)) {
            throw new Exception("activity_level invalide (attendu : " . implode(', ', self::LEVELS) . ")");
        }

        $existing = $this->crud->executeCustomQuery(
            'SELECT id FROM company_seasonality_calendar WHERE company_id = ? AND month = ? LIMIT 1',
            [$companyId, $month]
        );

        if ($level === 'normale') {
            if (!empty($existing)) {
                $this->crud->remove('company_seasonality_calendar', ['id' => $existing[0]['id']]);
            }
            return ['success' => true, 'data' => ['company_id' => $companyId, 'month' => $month, 'activity_level' => 'normale']];
        }

        $note = !empty($input['note']) ? mb_substr(trim($input['note']), 0, 255) : null;

        if (!empty($existing)) {
            $this->crud->merge(
                'company_seasonality_calendar',
                ['activity_level' => $level, 'note' => $note],
                ['id' => $existing[0]['id']]
            );
        } else {
            $this->crud->persist('company_seasonality_calendar', [
                'company_id' => $companyId,
                'month' => $month,
                'activity_level' => $level,
                'note' => $note,
            ]);
        }

        return ['success' => true, 'data' => ['company_id' => $companyId, 'month' => $month, 'activity_level' => $level]];
    }
}

// Exécution
$api = new CompanySeasonalityCalendarAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
