<?php
/**
 * API des indicateurs techniques
 * Endpoint: api_technical_indicators.php
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

class TechnicalIndicatorsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'calculate':
                    return $this->calculateIndicators($input);
                    
                case 'get_indicators':
                    return $this->getIndicators($input);
                    
                case 'sma':
                    return $this->calculateSMA($input);
                    
                case 'ema':
                    return $this->calculateEMA($input);
                    
                case 'rsi':
                    return $this->calculateRSI($input);
                    
                case 'macd':
                    return $this->calculateMACD($input);
                    
                case 'bollinger':
                    return $this->calculateBollingerBands($input);
                    
                case 'stochastic':
                    return $this->calculateStochastic($input);
                    
                case 'atr':
                    return $this->calculateATR($input);
                    
                case 'adx':
                    return $this->calculateADX($input);
                    
                case 'williams_r':
                    return $this->calculateWilliamsR($input);
                    
                case 'cci':
                    return $this->calculateCCI($input);
                    
                case 'obv':
                    return $this->calculateOBV($input);
                    
                case 'pivot_points':
                    return $this->calculatePivotPoints($input);
                    
                case 'ichimoku':
                    return $this->calculateIchimoku($input);
                    
                case 'fibonacci':
                    return $this->calculateFibonacci($input);
                    
                case 'support_resistance':
                    return $this->calculateSupportResistance($input);
                    
                case 'parabolic_sar':
                    return $this->calculateParabolicSAR($input);
                    
                case 'mfi':
                    return $this->calculateMFI($input);
                    
                case 'aroon':
                    return $this->calculateAroon($input);
                    
                case 'vwap':
                    return $this->calculateVWAP($input);
                    
                case 'ad_line':
                    return $this->calculateADLine($input);
                    
                case 'cmf':
                    return $this->calculateCMF($input);
                    
                case 'keltner':
                    return $this->calculateKeltnerChannels($input);
                    
                case 'donchian':
                    return $this->calculateDonchianChannels($input);
                    
                case 'roc':
                    return $this->calculateROC($input);
                    
                case 'force_index':
                    return $this->calculateForceIndex($input);
                    
                case 'trix':
                    return $this->calculateTRIX($input);
                    
                case 'ultimate':
                    return $this->calculateUltimateOscillator($input);
                    
                case 'volume_profile':
                    return $this->calculateVolumeProfile($input);
                    
                case 'chaikin_oscillator':
                    return $this->calculateChaikinOscillator($input);
                    
                case 'elder_ray':
                    return $this->calculateElderRay($input);
                    
                case 'mass_index':
                    return $this->calculateMassIndex($input);
                    
                case 'vortex':
                    return $this->calculateVortex($input);
                    
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
     * Calcule tous les indicateurs pour une entreprise
     */
    private function calculateIndicators($input) {
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

        $endDate = $input['end_date'] ?? date('Y-m-d');
        
        // Récupérer les données historiques (minimum 200 jours pour SMA 200)
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, 250);
        
        if (count($quotes) < 50) {
            throw new Exception("Données insuffisantes pour calculer les indicateurs (minimum 50 jours requis)");
        }

        // Calculer tous les indicateurs
        $indicators = [
            'company_id' => $companyId,
            'date' => $endDate,
            'sma' => $this->computeSMA($quotes),
            'ema' => $this->computeEMA($quotes),
            'rsi' => $this->computeRSI($quotes),
            'macd' => $this->computeMACD($quotes),
            'bollinger' => $this->computeBollingerBands($quotes)
        ];

        return [
            'success' => true,
            'data' => $indicators
        ];
    }

    /**
     * Récupère les indicateurs stockés
     */
    private function getIndicators($input) {
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

        $days = (int)($input['days'] ?? 30);
        $startDate = $input['start_date'] ?? null;
        $endDate = $input['end_date'] ?? date('Y-m-d');

        $sql = "
            SELECT *
            FROM technical_indicators
            WHERE company_id = ?
        ";

        $params = [$companyId];

        if ($startDate) {
            $sql .= " AND trading_date >= ?";
            $params[] = $startDate;
        } else {
            $sql .= " AND trading_date >= DATE_SUB(?, INTERVAL ? DAY)";
            $params[] = $endDate;
            $params[] = $days;
        }

        if ($endDate) {
            $sql .= " AND trading_date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY trading_date ASC";

        $indicators = $this->crud->executeCustomQuery($sql, $params);

        return [
            'success' => true,
            'data' => $indicators ?: [],
            'count' => count($indicators ?: [])
        ];
    }

    /**
     * Récupère les cotations historiques
     */
    private function getHistoricalQuotes($companyId, $endDate, $days) {
        $sql = "
            SELECT 
                trading_date,
                close_price,
                high_price,
                low_price,
                volume
            FROM stock_quotes
            WHERE company_id = ?
            AND trading_date <= ?
            ORDER BY trading_date DESC
            LIMIT ?
        ";

        $quotes = $this->crud->executeCustomQuery($sql, [$companyId, $endDate, $days]);
        
        // Inverser pour avoir l'ordre chronologique
        return array_reverse($quotes);
    }

    /**
     * Calcule les moyennes mobiles simples (SMA)
     */
    private function calculateSMA($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $smaData = [];
        for ($i = $period - 1; $i < count($quotes); $i++) {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $quotes[$i - $j]['close_price'];
            }
            $smaData[] = [
                'date' => $quotes[$i]['trading_date'],
                'sma' => round($sum / $period, 2),
                'period' => $period
            ];
        }

        return [
            'success' => true,
            'data' => $smaData,
            'indicator' => 'SMA',
            'period' => $period
        ];
    }

    /**
     * Calcule les moyennes mobiles exponentielles (EMA)
     */
    private function calculateEMA($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $multiplier = 2 / ($period + 1);
        $emaData = [];

        // Calculer la première EMA (SMA)
        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $quotes[$i]['close_price'];
        }
        $ema = $sum / $period;
        
        $emaData[] = [
            'date' => $quotes[$period - 1]['trading_date'],
            'ema' => round($ema, 2),
            'period' => $period
        ];

        // Calculer les EMA suivantes
        for ($i = $period; $i < count($quotes); $i++) {
            $ema = ($quotes[$i]['close_price'] - $ema) * $multiplier + $ema;
            $emaData[] = [
                'date' => $quotes[$i]['trading_date'],
                'ema' => round($ema, 2),
                'period' => $period
            ];
        }

        return [
            'success' => true,
            'data' => $emaData,
            'indicator' => 'EMA',
            'period' => $period
        ];
    }

    /**
     * Calcule le RSI (Relative Strength Index)
     */
    private function calculateRSI($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 14);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period + 1);

        if (count($quotes) < $period + 1) {
            throw new Exception("Données insuffisantes");
        }

        $rsiData = [];
        $gains = [];
        $losses = [];

        // Calculer les gains et pertes
        for ($i = 1; $i < count($quotes); $i++) {
            $change = $quotes[$i]['close_price'] - $quotes[$i - 1]['close_price'];
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        // Calculer le RSI pour chaque point
        for ($i = $period - 1; $i < count($gains); $i++) {
            $avgGain = array_sum(array_slice($gains, $i - $period + 1, $period)) / $period;
            $avgLoss = array_sum(array_slice($losses, $i - $period + 1, $period)) / $period;

            if ($avgLoss == 0) {
                $rsi = 100;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsi = 100 - (100 / (1 + $rs));
            }

            $rsiData[] = [
                'date' => $quotes[$i + 1]['trading_date'],
                'rsi' => round($rsi, 2),
                'period' => $period
            ];
        }

        return [
            'success' => true,
            'data' => $rsiData,
            'indicator' => 'RSI',
            'period' => $period
        ];
    }

    /**
     * Calcule le MACD (Moving Average Convergence Divergence)
     */
    private function calculateMACD($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $fastPeriod = (int)($input['fast_period'] ?? 12);
        $slowPeriod = (int)($input['slow_period'] ?? 26);
        $signalPeriod = (int)($input['signal_period'] ?? 9);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $slowPeriod + $signalPeriod);

        if (count($quotes) < $slowPeriod) {
            throw new Exception("Données insuffisantes");
        }

        // Calculer EMA rapide
        $fastEMA = $this->computeEMAArray($quotes, $fastPeriod);
        
        // Calculer EMA lente
        $slowEMA = $this->computeEMAArray($quotes, $slowPeriod);

        // Calculer MACD line
        $macdLine = [];
        $startIndex = $slowPeriod - 1;
        
        for ($i = $startIndex; $i < count($quotes); $i++) {
            $macdLine[] = $fastEMA[$i] - $slowEMA[$i];
        }

        // Calculer Signal line (EMA du MACD)
        $signalLine = $this->computeEMAFromArray($macdLine, $signalPeriod);

        // Construire les résultats
        $macdData = [];
        $signalStart = $startIndex + $signalPeriod - 1;
        
        for ($i = 0; $i < count($signalLine); $i++) {
            $idx = $signalStart + $i;
            $histogram = $macdLine[$i + $signalPeriod - 1] - $signalLine[$i];
            
            $macdData[] = [
                'date' => $quotes[$idx]['trading_date'],
                'macd_line' => round($macdLine[$i + $signalPeriod - 1], 2),
                'signal_line' => round($signalLine[$i], 2),
                'histogram' => round($histogram, 2)
            ];
        }

        return [
            'success' => true,
            'data' => $macdData,
            'indicator' => 'MACD',
            'parameters' => [
                'fast' => $fastPeriod,
                'slow' => $slowPeriod,
                'signal' => $signalPeriod
            ]
        ];
    }

    /**
     * Calcule les Bandes de Bollinger
     */
    private function calculateBollingerBands($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $stdDev = (float)($input['std_dev'] ?? 2);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $bollingerData = [];

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $prices = [];
            $sum = 0;
            
            for ($j = 0; $j < $period; $j++) {
                $price = $quotes[$i - $j]['close_price'];
                $prices[] = $price;
                $sum += $price;
            }
            
            $middle = $sum / $period;
            
            // Calculer l'écart-type
            $variance = 0;
            foreach ($prices as $price) {
                $variance += pow($price - $middle, 2);
            }
            $standardDeviation = sqrt($variance / $period);
            
            $upper = $middle + ($stdDev * $standardDeviation);
            $lower = $middle - ($stdDev * $standardDeviation);
            
            $bollingerData[] = [
                'date' => $quotes[$i]['trading_date'],
                'upper_band' => round($upper, 2),
                'middle_band' => round($middle, 2),
                'lower_band' => round($lower, 2),
                'bandwidth' => round($upper - $lower, 2),
                'price' => $quotes[$i]['close_price']
            ];
        }

        return [
            'success' => true,
            'data' => $bollingerData,
            'indicator' => 'Bollinger Bands',
            'parameters' => [
                'period' => $period,
                'std_dev' => $stdDev
            ]
        ];
    }

    /**
     * Fonctions utilitaires pour les calculs
     */
    private function computeSMA($quotes) {
        $periods = [10, 20, 50, 200];
        $result = [];
        
        foreach ($periods as $period) {
            if (count($quotes) >= $period) {
                $sum = 0;
                for ($i = count($quotes) - $period; $i < count($quotes); $i++) {
                    $sum += $quotes[$i]['close_price'];
                }
                $result["sma_$period"] = round($sum / $period, 2);
            }
        }
        
        return $result;
    }

    private function computeEMA($quotes) {
        $periods = [10, 20];
        $result = [];
        
        foreach ($periods as $period) {
            if (count($quotes) >= $period) {
                $multiplier = 2 / ($period + 1);
                
                // Première EMA = SMA
                $sum = 0;
                for ($i = 0; $i < $period; $i++) {
                    $sum += $quotes[$i]['close_price'];
                }
                $ema = $sum / $period;
                
                // Calculer EMA pour les valeurs suivantes
                for ($i = $period; $i < count($quotes); $i++) {
                    $ema = ($quotes[$i]['close_price'] - $ema) * $multiplier + $ema;
                }
                
                $result["ema_$period"] = round($ema, 2);
            }
        }
        
        return $result;
    }

    private function computeRSI($quotes, $period = 14) {
        if (count($quotes) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($quotes); $i++) {
            $change = $quotes[$i]['close_price'] - $quotes[$i - 1]['close_price'];
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        $avgGain = array_sum(array_slice($gains, -$period)) / $period;
        $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

        if ($avgLoss == 0) {
            return ['rsi_14' => 100];
        }

        $rs = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return ['rsi_14' => round($rsi, 2)];
    }

    private function computeMACD($quotes) {
        if (count($quotes) < 26) {
            return [];
        }

        $fastEMA = $this->computeEMAArray($quotes, 12);
        $slowEMA = $this->computeEMAArray($quotes, 26);
        
        $lastFast = end($fastEMA);
        $lastSlow = end($slowEMA);
        
        $macdLine = $lastFast - $lastSlow;

        return [
            'macd_line' => round($macdLine, 2)
        ];
    }

    private function computeBollingerBands($quotes, $period = 20, $stdDev = 2) {
        if (count($quotes) < $period) {
            return [];
        }

        $prices = array_slice(array_column($quotes, 'close_price'), -$period);
        $middle = array_sum($prices) / $period;
        
        $variance = 0;
        foreach ($prices as $price) {
            $variance += pow($price - $middle, 2);
        }
        $standardDeviation = sqrt($variance / $period);
        
        return [
            'bb_upper' => round($middle + ($stdDev * $standardDeviation), 2),
            'bb_middle' => round($middle, 2),
            'bb_lower' => round($middle - ($stdDev * $standardDeviation), 2)
        ];
    }

    private function computeEMAArray($quotes, $period) {
        $multiplier = 2 / ($period + 1);
        $emaArray = [];
        
        // Première EMA = SMA
        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $quotes[$i]['close_price'];
        }
        $ema = $sum / $period;
        
        for ($i = 0; $i < $period; $i++) {
            $emaArray[] = 0; // Padding
        }
        $emaArray[$period - 1] = $ema;
        
        for ($i = $period; $i < count($quotes); $i++) {
            $ema = ($quotes[$i]['close_price'] - $ema) * $multiplier + $ema;
            $emaArray[] = $ema;
        }
        
        return $emaArray;
    }

    private function computeEMAFromArray($values, $period) {
        $multiplier = 2 / ($period + 1);
        $emaArray = [];
        
        // Première EMA = moyenne simple
        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $values[$i];
        }
        $ema = $sum / $period;
        $emaArray[] = $ema;
        
        for ($i = $period; $i < count($values); $i++) {
            $ema = ($values[$i] - $ema) * $multiplier + $ema;
            $emaArray[] = $ema;
        }
        
        return $emaArray;
    }

    /**
     * Calcule le Stochastique (Stochastic Oscillator)
     */
    private function calculateStochastic($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $periodK = (int)($input['period_k'] ?? 14);
        $periodD = (int)($input['period_d'] ?? 3);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $periodK);

        if (count($quotes) < $periodK) {
            throw new Exception("Données insuffisantes");
        }

        $stochasticData = [];

        for ($i = $periodK - 1; $i < count($quotes); $i++) {
            $highestHigh = 0;
            $lowestLow = PHP_FLOAT_MAX;
            
            // Trouver le plus haut et le plus bas sur la période
            for ($j = 0; $j < $periodK; $j++) {
                $high = $quotes[$i - $j]['high_price'];
                $low = $quotes[$i - $j]['low_price'];
                
                if ($high > $highestHigh) $highestHigh = $high;
                if ($low < $lowestLow) $lowestLow = $low;
            }
            
            $currentClose = $quotes[$i]['close_price'];
            
            // %K = (Prix actuel - Plus bas) / (Plus haut - Plus bas) * 100
            if ($highestHigh - $lowestLow != 0) {
                $percentK = (($currentClose - $lowestLow) / ($highestHigh - $lowestLow)) * 100;
            } else {
                $percentK = 50;
            }
            
            $stochasticData[] = [
                'date' => $quotes[$i]['trading_date'],
                'k' => round($percentK, 2),
                'high' => $highestHigh,
                'low' => $lowestLow
            ];
        }

        // Calculer %D (moyenne mobile de %K)
        for ($i = $periodD - 1; $i < count($stochasticData); $i++) {
            $sum = 0;
            for ($j = 0; $j < $periodD; $j++) {
                $sum += $stochasticData[$i - $j]['k'];
            }
            $stochasticData[$i]['d'] = round($sum / $periodD, 2);
        }

        return [
            'success' => true,
            'data' => array_slice($stochasticData, $periodD - 1),
            'indicator' => 'Stochastic',
            'parameters' => [
                'period_k' => $periodK,
                'period_d' => $periodD
            ]
        ];
    }

    /**
     * Calcule l'ATR (Average True Range)
     */
    private function calculateATR($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 14);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period + 1) {
            throw new Exception("Données insuffisantes");
        }

        $trueRanges = [];
        
        for ($i = 1; $i < count($quotes); $i++) {
            $high = $quotes[$i]['high_price'];
            $low = $quotes[$i]['low_price'];
            $prevClose = $quotes[$i - 1]['close_price'];
            
            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            
            $trueRanges[] = $tr;
        }

        $atrData = [];
        
        // Première ATR = moyenne des TR
        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $trueRanges[$i];
        }
        $atr = $sum / $period;
        
        $atrData[] = [
            'date' => $quotes[$period]['trading_date'],
            'atr' => round($atr, 2),
            'true_range' => round($trueRanges[$period - 1], 2)
        ];

        // ATR suivants = (ATR précédent * (n-1) + TR actuel) / n
        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
            $atrData[] = [
                'date' => $quotes[$i + 1]['trading_date'],
                'atr' => round($atr, 2),
                'true_range' => round($trueRanges[$i], 2)
            ];
        }

        return [
            'success' => true,
            'data' => $atrData,
            'indicator' => 'ATR',
            'period' => $period
        ];
    }

    /**
     * Calcule l'ADX (Average Directional Index)
     */
    private function calculateADX($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 14);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period * 2);

        if (count($quotes) < $period * 2) {
            throw new Exception("Données insuffisantes");
        }

        $adxData = [];
        $plusDM = [];
        $minusDM = [];
        $tr = [];

        // Calculer +DM, -DM et TR
        for ($i = 1; $i < count($quotes); $i++) {
            $high = $quotes[$i]['high_price'];
            $low = $quotes[$i]['low_price'];
            $prevHigh = $quotes[$i - 1]['high_price'];
            $prevLow = $quotes[$i - 1]['low_price'];
            $prevClose = $quotes[$i - 1]['close_price'];
            
            $upMove = $high - $prevHigh;
            $downMove = $prevLow - $low;
            
            $plusDM[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0;
            $minusDM[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0;
            
            $tr[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        // Calculer les moyennes lissées
        $smoothedPlusDM = array_sum(array_slice($plusDM, 0, $period));
        $smoothedMinusDM = array_sum(array_slice($minusDM, 0, $period));
        $smoothedTR = array_sum(array_slice($tr, 0, $period));

        $dxValues = [];

        for ($i = $period; $i < count($plusDM); $i++) {
            $smoothedPlusDM = $smoothedPlusDM - ($smoothedPlusDM / $period) + $plusDM[$i];
            $smoothedMinusDM = $smoothedMinusDM - ($smoothedMinusDM / $period) + $minusDM[$i];
            $smoothedTR = $smoothedTR - ($smoothedTR / $period) + $tr[$i];
            
            $plusDI = ($smoothedTR != 0) ? ($smoothedPlusDM / $smoothedTR) * 100 : 0;
            $minusDI = ($smoothedTR != 0) ? ($smoothedMinusDM / $smoothedTR) * 100 : 0;
            
            $diSum = $plusDI + $minusDI;
            $dx = ($diSum != 0) ? (abs($plusDI - $minusDI) / $diSum) * 100 : 0;
            
            $dxValues[] = $dx;
            
            if (count($dxValues) >= $period) {
                $adx = array_sum(array_slice($dxValues, -$period)) / $period;
                
                $adxData[] = [
                    'date' => $quotes[$i + 1]['trading_date'],
                    'adx' => round($adx, 2),
                    'plus_di' => round($plusDI, 2),
                    'minus_di' => round($minusDI, 2)
                ];
            }
        }

        return [
            'success' => true,
            'data' => $adxData,
            'indicator' => 'ADX',
            'period' => $period
        ];
    }

    /**
     * Calcule Williams %R
     */
    private function calculateWilliamsR($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 14);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $williamsData = [];

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $highestHigh = 0;
            $lowestLow = PHP_FLOAT_MAX;
            
            for ($j = 0; $j < $period; $j++) {
                $high = $quotes[$i - $j]['high_price'];
                $low = $quotes[$i - $j]['low_price'];
                
                if ($high > $highestHigh) $highestHigh = $high;
                if ($low < $lowestLow) $lowestLow = $low;
            }
            
            $currentClose = $quotes[$i]['close_price'];
            
            if ($highestHigh - $lowestLow != 0) {
                $williamsR = (($highestHigh - $currentClose) / ($highestHigh - $lowestLow)) * -100;
            } else {
                $williamsR = -50;
            }
            
            $williamsData[] = [
                'date' => $quotes[$i]['trading_date'],
                'williams_r' => round($williamsR, 2)
            ];
        }

        return [
            'success' => true,
            'data' => $williamsData,
            'indicator' => 'Williams %R',
            'period' => $period
        ];
    }

    /**
     * Calcule le CCI (Commodity Channel Index)
     */
    private function calculateCCI($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $cciData = [];
        $constant = 0.015;

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $typicalPrices = [];
            
            for ($j = 0; $j < $period; $j++) {
                $tp = ($quotes[$i - $j]['high_price'] + 
                      $quotes[$i - $j]['low_price'] + 
                      $quotes[$i - $j]['close_price']) / 3;
                $typicalPrices[] = $tp;
            }
            
            $smaTP = array_sum($typicalPrices) / $period;
            $currentTP = $typicalPrices[0];
            
            // Calculer la déviation moyenne
            $meanDeviation = 0;
            foreach ($typicalPrices as $tp) {
                $meanDeviation += abs($tp - $smaTP);
            }
            $meanDeviation /= $period;
            
            if ($meanDeviation != 0) {
                $cci = ($currentTP - $smaTP) / ($constant * $meanDeviation);
            } else {
                $cci = 0;
            }
            
            $cciData[] = [
                'date' => $quotes[$i]['trading_date'],
                'cci' => round($cci, 2)
            ];
        }

        return [
            'success' => true,
            'data' => $cciData,
            'indicator' => 'CCI',
            'period' => $period
        ];
    }

    /**
     * Calcule l'OBV (On-Balance Volume)
     */
    private function calculateOBV($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (count($quotes) < 2) {
            throw new Exception("Données insuffisantes");
        }

        $obvData = [];
        $obv = 0;

        for ($i = 0; $i < count($quotes); $i++) {
            if ($i == 0) {
                $obv = $quotes[$i]['volume'];
            } else {
                if ($quotes[$i]['close_price'] > $quotes[$i - 1]['close_price']) {
                    $obv += $quotes[$i]['volume'];
                } elseif ($quotes[$i]['close_price'] < $quotes[$i - 1]['close_price']) {
                    $obv -= $quotes[$i]['volume'];
                }
            }
            
            $obvData[] = [
                'date' => $quotes[$i]['trading_date'],
                'obv' => $obv,
                'volume' => $quotes[$i]['volume'],
                'price' => $quotes[$i]['close_price']
            ];
        }

        return [
            'success' => true,
            'data' => $obvData,
            'indicator' => 'OBV'
        ];
    }

    /**
     * Calcule les Points Pivots
     */
    private function calculatePivotPoints($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 30);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (empty($quotes)) {
            throw new Exception("Données insuffisantes");
        }

        $pivotData = [];

        for ($i = 1; $i < count($quotes); $i++) {
            $prevHigh = $quotes[$i - 1]['high_price'];
            $prevLow = $quotes[$i - 1]['low_price'];
            $prevClose = $quotes[$i - 1]['close_price'];
            
            // Pivot Point
            $pp = ($prevHigh + $prevLow + $prevClose) / 3;
            
            // Résistances
            $r1 = (2 * $pp) - $prevLow;
            $r2 = $pp + ($prevHigh - $prevLow);
            $r3 = $prevHigh + 2 * ($pp - $prevLow);
            
            // Supports
            $s1 = (2 * $pp) - $prevHigh;
            $s2 = $pp - ($prevHigh - $prevLow);
            $s3 = $prevLow - 2 * ($prevHigh - $pp);
            
            $pivotData[] = [
                'date' => $quotes[$i]['trading_date'],
                'pivot_point' => round($pp, 2),
                'resistance_1' => round($r1, 2),
                'resistance_2' => round($r2, 2),
                'resistance_3' => round($r3, 2),
                'support_1' => round($s1, 2),
                'support_2' => round($s2, 2),
                'support_3' => round($s3, 2),
                'current_price' => $quotes[$i]['close_price']
            ];
        }

        return [
            'success' => true,
            'data' => $pivotData,
            'indicator' => 'Pivot Points'
        ];
    }

    /**
     * Calcule Ichimoku Cloud
     */
    private function calculateIchimoku($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + 52);

        if (count($quotes) < 52) {
            throw new Exception("Données insuffisantes (minimum 52 jours)");
        }

        $ichimokuData = [];

        for ($i = 51; $i < count($quotes); $i++) {
            // Tenkan-sen (ligne de conversion) - 9 périodes
            $tenkanHigh = max(array_column(array_slice($quotes, $i - 8, 9), 'high_price'));
            $tenkanLow = min(array_column(array_slice($quotes, $i - 8, 9), 'low_price'));
            $tenkan = ($tenkanHigh + $tenkanLow) / 2;
            
            // Kijun-sen (ligne de base) - 26 périodes
            $kijunHigh = max(array_column(array_slice($quotes, $i - 25, 26), 'high_price'));
            $kijunLow = min(array_column(array_slice($quotes, $i - 25, 26), 'low_price'));
            $kijun = ($kijunHigh + $kijunLow) / 2;
            
            // Senkou Span A (ligne avancée A)
            $senkouA = ($tenkan + $kijun) / 2;
            
            // Senkou Span B (ligne avancée B) - 52 périodes
            $senkouBHigh = max(array_column(array_slice($quotes, $i - 51, 52), 'high_price'));
            $senkouBLow = min(array_column(array_slice($quotes, $i - 51, 52), 'low_price'));
            $senkouB = ($senkouBHigh + $senkouBLow) / 2;
            
            // Chikou Span (ligne retardée)
            $chikou = $quotes[$i]['close_price'];
            
            $ichimokuData[] = [
                'date' => $quotes[$i]['trading_date'],
                'tenkan_sen' => round($tenkan, 2),
                'kijun_sen' => round($kijun, 2),
                'senkou_span_a' => round($senkouA, 2),
                'senkou_span_b' => round($senkouB, 2),
                'chikou_span' => round($chikou, 2),
                'price' => $quotes[$i]['close_price']
            ];
        }

        return [
            'success' => true,
            'data' => $ichimokuData,
            'indicator' => 'Ichimoku Cloud'
        ];
    }

    /**
     * Calcule les niveaux de Fibonacci
     */
    private function calculateFibonacci($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 90);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (count($quotes) < 10) {
            throw new Exception("Données insuffisantes");
        }

        // Trouver le plus haut et le plus bas
        $prices = array_column($quotes, 'close_price');
        $highest = max($prices);
        $lowest = min($prices);
        $diff = $highest - $lowest;

        // Niveaux de Fibonacci
        $levels = [
            0 => $highest,
            23.6 => $highest - ($diff * 0.236),
            38.2 => $highest - ($diff * 0.382),
            50 => $highest - ($diff * 0.5),
            61.8 => $highest - ($diff * 0.618),
            78.6 => $highest - ($diff * 0.786),
            100 => $lowest
        ];

        $fibData = [];
        foreach ($levels as $percentage => $level) {
            $fibData[] = [
                'level' => $percentage,
                'price' => round($level, 2)
            ];
        }

        return [
            'success' => true,
            'data' => [
                'levels' => $fibData,
                'highest' => $highest,
                'lowest' => $lowest,
                'range' => round($diff, 2),
                'current_price' => end($quotes)['close_price']
            ],
            'indicator' => 'Fibonacci Retracement',
            'period' => [
                'start' => $quotes[0]['trading_date'],
                'end' => end($quotes)['trading_date']
            ]
        ];
    }

    /**
     * Identifie les niveaux de support et résistance
     */
    private function calculateSupportResistance($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 90);
        $sensitivity = (float)($input['sensitivity'] ?? 0.02); // 2% par défaut
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (count($quotes) < 10) {
            throw new Exception("Données insuffisantes");
        }

        $prices = array_column($quotes, 'close_price');
        $currentPrice = end($prices);
        
        // Identifier les points pivots (hauts et bas locaux)
        $pivots = [];
        
        for ($i = 2; $i < count($quotes) - 2; $i++) {
            $price = $quotes[$i]['close_price'];
            $prev2 = $quotes[$i - 2]['close_price'];
            $prev1 = $quotes[$i - 1]['close_price'];
            $next1 = $quotes[$i + 1]['close_price'];
            $next2 = $quotes[$i + 2]['close_price'];
            
            // Point haut
            if ($price > $prev2 && $price > $prev1 && $price > $next1 && $price > $next2) {
                $pivots[] = [
                    'type' => 'resistance',
                    'price' => $price,
                    'date' => $quotes[$i]['trading_date']
                ];
            }
            
            // Point bas
            if ($price < $prev2 && $price < $prev1 && $price < $next1 && $price < $next2) {
                $pivots[] = [
                    'type' => 'support',
                    'price' => $price,
                    'date' => $quotes[$i]['trading_date']
                ];
            }
        }

        // Regrouper les niveaux proches
        $supports = [];
        $resistances = [];
        
        foreach ($pivots as $pivot) {
            $found = false;
            
            if ($pivot['type'] === 'support') {
                foreach ($supports as &$support) {
                    if (abs($support['price'] - $pivot['price']) / $pivot['price'] < $sensitivity) {
                        $support['count']++;
                        $support['price'] = ($support['price'] * ($support['count'] - 1) + $pivot['price']) / $support['count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $supports[] = ['price' => $pivot['price'], 'count' => 1];
                }
            } else {
                foreach ($resistances as &$resistance) {
                    if (abs($resistance['price'] - $pivot['price']) / $pivot['price'] < $sensitivity) {
                        $resistance['count']++;
                        $resistance['price'] = ($resistance['price'] * ($resistance['count'] - 1) + $pivot['price']) / $resistance['count'];
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $resistances[] = ['price' => $pivot['price'], 'count' => 1];
                }
            }
        }

        // Trier par force (nombre de touches)
        usort($supports, function($a, $b) { return $b['count'] - $a['count']; });
        usort($resistances, function($a, $b) { return $b['count'] - $a['count']; });

        // Formater les résultats
        $formattedSupports = array_map(function($s) {
            return [
                'price' => round($s['price'], 2),
                'strength' => $s['count']
            ];
        }, array_slice($supports, 0, 5));

        $formattedResistances = array_map(function($r) {
            return [
                'price' => round($r['price'], 2),
                'strength' => $r['count']
            ];
        }, array_slice($resistances, 0, 5));

        return [
            'success' => true,
            'data' => [
                'supports' => $formattedSupports,
                'resistances' => $formattedResistances,
                'current_price' => $currentPrice,
                'nearest_support' => !empty($formattedSupports) ? $formattedSupports[0]['price'] : null,
                'nearest_resistance' => !empty($formattedResistances) ? $formattedResistances[0]['price'] : null
            ],
            'indicator' => 'Support & Resistance',
            'parameters' => [
                'sensitivity' => $sensitivity,
                'days' => $days
            ]
        ];
    }

    /**
     * Calcule le Parabolic SAR (Stop and Reverse)
     */
    private function calculateParabolicSAR($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $acceleration = (float)($input['acceleration'] ?? 0.02);
        $maxAcceleration = (float)($input['max_acceleration'] ?? 0.2);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (count($quotes) < 5) {
            throw new Exception("Données insuffisantes");
        }

        $sarData = [];
        $isUptrend = true;
        $sar = $quotes[0]['low_price'];
        $ep = $quotes[0]['high_price']; // Extreme Point
        $af = $acceleration;

        for ($i = 1; $i < count($quotes); $i++) {
            $prevSar = $sar;
            
            // Calculer nouveau SAR
            $sar = $prevSar + $af * ($ep - $prevSar);
            
            if ($isUptrend) {
                // En tendance haussière
                $sar = min($sar, $quotes[$i - 1]['low_price']);
                if ($i > 1) {
                    $sar = min($sar, $quotes[$i - 2]['low_price']);
                }
                
                // Vérifier retournement
                if ($quotes[$i]['low_price'] < $sar) {
                    $isUptrend = false;
                    $sar = $ep;
                    $ep = $quotes[$i]['low_price'];
                    $af = $acceleration;
                } else {
                    if ($quotes[$i]['high_price'] > $ep) {
                        $ep = $quotes[$i]['high_price'];
                        $af = min($af + $acceleration, $maxAcceleration);
                    }
                }
            } else {
                // En tendance baissière
                $sar = max($sar, $quotes[$i - 1]['high_price']);
                if ($i > 1) {
                    $sar = max($sar, $quotes[$i - 2]['high_price']);
                }
                
                // Vérifier retournement
                if ($quotes[$i]['high_price'] > $sar) {
                    $isUptrend = true;
                    $sar = $ep;
                    $ep = $quotes[$i]['high_price'];
                    $af = $acceleration;
                } else {
                    if ($quotes[$i]['low_price'] < $ep) {
                        $ep = $quotes[$i]['low_price'];
                        $af = min($af + $acceleration, $maxAcceleration);
                    }
                }
            }
            
            $sarData[] = [
                'date' => $quotes[$i]['trading_date'],
                'sar' => round($sar, 2),
                'trend' => $isUptrend ? 'bullish' : 'bearish',
                'price' => $quotes[$i]['close_price'],
                'signal' => $quotes[$i]['close_price'] > $sar ? 'buy' : 'sell'
            ];
        }

        return [
            'success' => true,
            'data' => $sarData,
            'indicator' => 'Parabolic SAR',
            'parameters' => [
                'acceleration' => $acceleration,
                'max_acceleration' => $maxAcceleration
            ]
        ];
    }

    /**
     * Calcule le MFI (Money Flow Index)
     */
    private function calculateMFI($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 14);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period + 1) {
            throw new Exception("Données insuffisantes");
        }

        $mfiData = [];
        $typicalPrices = [];
        $moneyFlows = [];

        // Calculer Typical Price et Money Flow
        for ($i = 0; $i < count($quotes); $i++) {
            $tp = ($quotes[$i]['high_price'] + $quotes[$i]['low_price'] + $quotes[$i]['close_price']) / 3;
            $typicalPrices[] = $tp;
            $moneyFlows[] = $tp * $quotes[$i]['volume'];
        }

        for ($i = $period; $i < count($quotes); $i++) {
            $positiveFlow = 0;
            $negativeFlow = 0;
            
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                if ($typicalPrices[$j] > $typicalPrices[$j - 1]) {
                    $positiveFlow += $moneyFlows[$j];
                } else if ($typicalPrices[$j] < $typicalPrices[$j - 1]) {
                    $negativeFlow += $moneyFlows[$j];
                }
            }
            
            if ($negativeFlow == 0) {
                $mfi = 100;
            } else {
                $moneyRatio = $positiveFlow / $negativeFlow;
                $mfi = 100 - (100 / (1 + $moneyRatio));
            }
            
            $mfiData[] = [
                'date' => $quotes[$i]['trading_date'],
                'mfi' => round($mfi, 2),
                'signal' => $mfi > 80 ? 'overbought' : ($mfi < 20 ? 'oversold' : 'neutral')
            ];
        }

        return [
            'success' => true,
            'data' => $mfiData,
            'indicator' => 'Money Flow Index',
            'period' => $period
        ];
    }

    /**
     * Calcule l'indicateur Aroon
     */
    private function calculateAroon($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 25);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $aroonData = [];

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $highestIdx = 0;
            $lowestIdx = 0;
            $highest = 0;
            $lowest = PHP_FLOAT_MAX;
            
            // Trouver position du plus haut et plus bas
            for ($j = 0; $j < $period; $j++) {
                $idx = $i - $j;
                if ($quotes[$idx]['high_price'] >= $highest) {
                    $highest = $quotes[$idx]['high_price'];
                    $highestIdx = $j;
                }
                if ($quotes[$idx]['low_price'] <= $lowest) {
                    $lowest = $quotes[$idx]['low_price'];
                    $lowestIdx = $j;
                }
            }
            
            $aroonUp = (($period - $highestIdx) / $period) * 100;
            $aroonDown = (($period - $lowestIdx) / $period) * 100;
            $aroonOscillator = $aroonUp - $aroonDown;
            
            $aroonData[] = [
                'date' => $quotes[$i]['trading_date'],
                'aroon_up' => round($aroonUp, 2),
                'aroon_down' => round($aroonDown, 2),
                'aroon_oscillator' => round($aroonOscillator, 2),
                'signal' => $aroonUp > 70 && $aroonDown < 30 ? 'strong_bullish' : 
                           ($aroonDown > 70 && $aroonUp < 30 ? 'strong_bearish' : 'neutral')
            ];
        }

        return [
            'success' => true,
            'data' => $aroonData,
            'indicator' => 'Aroon',
            'period' => $period
        ];
    }

    /**
     * Calcule le VWAP (Volume Weighted Average Price)
     */
    private function calculateVWAP($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 30);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (empty($quotes)) {
            throw new Exception("Données insuffisantes");
        }

        $vwapData = [];
        $cumulativeTPV = 0;
        $cumulativeVolume = 0;

        foreach ($quotes as $quote) {
            $typicalPrice = ($quote['high_price'] + $quote['low_price'] + $quote['close_price']) / 3;
            $tpv = $typicalPrice * $quote['volume'];
            
            $cumulativeTPV += $tpv;
            $cumulativeVolume += $quote['volume'];
            
            $vwap = $cumulativeVolume > 0 ? $cumulativeTPV / $cumulativeVolume : $typicalPrice;
            
            $vwapData[] = [
                'date' => $quote['trading_date'],
                'vwap' => round($vwap, 2),
                'price' => $quote['close_price'],
                'volume' => $quote['volume'],
                'signal' => $quote['close_price'] > $vwap ? 'above_vwap' : 'below_vwap'
            ];
        }

        return [
            'success' => true,
            'data' => $vwapData,
            'indicator' => 'VWAP',
            'note' => 'VWAP se réinitialise typiquement chaque jour de trading'
        ];
    }

    /**
     * Calcule l'A/D Line (Accumulation/Distribution)
     */
    private function calculateADLine($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (empty($quotes)) {
            throw new Exception("Données insuffisantes");
        }

        $adData = [];
        $adLine = 0;

        foreach ($quotes as $quote) {
            $high = $quote['high_price'];
            $low = $quote['low_price'];
            $close = $quote['close_price'];
            $volume = $quote['volume'];
            
            if ($high != $low) {
                $clv = (($close - $low) - ($high - $close)) / ($high - $low);
            } else {
                $clv = 0;
            }
            
            $adLine += $clv * $volume;
            
            $adData[] = [
                'date' => $quote['trading_date'],
                'ad_line' => round($adLine, 2),
                'price' => $close,
                'volume' => $volume
            ];
        }

        return [
            'success' => true,
            'data' => $adData,
            'indicator' => 'Accumulation/Distribution Line'
        ];
    }

    /**
     * Calcule le CMF (Chaikin Money Flow)
     */
    private function calculateCMF($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $cmfData = [];

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $sumMoneyFlow = 0;
            $sumVolume = 0;
            
            for ($j = 0; $j < $period; $j++) {
                $idx = $i - $j;
                $high = $quotes[$idx]['high_price'];
                $low = $quotes[$idx]['low_price'];
                $close = $quotes[$idx]['close_price'];
                $volume = $quotes[$idx]['volume'];
                
                if ($high != $low) {
                    $mfMultiplier = (($close - $low) - ($high - $close)) / ($high - $low);
                } else {
                    $mfMultiplier = 0;
                }
                
                $mfVolume = $mfMultiplier * $volume;
                $sumMoneyFlow += $mfVolume;
                $sumVolume += $volume;
            }
            
            $cmf = $sumVolume > 0 ? $sumMoneyFlow / $sumVolume : 0;
            
            $cmfData[] = [
                'date' => $quotes[$i]['trading_date'],
                'cmf' => round($cmf, 4),
                'signal' => $cmf > 0.1 ? 'buying_pressure' : ($cmf < -0.1 ? 'selling_pressure' : 'neutral')
            ];
        }

        return [
            'success' => true,
            'data' => $cmfData,
            'indicator' => 'Chaikin Money Flow',
            'period' => $period
        ];
    }

    /**
     * Calcule les Keltner Channels
     */
    private function calculateKeltnerChannels($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $atrPeriod = (int)($input['atr_period'] ?? 10);
        $multiplier = (float)($input['multiplier'] ?? 2);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + max($period, $atrPeriod));

        if (count($quotes) < max($period, $atrPeriod) + 1) {
            throw new Exception("Données insuffisantes");
        }

        // Calculer EMA
        $emaValues = $this->computeEMAArray($quotes, $period);
        
        // Calculer ATR
        $trueRanges = [];
        for ($i = 1; $i < count($quotes); $i++) {
            $high = $quotes[$i]['high_price'];
            $low = $quotes[$i]['low_price'];
            $prevClose = $quotes[$i - 1]['close_price'];
            
            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            $trueRanges[] = $tr;
        }

        // Moyenne mobile de l'ATR
        $keltnerData = [];
        $startIdx = max($period, $atrPeriod);
        
        for ($i = $startIdx; $i < count($quotes); $i++) {
            $avgTR = array_sum(array_slice($trueRanges, $i - $atrPeriod, $atrPeriod)) / $atrPeriod;
            
            $middle = $emaValues[$i];
            $upper = $middle + ($multiplier * $avgTR);
            $lower = $middle - ($multiplier * $avgTR);
            
            $keltnerData[] = [
                'date' => $quotes[$i]['trading_date'],
                'upper_channel' => round($upper, 2),
                'middle_line' => round($middle, 2),
                'lower_channel' => round($lower, 2),
                'price' => $quotes[$i]['close_price'],
                'atr' => round($avgTR, 2)
            ];
        }

        return [
            'success' => true,
            'data' => $keltnerData,
            'indicator' => 'Keltner Channels',
            'parameters' => [
                'ema_period' => $period,
                'atr_period' => $atrPeriod,
                'multiplier' => $multiplier
            ]
        ];
    }

    /**
     * Calcule les Donchian Channels
     */
    private function calculateDonchianChannels($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 20);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $donchianData = [];

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $highs = [];
            $lows = [];
            
            for ($j = 0; $j < $period; $j++) {
                $highs[] = $quotes[$i - $j]['high_price'];
                $lows[] = $quotes[$i - $j]['low_price'];
            }
            
            $upper = max($highs);
            $lower = min($lows);
            $middle = ($upper + $lower) / 2;
            
            $donchianData[] = [
                'date' => $quotes[$i]['trading_date'],
                'upper_band' => round($upper, 2),
                'middle_band' => round($middle, 2),
                'lower_band' => round($lower, 2),
                'price' => $quotes[$i]['close_price'],
                'bandwidth' => round($upper - $lower, 2)
            ];
        }

        return [
            'success' => true,
            'data' => $donchianData,
            'indicator' => 'Donchian Channels',
            'period' => $period
        ];
    }

    /**
     * Calcule le ROC (Rate of Change)
     */
    private function calculateROC($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 12);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        $rocData = [];

        for ($i = $period; $i < count($quotes); $i++) {
            $currentPrice = $quotes[$i]['close_price'];
            $pastPrice = $quotes[$i - $period]['close_price'];
            
            if ($pastPrice != 0) {
                $roc = (($currentPrice - $pastPrice) / $pastPrice) * 100;
            } else {
                $roc = 0;
            }
            
            $rocData[] = [
                'date' => $quotes[$i]['trading_date'],
                'roc' => round($roc, 2),
                'price' => $currentPrice,
                'signal' => $roc > 0 ? 'positive_momentum' : 'negative_momentum'
            ];
        }

        return [
            'success' => true,
            'data' => $rocData,
            'indicator' => 'Rate of Change',
            'period' => $period
        ];
    }

    /**
     * Calcule le Force Index (Elder)
     */
    private function calculateForceIndex($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 13);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period + 1) {
            throw new Exception("Données insuffisantes");
        }

        $rawForceIndex = [];
        
        for ($i = 1; $i < count($quotes); $i++) {
            $priceChange = $quotes[$i]['close_price'] - $quotes[$i - 1]['close_price'];
            $volume = $quotes[$i]['volume'];
            $rawForceIndex[] = $priceChange * $volume;
        }

        // EMA du Force Index
        $emaForce = $this->computeEMAFromArray($rawForceIndex, $period);
        
        $forceData = [];
        for ($i = 0; $i < count($emaForce); $i++) {
            $forceData[] = [
                'date' => $quotes[$i + $period]['trading_date'],
                'force_index' => round($emaForce[$i], 2),
                'signal' => $emaForce[$i] > 0 ? 'bullish_force' : 'bearish_force'
            ];
        }

        return [
            'success' => true,
            'data' => $forceData,
            'indicator' => 'Force Index (Elder)',
            'period' => $period
        ];
    }

    /**
     * Calcule le TRIX (Triple Exponential Average)
     */
    private function calculateTRIX($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 15);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + ($period * 3));

        if (count($quotes) < $period * 3) {
            throw new Exception("Données insuffisantes");
        }

        $prices = array_column($quotes, 'close_price');
        
        // Triple lissage exponentiel
        $ema1 = $this->computeEMAFromArray($prices, $period);
        $ema2 = $this->computeEMAFromArray($ema1, $period);
        $ema3 = $this->computeEMAFromArray($ema2, $period);

        $trixData = [];
        
        for ($i = 1; $i < count($ema3); $i++) {
            $trix = (($ema3[$i] - $ema3[$i - 1]) / $ema3[$i - 1]) * 100;
            
            $trixData[] = [
                'date' => $quotes[$i + ($period * 3) - 2]['trading_date'],
                'trix' => round($trix, 4),
                'signal' => $trix > 0 ? 'bullish' : 'bearish'
            ];
        }

        return [
            'success' => true,
            'data' => $trixData,
            'indicator' => 'TRIX',
            'period' => $period
        ];
    }

    /**
     * Calcule l'Ultimate Oscillator
     */
    private function calculateUltimateOscillator($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period1 = (int)($input['period1'] ?? 7);
        $period2 = (int)($input['period2'] ?? 14);
        $period3 = (int)($input['period3'] ?? 28);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $maxPeriod = max($period1, $period2, $period3);
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $maxPeriod);

        if (count($quotes) < $maxPeriod + 1) {
            throw new Exception("Données insuffisantes");
        }

        $buyingPressure = [];
        $trueRange = [];

        for ($i = 1; $i < count($quotes); $i++) {
            $close = $quotes[$i]['close_price'];
            $low = $quotes[$i]['low_price'];
            $prevClose = $quotes[$i - 1]['close_price'];
            $high = $quotes[$i]['high_price'];
            
            $bp = $close - min($low, $prevClose);
            $tr = max($high, $prevClose) - min($low, $prevClose);
            
            $buyingPressure[] = $bp;
            $trueRange[] = $tr;
        }

        $ultimateData = [];

        for ($i = $maxPeriod - 1; $i < count($buyingPressure); $i++) {
            // Calcul pour chaque période
            $avg1 = array_sum(array_slice($buyingPressure, $i - $period1 + 1, $period1)) / 
                    array_sum(array_slice($trueRange, $i - $period1 + 1, $period1));
            
            $avg2 = array_sum(array_slice($buyingPressure, $i - $period2 + 1, $period2)) / 
                    array_sum(array_slice($trueRange, $i - $period2 + 1, $period2));
            
            $avg3 = array_sum(array_slice($buyingPressure, $i - $period3 + 1, $period3)) / 
                    array_sum(array_slice($trueRange, $i - $period3 + 1, $period3));
            
            $ultimate = 100 * ((4 * $avg1) + (2 * $avg2) + $avg3) / (4 + 2 + 1);
            
            $ultimateData[] = [
                'date' => $quotes[$i + 1]['trading_date'],
                'ultimate' => round($ultimate, 2),
                'signal' => $ultimate > 70 ? 'overbought' : ($ultimate < 30 ? 'oversold' : 'neutral')
            ];
        }

        return [
            'success' => true,
            'data' => $ultimateData,
            'indicator' => 'Ultimate Oscillator',
            'parameters' => [
                'period1' => $period1,
                'period2' => $period2,
                'period3' => $period3
            ]
        ];
    }

    /**
     * Calcule le Volume Profile
     */
    private function calculateVolumeProfile($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $days = (int)($input['days'] ?? 90);
        $priceLevels = (int)($input['price_levels'] ?? 24); // Nombre de niveaux de prix
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days);

        if (empty($quotes)) {
            throw new Exception("Données insuffisantes");
        }

        // Trouver le range de prix
        $prices = array_column($quotes, 'close_price');
        $minPrice = min($prices);
        $maxPrice = max($prices);
        $priceRange = $maxPrice - $minPrice;
        $priceStep = $priceRange / $priceLevels;

        // Initialiser les niveaux
        $volumeByLevel = [];
        for ($i = 0; $i < $priceLevels; $i++) {
            $levelPrice = $minPrice + ($i * $priceStep);
            $volumeByLevel[] = [
                'price_level' => round($levelPrice, 2),
                'price_min' => round($levelPrice, 2),
                'price_max' => round($levelPrice + $priceStep, 2),
                'volume' => 0,
                'trades' => 0
            ];
        }

        // Distribuer les volumes
        foreach ($quotes as $quote) {
            $price = $quote['close_price'];
            $volume = $quote['volume'];
            
            // Trouver le niveau correspondant
            if ($priceStep > 0) {
                $levelIndex = min(
                    floor(($price - $minPrice) / $priceStep),
                    $priceLevels - 1
                );
            } else {
                $levelIndex = 0;
            }
            
            if ($levelIndex >= 0 && $levelIndex < $priceLevels) {
                $volumeByLevel[$levelIndex]['volume'] += $volume;
                $volumeByLevel[$levelIndex]['trades']++;
            }
        }

        // Identifier le POC (Point of Control - niveau avec le plus de volume)
        $maxVolume = 0;
        $pocLevel = null;
        
        foreach ($volumeByLevel as $level) {
            if ($level['volume'] > $maxVolume) {
                $maxVolume = $level['volume'];
                $pocLevel = $level;
            }
        }

        // Calculer les Value Areas (70% du volume)
        $totalVolume = array_sum(array_column($volumeByLevel, 'volume'));
        $targetVolume = $totalVolume * 0.7;
        
        // Trier par volume décroissant
        $sortedLevels = $volumeByLevel;
        usort($sortedLevels, function($a, $b) {
            return $b['volume'] - $a['volume'];
        });
        
        $valueAreaVolume = 0;
        $valueAreaLevels = [];
        
        foreach ($sortedLevels as $level) {
            if ($valueAreaVolume < $targetVolume) {
                $valueAreaVolume += $level['volume'];
                $valueAreaLevels[] = $level['price_level'];
            }
        }

        return [
            'success' => true,
            'data' => [
                'volume_profile' => $volumeByLevel,
                'point_of_control' => $pocLevel,
                'value_area_high' => !empty($valueAreaLevels) ? max($valueAreaLevels) : null,
                'value_area_low' => !empty($valueAreaLevels) ? min($valueAreaLevels) : null,
                'total_volume' => $totalVolume,
                'current_price' => end($quotes)['close_price']
            ],
            'indicator' => 'Volume Profile',
            'parameters' => [
                'price_levels' => $priceLevels,
                'days' => $days
            ]
        ];
    }

    /**
     * Calcule le Chaikin Oscillator
     */
    private function calculateChaikinOscillator($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $fastPeriod = (int)($input['fast_period'] ?? 3);
        $slowPeriod = (int)($input['slow_period'] ?? 10);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $slowPeriod);

        if (count($quotes) < $slowPeriod) {
            throw new Exception("Données insuffisantes");
        }

        // Calculer l'A/D Line
        $adLine = [];
        $cumulativeAD = 0;

        foreach ($quotes as $quote) {
            $high = $quote['high_price'];
            $low = $quote['low_price'];
            $close = $quote['close_price'];
            $volume = $quote['volume'];
            
            if ($high != $low) {
                $clv = (($close - $low) - ($high - $close)) / ($high - $low);
            } else {
                $clv = 0;
            }
            
            $cumulativeAD += $clv * $volume;
            $adLine[] = $cumulativeAD;
        }

        // Calculer EMA rapide et lente de l'A/D Line
        $fastEMA = $this->computeEMAFromArray($adLine, $fastPeriod);
        $slowEMA = $this->computeEMAFromArray($adLine, $slowPeriod);

        $chaikinData = [];
        $startIdx = $slowPeriod - 1;

        for ($i = 0; $i < count($slowEMA); $i++) {
            $oscillator = $fastEMA[$i + ($fastPeriod - 1)] - $slowEMA[$i];
            
            $chaikinData[] = [
                'date' => $quotes[$startIdx + $i]['trading_date'],
                'chaikin_oscillator' => round($oscillator, 2),
                'signal' => $oscillator > 0 ? 'accumulation' : 'distribution'
            ];
        }

        return [
            'success' => true,
            'data' => $chaikinData,
            'indicator' => 'Chaikin Oscillator',
            'parameters' => [
                'fast_period' => $fastPeriod,
                'slow_period' => $slowPeriod
            ]
        ];
    }

    /**
     * Calcule Elder Ray (Bull Power & Bear Power)
     */
    private function calculateElderRay($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 13);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period) {
            throw new Exception("Données insuffisantes");
        }

        // Calculer EMA du prix de clôture
        $closePrices = array_column($quotes, 'close_price');
        $emaValues = $this->computeEMAFromArray($closePrices, $period);

        $elderData = [];

        for ($i = $period - 1; $i < count($quotes); $i++) {
            $high = $quotes[$i]['high_price'];
            $low = $quotes[$i]['low_price'];
            $ema = $emaValues[$i];
            
            // Bull Power = High - EMA
            $bullPower = $high - $ema;
            
            // Bear Power = Low - EMA
            $bearPower = $low - $ema;
            
            $elderData[] = [
                'date' => $quotes[$i]['trading_date'],
                'ema' => round($ema, 2),
                'bull_power' => round($bullPower, 2),
                'bear_power' => round($bearPower, 2),
                'signal' => ($bullPower > 0 && $bearPower > 0) ? 'strong_bullish' : 
                           (($bullPower < 0 && $bearPower < 0) ? 'strong_bearish' : 'mixed')
            ];
        }

        return [
            'success' => true,
            'data' => $elderData,
            'indicator' => 'Elder Ray (Bull/Bear Power)',
            'period' => $period
        ];
    }

    /**
     * Calcule le Mass Index
     */
    private function calculateMassIndex($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $emaPeriod = (int)($input['ema_period'] ?? 9);
        $sumPeriod = (int)($input['sum_period'] ?? 25);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $sumPeriod + ($emaPeriod * 2));

        if (count($quotes) < $sumPeriod + ($emaPeriod * 2)) {
            throw new Exception("Données insuffisantes");
        }

        // Calculer les ranges High-Low
        $ranges = [];
        foreach ($quotes as $quote) {
            $ranges[] = $quote['high_price'] - $quote['low_price'];
        }

        // Première EMA des ranges
        $ema1 = $this->computeEMAFromArray($ranges, $emaPeriod);
        
        // Deuxième EMA (EMA de l'EMA)
        $ema2 = $this->computeEMAFromArray($ema1, $emaPeriod);

        // Calculer EMA Ratio
        $emaRatio = [];
        for ($i = 0; $i < count($ema2); $i++) {
            if ($ema2[$i] != 0) {
                $emaRatio[] = $ema1[$i + ($emaPeriod - 1)] / $ema2[$i];
            } else {
                $emaRatio[] = 1;
            }
        }

        // Calculer le Mass Index (somme des ratios)
        $massData = [];
        $startIdx = ($emaPeriod * 2) + $sumPeriod - 2;

        for ($i = $sumPeriod - 1; $i < count($emaRatio); $i++) {
            $massIndex = array_sum(array_slice($emaRatio, $i - $sumPeriod + 1, $sumPeriod));
            
            $massData[] = [
                'date' => $quotes[$startIdx + ($i - $sumPeriod + 1)]['trading_date'],
                'mass_index' => round($massIndex, 2),
                'signal' => $massIndex > 27 ? 'reversal_warning' : 
                           ($massIndex < 26.5 ? 'range_bound' : 'neutral')
            ];
        }

        return [
            'success' => true,
            'data' => $massData,
            'indicator' => 'Mass Index',
            'parameters' => [
                'ema_period' => $emaPeriod,
                'sum_period' => $sumPeriod
            ],
            'interpretation' => [
                'above_27' => 'Bulge detected - reversal likely',
                'below_26.5' => 'Range-bound market'
            ]
        ];
    }

    /**
     * Calcule le Vortex Indicator
     */
    private function calculateVortex($input) {
        $companyId = (int)($input['company_id'] ?? 0);
        $period = (int)($input['period'] ?? 14);
        $days = (int)($input['days'] ?? 100);
        
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $endDate = $input['end_date'] ?? date('Y-m-d');
        $quotes = $this->getHistoricalQuotes($companyId, $endDate, $days + $period);

        if (count($quotes) < $period + 1) {
            throw new Exception("Données insuffisantes");
        }

        $vortexData = [];

        for ($i = $period; $i < count($quotes); $i++) {
            $plusVM = 0; // Vortex Movement +
            $minusVM = 0; // Vortex Movement -
            $trueRangeSum = 0;
            
            for ($j = 1; $j <= $period; $j++) {
                $idx = $i - $period + $j;
                
                // Vortex Movement +
                $plusVM += abs($quotes[$idx]['high_price'] - $quotes[$idx - 1]['low_price']);
                
                // Vortex Movement -
                $minusVM += abs($quotes[$idx]['low_price'] - $quotes[$idx - 1]['high_price']);
                
                // True Range
                $high = $quotes[$idx]['high_price'];
                $low = $quotes[$idx]['low_price'];
                $prevClose = $quotes[$idx - 1]['close_price'];
                
                $tr = max(
                    $high - $low,
                    abs($high - $prevClose),
                    abs($low - $prevClose)
                );
                
                $trueRangeSum += $tr;
            }
            
            // Calcul des indicateurs VI+ et VI-
            $viPlus = $trueRangeSum > 0 ? $plusVM / $trueRangeSum : 0;
            $viMinus = $trueRangeSum > 0 ? $minusVM / $trueRangeSum : 0;
            
            $vortexData[] = [
                'date' => $quotes[$i]['trading_date'],
                'vi_plus' => round($viPlus, 4),
                'vi_minus' => round($viMinus, 4),
                'signal' => $viPlus > $viMinus ? 'bullish_trend' : 'bearish_trend',
                'trend_strength' => round(abs($viPlus - $viMinus), 4)
            ];
        }

        return [
            'success' => true,
            'data' => $vortexData,
            'indicator' => 'Vortex Indicator',
            'period' => $period,
            'interpretation' => [
                'vi_plus_above_vi_minus' => 'Bullish trend',
                'vi_minus_above_vi_plus' => 'Bearish trend',
                'crossover' => 'Trend reversal signal'
            ]
        ];
    }
}

// Exécution
$api = new TechnicalIndicatorsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);