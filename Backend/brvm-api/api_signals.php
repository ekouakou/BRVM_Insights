<?php
/**
 * API de signaux composites achat/vente
 * Endpoint: api_signals.php
 *
 * Combine les indicateurs techniques déjà persistés dans `technical_indicators`
 * (RSI, MACD, SMA, Bollinger) en un score simple (-2 à +2) par entreprise, pour
 * une aide à la décision rapide. Ce n'est pas un conseil financier : c'est une
 * synthèse mécanique d'indicateurs techniques classiques.
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

class SignalsAPI {
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
                    return $this->listSignals($input);

                case 'get':
                    return $this->getSignal($input);

                case 'history':
                    return $this->getSignalHistory($input);

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
     * Score composite pour toutes les entreprises actives, à la date la plus récente
     */
    private function listSignals($input) {
        $date = $input['date'] ?? $this->getLatestIndicatorsDate();

        if (!$date) {
            return [
                'success' => true,
                'data' => [],
                'message' => "Aucun indicateur technique calculé pour l'instant. Lancez une synchronisation (api_brvm_sync.php?action=sync_now)."
            ];
        }

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                s.name AS sector,
                sq.close_price,
                sq.variation_percent,
                ti.rsi_14,
                ti.macd_line,
                ti.macd_signal,
                ti.sma_10,
                ti.sma_20,
                ti.bb_upper,
                ti.bb_lower
            FROM technical_indicators ti
            INNER JOIN companies c ON c.id = ti.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN stock_quotes sq ON sq.company_id = ti.company_id AND sq.trading_date = ti.trading_date
            WHERE ti.trading_date = ?
            AND c.active = 1
            ORDER BY c.symbol
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$date]) ?: [];

        $signals = array_map([$this, 'buildSignal'], $rows);

        // Les plus forts signaux (achat ou vente) en premier
        usort($signals, function ($a, $b) {
            return abs($b['score'] ?? 0) <=> abs($a['score'] ?? 0);
        });

        return [
            'success' => true,
            'date' => $date,
            'data' => $signals,
            'count' => count($signals)
        ];
    }

    /**
     * Score composite pour une seule entreprise
     */
    private function getSignal($input) {
        $companyId = (int)($input['company_id'] ?? 0);
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

        $date = $input['date'] ?? $this->getLatestIndicatorsDate($companyId);

        if (!$date) {
            throw new Exception("Aucun indicateur technique disponible pour cette entreprise");
        }

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                s.name AS sector,
                sq.close_price,
                sq.variation_percent,
                ti.rsi_14,
                ti.macd_line,
                ti.macd_signal,
                ti.sma_10,
                ti.sma_20,
                ti.bb_upper,
                ti.bb_lower
            FROM technical_indicators ti
            INNER JOIN companies c ON c.id = ti.company_id
            LEFT JOIN sectors s ON s.id = c.sector_id
            LEFT JOIN stock_quotes sq ON sq.company_id = ti.company_id AND sq.trading_date = ti.trading_date
            WHERE ti.company_id = ? AND ti.trading_date = ?
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$companyId, $date]) ?: [];

        if (empty($rows)) {
            throw new Exception("Aucun indicateur technique disponible pour cette entreprise à cette date");
        }

        return [
            'success' => true,
            'data' => $this->buildSignal($rows[0])
        ];
    }

    /**
     * Historique du score composite pour une entreprise (pour tracer son évolution)
     */
    private function getSignalHistory($input) {
        $companyId = (int)($input['company_id'] ?? 0);
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

        $days = (int)($input['days'] ?? 90);
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                sq.close_price,
                sq.variation_percent,
                ti.trading_date,
                ti.rsi_14,
                ti.macd_line,
                ti.macd_signal,
                ti.sma_10,
                ti.sma_20,
                ti.bb_upper,
                ti.bb_lower
            FROM technical_indicators ti
            INNER JOIN companies c ON c.id = ti.company_id
            LEFT JOIN stock_quotes sq ON sq.company_id = ti.company_id AND sq.trading_date = ti.trading_date
            WHERE ti.company_id = ?
            AND ti.trading_date >= DATE_SUB(?, INTERVAL ? DAY)
            AND ti.trading_date <= ?
            ORDER BY ti.trading_date ASC
        ";

        $rows = $this->crud->executeCustomQuery($sql, [$companyId, $endDate, $days, $endDate]) ?: [];

        $history = array_map(function ($row) {
            $signal = $this->buildSignal($row);
            return [
                'date' => $row['trading_date'],
                'close_price' => $row['close_price'],
                'score' => $signal['score'],
                'label' => $signal['label']
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => $history,
            'count' => count($history),
            'company_id' => $companyId
        ];
    }

    /**
     * Construit le score composite (-2 à +2) et son détail à partir d'une ligne
     * combinant cotation + indicateurs techniques
     */
    private function buildSignal($row) {
        $details = [];
        $sum = 0;
        $count = 0;

        // RSI : survendu = signal d'achat, suracheté = signal de vente
        if ($row['rsi_14'] !== null) {
            $rsi = (float) $row['rsi_14'];
            if ($rsi < 30) {
                $details['rsi'] = ['value' => $rsi, 'signal' => 1, 'reason' => 'RSI < 30 (survendu)'];
            } elseif ($rsi > 70) {
                $details['rsi'] = ['value' => $rsi, 'signal' => -1, 'reason' => 'RSI > 70 (suracheté)'];
            } else {
                $details['rsi'] = ['value' => $rsi, 'signal' => 0, 'reason' => 'RSI neutre'];
            }
            $sum += $details['rsi']['signal'];
            $count++;
        }

        // MACD : ligne au-dessus du signal = momentum haussier
        if ($row['macd_line'] !== null && $row['macd_signal'] !== null) {
            $macdLine = (float) $row['macd_line'];
            $macdSignal = (float) $row['macd_signal'];
            if ($macdLine > $macdSignal) {
                $details['macd'] = ['signal' => 1, 'reason' => 'MACD au-dessus de sa ligne de signal'];
            } elseif ($macdLine < $macdSignal) {
                $details['macd'] = ['signal' => -1, 'reason' => 'MACD en-dessous de sa ligne de signal'];
            } else {
                $details['macd'] = ['signal' => 0, 'reason' => 'MACD neutre'];
            }
            $sum += $details['macd']['signal'];
            $count++;
        }

        // Tendance : cours vs moyenne mobile (SMA20 en priorité, sinon SMA10)
        $sma = $row['sma_20'] !== null ? $row['sma_20'] : $row['sma_10'];
        if ($sma !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            $smaValue = (float) $sma;
            if ($close > $smaValue) {
                $details['trend'] = ['signal' => 1, 'reason' => 'Cours au-dessus de sa moyenne mobile'];
            } elseif ($close < $smaValue) {
                $details['trend'] = ['signal' => -1, 'reason' => 'Cours en-dessous de sa moyenne mobile'];
            } else {
                $details['trend'] = ['signal' => 0, 'reason' => 'Cours proche de sa moyenne mobile'];
            }
            $sum += $details['trend']['signal'];
            $count++;
        }

        // Bandes de Bollinger : cours hors bande = potentiel retour à la moyenne
        if ($row['bb_upper'] !== null && $row['bb_lower'] !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            if ($close < (float) $row['bb_lower']) {
                $details['bollinger'] = ['signal' => 1, 'reason' => 'Cours sous la bande basse de Bollinger'];
            } elseif ($close > (float) $row['bb_upper']) {
                $details['bollinger'] = ['signal' => -1, 'reason' => 'Cours au-dessus de la bande haute de Bollinger'];
            } else {
                $details['bollinger'] = ['signal' => 0, 'reason' => 'Cours dans le canal de Bollinger'];
            }
            $sum += $details['bollinger']['signal'];
            $count++;
        }

        $score = null;
        $label = 'Indéterminé';

        if ($count > 0) {
            $score = (int) round(($sum / $count) * 2);
            $score = max(-2, min(2, $score));
            $label = $this->scoreLabel($score);
        }

        return [
            'company_id' => $row['company_id'],
            'symbol' => $row['symbol'],
            'name' => $row['name'],
            'sector' => $row['sector'] ?? null,
            'close_price' => $row['close_price'],
            'variation_percent' => $row['variation_percent'],
            'score' => $score,
            'label' => $label,
            'indicators_used' => $count,
            'details' => $details
        ];
    }

    private function scoreLabel($score) {
        switch ($score) {
            case 2: return 'Achat fort';
            case 1: return 'Achat';
            case 0: return 'Neutre';
            case -1: return 'Vente';
            case -2: return 'Vente forte';
            default: return 'Indéterminé';
        }
    }

    /**
     * Dernière date pour laquelle des indicateurs techniques existent
     */
    private function getLatestIndicatorsDate($companyId = null) {
        if ($companyId) {
            $sql = "SELECT MAX(trading_date) as last_date FROM technical_indicators WHERE company_id = ?";
            $result = $this->crud->executeCustomQuery($sql, [$companyId]);
        } else {
            $sql = "SELECT MAX(trading_date) as last_date FROM technical_indicators";
            $result = $this->crud->executeCustomQuery($sql);
        }

        return $result[0]['last_date'] ?? null;
    }
}

// Exécution
$api = new SignalsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
