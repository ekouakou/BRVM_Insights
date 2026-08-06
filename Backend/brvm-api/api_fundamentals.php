<?php
/**
 * API des fondamentaux par entreprise
 * Endpoint: api_fundamentals.php
 *
 * Expose, pour chaque entreprise, les ratios fondamentaux (PER, P/B,
 * ROE, ROA, marges, rendement du dividende...) déjà extraits par IA à
 * partir de son dernier rapport financier traité avec succès
 * (company_report_analyses.details, colonnes key_financials/
 * valuation_assessment — voir class/AnthropicClient.php pour le schéma
 * exact). Aucun nouveau calcul IA ici, seulement une lecture/formatage
 * dédiée + le PEG (absent du schéma existant : PER ÷ croissance du CA,
 * les deux étant déjà présents séparément).
 *
 * Voir TODO_ANALYSES.md, point 24. Décision prise avec l'utilisateur :
 * exploiter cette filière IA existante plutôt que scraper les pages
 * "sociétés cotées" de brvm.org — vérifié en direct, ces pages affichent
 * des données obsolètes (jusqu'à 11 ans de retard selon l'entreprise) et
 * n'incluent pas les capitaux propres, ce qui aurait rendu une "source
 * fiable" scrapée en réalité moins à jour que les rapports déjà traités.
 *
 * Fiabilité différente des indicateurs techniques (calcul déterministe
 * sur des colonnes de base) : ces ratios dépendent de ce que le rapport
 * source a effectivement divulgué et de la qualité de l'extraction IA —
 * la date de publication du rapport source est donc toujours renvoyée
 * explicitement, jamais implicite.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

class FundamentalsAPI {
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
                    return $this->listFundamentals($input);

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
     * Pour chaque entreprise active ayant au moins une analyse IA de
     * rapport réussie, renvoie les ratios extraits du rapport le plus
     * récent (par date de publication). Les entreprises sans aucune
     * analyse réussie n'apparaissent pas dans `data` — comptées à part
     * dans `companies_without_data` pour que le frontend puisse l'annoncer
     * explicitement plutôt que de laisser croire à une liste complète.
     */
    private function listFundamentals($input) {
        $sql = "
            SELECT
                cra.id AS analysis_id,
                cra.company_id,
                cra.details,
                cr.id AS report_id,
                cr.report_type,
                cr.title AS report_title,
                cr.publish_date,
                c.symbol,
                c.name,
                c.sector_id,
                s.name AS sector
            FROM company_report_analyses cra
            INNER JOIN company_reports cr ON cr.id = cra.report_id
            INNER JOIN companies c ON c.id = cra.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            WHERE cra.status = 'success'
            AND c.active = 1
            ORDER BY cra.company_id ASC, cr.publish_date DESC
        ";
        $rows = $this->crud->executeCustomQuery($sql) ?: [];

        // Ne garde que le rapport le plus récent par entreprise (les lignes
        // arrivent déjà triées publish_date DESC par entreprise ci-dessus).
        $latestByCompany = [];
        foreach ($rows as $row) {
            $cid = (int) $row['company_id'];
            if (!isset($latestByCompany[$cid])) {
                $latestByCompany[$cid] = $row;
            }
        }

        $result = [];
        foreach ($latestByCompany as $cid => $row) {
            $details = json_decode($row['details'] ?? 'null', true) ?: [];
            $financials = $details['key_financials'] ?? [];
            $valuation = $details['valuation_assessment'] ?? [];

            $peRatio = $this->toFloatOrNull($valuation['pe_ratio'] ?? null);
            $revenueGrowth = $this->toFloatOrNull($financials['revenue_growth_percent'] ?? null);
            $pegRatio = ($peRatio !== null && $revenueGrowth !== null && $revenueGrowth != 0)
                ? round($peRatio / $revenueGrowth, 3)
                : null;

            $result[] = [
                'company_id' => $cid,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'sector_id' => $row['sector_id'] !== null ? (int) $row['sector_id'] : null,
                'sector' => $row['sector'],
                'source_report_id' => (int) $row['report_id'],
                'source_report_type' => $row['report_type'],
                'source_report_title' => $row['report_title'],
                'source_publish_date' => $row['publish_date'],
                'currency' => $financials['currency'] ?? null,
                'revenue' => $this->toFloatOrNull($financials['revenue'] ?? null),
                'revenue_prior_year' => $this->toFloatOrNull($financials['revenue_prior_year'] ?? null),
                'revenue_growth_percent' => $revenueGrowth,
                'net_income' => $this->toFloatOrNull($financials['net_income'] ?? null),
                'net_income_prior_year' => $this->toFloatOrNull($financials['net_income_prior_year'] ?? null),
                'net_margin_percent' => $this->toFloatOrNull($financials['net_margin_percent'] ?? null),
                'gross_margin_percent' => $this->toFloatOrNull($financials['gross_margin_percent'] ?? null),
                'operating_margin_percent' => $this->toFloatOrNull($financials['operating_margin_percent'] ?? null),
                'ebitda_margin_percent' => $this->toFloatOrNull($financials['ebitda_margin_percent'] ?? null),
                'roe_percent' => $this->toFloatOrNull($financials['roe_percent'] ?? null),
                'roa_percent' => $this->toFloatOrNull($financials['roa_percent'] ?? null),
                'debt_to_equity' => $this->toFloatOrNull($financials['debt_to_equity'] ?? null),
                'debt_to_ebitda' => $this->toFloatOrNull($financials['debt_to_ebitda'] ?? null),
                'current_ratio' => $this->toFloatOrNull($financials['current_ratio'] ?? null),
                'free_cash_flow' => $this->toFloatOrNull($financials['free_cash_flow'] ?? null),
                'dividend_per_share' => $this->toFloatOrNull($financials['dividend_per_share'] ?? null),
                'shares_outstanding' => $this->toFloatOrNull($valuation['shares_outstanding'] ?? null),
                'eps' => $this->toFloatOrNull($valuation['eps'] ?? null),
                'book_value_per_share' => $this->toFloatOrNull($valuation['book_value_per_share'] ?? null),
                'pe_ratio' => $peRatio,
                'peg_ratio' => $pegRatio,
                'price_to_book' => $this->toFloatOrNull($valuation['price_to_book'] ?? null),
                'ev_to_ebitda' => $this->toFloatOrNull($valuation['ev_to_ebitda'] ?? null),
                'dividend_yield_percent' => $this->toFloatOrNull($valuation['dividend_yield_percent'] ?? null),
                'payout_ratio_percent' => $this->toFloatOrNull($valuation['payout_ratio_percent'] ?? null),
                'valuation_verdict' => $valuation['verdict'] ?? null,
                'valuation_rationale' => $valuation['rationale'] ?? null,
            ];
        }

        $companiesWithData = array_column($result, 'company_id');
        $allActive = $this->crud->executeCustomQuery("SELECT id, symbol, name FROM companies WHERE active = 1") ?: [];
        $companiesWithoutData = array_values(array_filter($allActive, fn($c) => !in_array((int) $c['id'], $companiesWithData, true)));

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'companies_without_data' => array_map(fn($c) => ['company_id' => (int) $c['id'], 'symbol' => $c['symbol'], 'name' => $c['name']], $companiesWithoutData),
            'companies_without_data_count' => count($companiesWithoutData)
        ];
    }

    private function toFloatOrNull($value) {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }
}

// Exécution
$api = new FundamentalsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
