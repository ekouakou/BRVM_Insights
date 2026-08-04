<?php
/**
 * Contrôles qualité / réconciliation des données de synchro
 * Endpoint: api_data_quality.php
 *
 * Motivation (voir TODO_ANALYSES.md, point 3) : deux bugs de données ont été
 * découverts cette semaine (cron sur le mauvais port MySQL en local, clôture
 * manquante un jour sur la prod) uniquement parce qu'un utilisateur a
 * remarqué l'absence de données — cet endpoint automatise la détection.
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

class DataQualityAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'reconciliation':
                    return $this->checkReconciliation($input);

                case 'price_jumps':
                    return $this->checkPriceJumps($input);

                case 'missing_days':
                    return $this->checkMissingDays($input);

                case 'summary':
                    return $this->summary($input);

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
     * variation_percent stocké vs recalculé depuis close_price/previous_close
     * — un écart signale une erreur de scraping (mauvaise valeur récupérée)
     * ou un bug de calcul, pas une vraie variation de marché.
     */
    private function checkReconciliation($input) {
        $days = (int)($input['days'] ?? 30);
        $tolerance = (float)($input['tolerance'] ?? 0.5); // points de %

        $sql = "
            SELECT
                c.symbol, c.name, sq.trading_date,
                sq.close_price, sq.previous_close, sq.variation_percent AS stored_variation,
                ROUND((sq.close_price - sq.previous_close) / sq.previous_close * 100, 4) AS computed_variation
            FROM stock_quotes sq
            INNER JOIN companies c ON c.id = sq.company_id
            WHERE sq.trading_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            AND sq.previous_close IS NOT NULL
            AND sq.previous_close > 0
            HAVING ABS(stored_variation - computed_variation) > ?
            ORDER BY sq.trading_date DESC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$days, $tolerance]) ?: [];

        return [
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
            'tolerance' => $tolerance
        ];
    }

    /**
     * Sauts de prix isolés entre deux relevés intrajournaliers consécutifs
     * de la même entreprise — un bond de +20% en 10 minutes est peu crédible
     * sur la BRVM (pas de limite de variation officielle documentée mais les
     * mouvements réels sont largement plus progressifs), plus probablement
     * une erreur de scraping (décalage de colonne, virgule mal lue...).
     *
     * Auto-jointure corrélée (pas de LAG(), MySQL 5.7 en prod) : pour chaque
     * relevé, retrouve le relevé précédent de la même entreprise le même
     * jour.
     */
    private function checkPriceJumps($input) {
        $days = (int)($input['days'] ?? 7);
        $threshold = (float)($input['threshold'] ?? 15); // % entre deux relevés consécutifs

        $sql = "
            SELECT
                c.symbol, c.name,
                prev.quote_datetime AS previous_datetime, prev.price AS previous_price,
                iq.quote_datetime, iq.price,
                ROUND((iq.price - prev.price) / prev.price * 100, 2) AS jump_percent
            FROM intraday_quotes iq
            INNER JOIN companies c ON c.id = iq.company_id
            INNER JOIN intraday_quotes prev ON prev.company_id = iq.company_id
                AND prev.quote_datetime = (
                    SELECT MAX(iq2.quote_datetime)
                    FROM intraday_quotes iq2
                    WHERE iq2.company_id = iq.company_id
                    AND iq2.quote_datetime < iq.quote_datetime
                    AND DATE(iq2.quote_datetime) = DATE(iq.quote_datetime)
                )
            WHERE iq.quote_datetime >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND prev.price > 0
            HAVING ABS(jump_percent) > ?
            ORDER BY iq.quote_datetime DESC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$days, $threshold]) ?: [];

        return [
            'success' => true,
            'data' => $rows,
            'count' => count($rows),
            'threshold' => $threshold
        ];
    }

    /**
     * Entreprises actives avec des jours ouvrés manquants dans stock_quotes
     * — le calendrier de référence est déduit des dates où AU MOINS une
     * entreprise a une clôture (donc où le marché était ouvert et la synchro
     * a tourné pour au moins une valeur), pas une liste de jours fériés BRVM
     * codée en dur.
     */
    private function checkMissingDays($input) {
        $days = (int)($input['days'] ?? 30);

        $sql = "
            SELECT
                c.id AS company_id, c.symbol, c.name,
                COUNT(DISTINCT cal.trading_date) AS expected_days,
                COUNT(DISTINCT sq.trading_date) AS actual_days
            FROM companies c
            CROSS JOIN (
                SELECT DISTINCT trading_date
                FROM stock_quotes
                WHERE trading_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ) cal
            LEFT JOIN stock_quotes sq ON sq.company_id = c.id AND sq.trading_date = cal.trading_date
            WHERE c.active = 1
            GROUP BY c.id, c.symbol, c.name
            HAVING COUNT(DISTINCT sq.trading_date) < COUNT(DISTINCT cal.trading_date)
            ORDER BY (COUNT(DISTINCT cal.trading_date) - COUNT(DISTINCT sq.trading_date)) DESC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$days]) ?: [];

        $result = array_map(function ($row) {
            return [
                'company_id' => (int) $row['company_id'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'expected_days' => (int) $row['expected_days'],
                'actual_days' => (int) $row['actual_days'],
                'missing_days' => (int) $row['expected_days'] - (int) $row['actual_days']
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'days' => $days
        ];
    }

    /**
     * Vue d'ensemble légère (compteurs seulement) pour un badge/bandeau
     * d'alerte sans devoir charger les trois listes détaillées.
     */
    private function summary($input) {
        $reconciliation = $this->checkReconciliation($input);
        $priceJumps = $this->checkPriceJumps($input);
        $missingDays = $this->checkMissingDays($input);

        return [
            'success' => true,
            'data' => [
                'reconciliation_issues' => $reconciliation['count'],
                'price_jump_issues' => $priceJumps['count'],
                'companies_with_missing_days' => $missingDays['count']
            ]
        ];
    }
}

// Exécution
$api = new DataQualityAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
