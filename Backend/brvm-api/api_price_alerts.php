<?php
/**
 * Gestion des alertes de prix (TODO_ANALYSES.md point 11)
 * Endpoint: api_price_alerts.php
 *
 * La table `price_alerts` existait déjà dans le schéma mais n'était
 * utilisée par aucun code — ce fichier construit le CRUD + la vérification
 * des seuils. Volontairement PAS branché sur le cron de production
 * (aucune modification de BRVMSyncService.php/cron_sync_brvm.php) : l'action
 * `check` doit être déclenchée manuellement (bouton frontend) pour l'instant.
 *
 * ⚠️ notification_email / notification_webhook sont acceptés et stockés,
 * mais AUCUN envoi réel n'est implémenté ici (ni email ni webhook) — seul
 * le flag `triggered` est mis à jour. Prochaine étape si ce v1 est validé :
 * brancher l'envoi effectif (ex: via class/OneSignalNotifier.php ou un
 * mailer), une fois qu'on est sûr que les seuils déclenchés sont pertinents
 * en pratique.
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

class PriceAlertsAPI {
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
                    return $this->listAlerts($input);

                case 'create':
                    return $this->createAlert($input);

                case 'update':
                    return $this->updateAlert($input);

                case 'delete':
                    return $this->deleteAlert($input);

                case 'check':
                    return $this->checkAlerts($input);

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

    private function listAlerts($input) {
        $sql = "
            SELECT
                pa.id, pa.company_id, c.symbol, c.name,
                pa.alert_type, pa.target_price, pa.target_percent,
                pa.notification_email, pa.notification_webhook,
                pa.triggered, pa.triggered_at, pa.active, pa.created_at
            FROM price_alerts pa
            INNER JOIN companies c ON c.id = pa.company_id
        ";
        $params = [];

        if (!empty($input['company_id'])) {
            $sql .= " WHERE pa.company_id = ?";
            $params[] = (int) $input['company_id'];
        }

        $sql .= " ORDER BY pa.active DESC, pa.triggered ASC, pa.created_at DESC";

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        return [
            'success' => true,
            'data' => $rows,
            'count' => count($rows)
        ];
    }

    private function createAlert($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $alertType = $input['alert_type'] ?? '';

        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        if (!in_array($alertType, ['above', 'below', 'change_percent'], true)) {
            throw new Exception("alert_type doit être 'above', 'below' ou 'change_percent'");
        }

        $company = $this->crud->find('companies', ['id' => $companyId]);
        if (empty($company)) {
            throw new Exception("Entreprise non trouvée");
        }

        $data = [
            'company_id' => $companyId,
            'alert_type' => $alertType,
            'target_price' => in_array($alertType, ['above', 'below'], true) ? (float) ($input['target_price'] ?? 0) : null,
            'target_percent' => $alertType === 'change_percent' ? (float) ($input['target_percent'] ?? 0) : null,
            'notification_email' => $input['notification_email'] ?? null,
            'notification_webhook' => $input['notification_webhook'] ?? null,
            'triggered' => 0,
            'active' => 1
        ];

        if (in_array($alertType, ['above', 'below'], true) && !$data['target_price']) {
            throw new Exception("target_price requis pour une alerte 'above'/'below'");
        }
        if ($alertType === 'change_percent' && !$data['target_percent']) {
            throw new Exception("target_percent requis pour une alerte 'change_percent'");
        }

        $id = $this->crud->persist('price_alerts', $data);

        return [
            'success' => true,
            'data' => ['id' => $id]
        ];
    }

    private function updateAlert($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("ID de l'alerte requis");
        }

        $existing = $this->crud->find('price_alerts', ['id' => $id]);
        if (empty($existing)) {
            throw new Exception("Alerte non trouvée");
        }

        $update = [];
        if (isset($input['active'])) {
            $update['active'] = (int) $input['active'] ? 1 : 0;
        }
        if (isset($input['triggered'])) {
            $update['triggered'] = (int) $input['triggered'] ? 1 : 0;
            $update['triggered_at'] = $update['triggered'] ? date('Y-m-d H:i:s') : null;
        }

        if (empty($update)) {
            throw new Exception("Rien à mettre à jour (active et/ou triggered attendus)");
        }

        $this->crud->merge('price_alerts', $update, ['id' => $id]);

        return ['success' => true];
    }

    private function deleteAlert($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("ID de l'alerte requis");
        }

        $this->crud->remove('price_alerts', ['id' => $id]);

        return ['success' => true];
    }

    /**
     * Évalue toutes les alertes actives non déclenchées contre le dernier
     * cours de clôture connu — à appeler manuellement (bouton frontend),
     * pas automatiquement. Marque `triggered` mais n'envoie aucune
     * notification (voir avertissement en tête de fichier).
     */
    private function checkAlerts($input) {
        $sql = "
            SELECT
                pa.id, pa.company_id, pa.alert_type, pa.target_price, pa.target_percent,
                c.symbol, c.name,
                sq.close_price AS current_price, sq.variation_percent AS current_variation
            FROM price_alerts pa
            INNER JOIN companies c ON c.id = pa.company_id
            LEFT JOIN stock_quotes sq ON sq.company_id = pa.company_id
                AND sq.trading_date = (SELECT MAX(trading_date) FROM stock_quotes WHERE company_id = pa.company_id)
            WHERE pa.active = 1 AND pa.triggered = 0
        ";
        $alerts = $this->crud->executeCustomQuery($sql) ?: [];

        $triggeredNow = [];

        foreach ($alerts as $alert) {
            if ($alert['current_price'] === null) {
                continue;
            }

            $price = (float) $alert['current_price'];
            $variation = $alert['current_variation'] !== null ? (float) $alert['current_variation'] : null;
            $fired = false;

            if ($alert['alert_type'] === 'above' && $price >= (float) $alert['target_price']) {
                $fired = true;
            } elseif ($alert['alert_type'] === 'below' && $price <= (float) $alert['target_price']) {
                $fired = true;
            } elseif ($alert['alert_type'] === 'change_percent' && $variation !== null && abs($variation) >= abs((float) $alert['target_percent'])) {
                $fired = true;
            }

            if ($fired) {
                $this->crud->merge('price_alerts', [
                    'triggered' => 1,
                    'triggered_at' => date('Y-m-d H:i:s')
                ], ['id' => $alert['id']]);

                $triggeredNow[] = [
                    'id' => $alert['id'],
                    'symbol' => $alert['symbol'],
                    'name' => $alert['name'],
                    'alert_type' => $alert['alert_type'],
                    'current_price' => $price,
                    'current_variation' => $variation
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'triggered' => $triggeredNow,
                'checked_count' => count($alerts),
                'triggered_count' => count($triggeredNow)
            ]
        ];
    }
}

// Exécution
$api = new PriceAlertsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
