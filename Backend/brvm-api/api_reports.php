<?php
/**
 * API de consultation des rapports des sociétés cotées
 * Endpoint: api_reports.php
 *
 * Expose les métadonnées et le texte extrait des rapports (PDF) scrapés par
 * scripts/backfill_reports.php, pensé pour être consommé par une analyse IA
 * (résumé, extraction de chiffres clés, etc.) en plus d'un affichage classique.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';

class ReportsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'list_companies':
                    return $this->listCompaniesWithReports($input);

                case 'list':
                    return $this->listReports($input);

                case 'get':
                    return $this->getReport($input);

                case 'stats':
                    return $this->getStats($input);

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
     * Entreprises pour lesquelles des rapports ont été (ou peuvent être) collectés
     */
    private function listCompaniesWithReports($input) {
        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                c.brvm_report_slug,
                COUNT(cr.id) AS reports_count,
                SUM(cr.text_extracted) AS reports_with_text,
                MAX(cr.publish_date) AS latest_report_date
            FROM companies c
            LEFT JOIN company_reports cr ON cr.company_id = c.id
            WHERE c.active = 1 AND c.brvm_report_slug IS NOT NULL
            GROUP BY c.id, c.symbol, c.name, c.brvm_report_slug
            ORDER BY c.symbol
        ";

        $data = $this->crud->executeCustomQuery($sql) ?: [];

        return [
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ];
    }

    /**
     * Liste des rapports d'une entreprise (métadonnées uniquement, sans le texte complet)
     */
    private function listReports($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $where = ['company_id' => $companyId];
        if (!empty($input['report_type'])) {
            $where['report_type'] = $input['report_type'];
        }

        $reports = $this->crud->find(
            'company_reports',
            $where,
            PDO::FETCH_ASSOC,
            true,
            'publish_date DESC, id DESC'
        );

        // Ne pas renvoyer extraction_error/local_path (détails internes) dans la liste
        $data = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'report_type' => $r['report_type'],
                'title' => $r['title'],
                'publish_date' => $r['publish_date'],
                'file_url' => $r['file_url'],
                'file_size' => $r['file_size'],
                'text_extracted' => (bool) $r['text_extracted'],
                'extraction_method' => $r['extraction_method'],
            ];
        }, $reports);

        return [
            'success' => true,
            'data' => $data,
            'count' => count($data),
            'company_id' => $companyId
        ];
    }

    /**
     * Détail d'un rapport, avec le texte extrait (si disponible) — prêt à être
     * envoyé à un modèle d'IA pour analyse/résumé.
     */
    private function getReport($input) {
        $reportId = (int) ($input['id'] ?? 0);

        if (!$reportId) {
            throw new Exception("ID du rapport requis");
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé");
        }

        $company = $this->crud->findById('companies', $report['company_id']);

        $content = $this->crud->find('company_report_contents', ['report_id' => $reportId]);

        return [
            'success' => true,
            'data' => [
                'id' => $report['id'],
                'company' => [
                    'id' => $company['id'] ?? null,
                    'symbol' => $company['symbol'] ?? null,
                    'name' => $company['name'] ?? null,
                ],
                'report_type' => $report['report_type'],
                'title' => $report['title'],
                'publish_date' => $report['publish_date'],
                'file_url' => $report['file_url'],
                'file_size' => $report['file_size'],
                'text_extracted' => (bool) $report['text_extracted'],
                'extraction_method' => $report['extraction_method'],
                'extraction_error' => $report['extraction_error'],
                'extracted_text' => $content[0]['extracted_text'] ?? null,
                'char_count' => $content[0]['char_count'] ?? null,
            ]
        ];
    }

    /**
     * Statistiques globales de collecte (utile pour suivre l'avancement du backfill)
     */
    private function getStats($input) {
        $sql = "
            SELECT
                COUNT(*) AS total_reports,
                SUM(text_extracted) AS extracted_reports,
                SUM(downloaded_at IS NOT NULL) AS downloaded_reports,
                COUNT(DISTINCT company_id) AS companies_with_reports,
                MIN(publish_date) AS oldest_report,
                MAX(publish_date) AS newest_report
            FROM company_reports
        ";

        $stats = $this->crud->executeCustomQuery($sql);

        $byType = $this->crud->executeCustomQuery(
            "SELECT report_type, COUNT(*) AS count FROM company_reports GROUP BY report_type ORDER BY count DESC"
        );

        return [
            'success' => true,
            'data' => [
                'overview' => $stats[0] ?? null,
                'by_type' => $byType ?: []
            ]
        ];
    }
}

// Exécution
$api = new ReportsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
