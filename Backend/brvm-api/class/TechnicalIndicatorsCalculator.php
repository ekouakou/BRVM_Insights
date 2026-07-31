<?php
/**
 * Calcule les indicateurs techniques quotidiens et les persiste dans la table
 * `technical_indicators`, pour éviter de tout recalculer à la volée à chaque
 * appel API et permettre l'historisation / le backtesting.
 *
 * Les formules reprennent celles déjà utilisées par api_technical_indicators.php
 * (SMA/EMA/RSI/MACD/Bollinger/ATR) pour rester cohérent avec le calcul à la volée.
 */
class TechnicalIndicatorsCalculator {
    private $crud;

    /** Nombre max de cotations historiques chargées (suffisant pour SMA200) */
    private const MAX_HISTORY = 260;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * Calcule et persiste les indicateurs pour une entreprise à une date donnée.
     *
     * @return bool true si les indicateurs ont été calculés (même partiellement)
     */
    public function computeAndPersist($companyId, $tradingDate) {
        $quotes = $this->getHistoricalQuotes($companyId, $tradingDate, self::MAX_HISTORY);

        if (count($quotes) < 2) {
            return false; // Pas assez d'historique pour calculer quoi que ce soit d'utile
        }

        $closes = array_column($quotes, 'close_price');
        $closes = array_map('floatval', $closes);

        $bollinger = $this->computeBollinger($closes, 20, 2);
        $macd = $this->computeMACD($closes, 12, 26, 9);

        $indicators = [
            'company_id' => $companyId,
            'trading_date' => $tradingDate,
            'sma_10' => $this->computeSMA($closes, 10),
            'sma_20' => $this->computeSMA($closes, 20),
            'sma_50' => $this->computeSMA($closes, 50),
            'sma_200' => $this->computeSMA($closes, 200),
            'ema_10' => $this->computeEMA($closes, 10),
            'ema_20' => $this->computeEMA($closes, 20),
            'rsi_14' => $this->computeRSI($closes, 14),
            'macd_line' => $macd['line'],
            'macd_signal' => $macd['signal'],
            'macd_histogram' => $macd['histogram'],
            'bb_upper' => $bollinger['upper'],
            'bb_middle' => $bollinger['middle'],
            'bb_lower' => $bollinger['lower'],
            'atr_14' => $this->computeATR($quotes, 14),
        ];

        // Retirer les valeurs null pour ne pas écraser d'anciennes valeurs avec du vide
        // lors d'un merge, mais les garder pour un premier insert (colonnes nullable).
        $existing = $this->crud->find('technical_indicators', [
            'company_id' => $companyId,
            'trading_date' => $tradingDate
        ]);

        if (!empty($existing)) {
            $this->crud->merge('technical_indicators', $indicators, [
                'company_id' => $companyId,
                'trading_date' => $tradingDate
            ]);
        } else {
            $this->crud->persist('technical_indicators', $indicators);
        }

        return true;
    }

    /**
     * Récupère l'historique de cotations (ordre chronologique croissant)
     */
    private function getHistoricalQuotes($companyId, $endDate, $limit) {
        $sql = "
            SELECT trading_date, close_price, high_price, low_price
            FROM stock_quotes
            WHERE company_id = ?
            AND trading_date <= ?
            ORDER BY trading_date DESC
            LIMIT ?
        ";

        $quotes = $this->crud->executeCustomQuery($sql, [$companyId, $endDate, $limit]);

        return array_reverse($quotes ?: []);
    }

    private function computeSMA(array $closes, $period) {
        if (count($closes) < $period) {
            return null;
        }
        $slice = array_slice($closes, -$period);
        return round(array_sum($slice) / $period, 4);
    }

    /**
     * EMA finale de la série (seedée par la SMA des $period premières valeurs,
     * comme dans api_technical_indicators.php::calculateEMA)
     */
    private function computeEMA(array $closes, $period) {
        if (count($closes) < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] - $ema) * $multiplier + $ema;
        }

        return round($ema, 4);
    }

    /**
     * RSI final (moyenne simple des gains/pertes sur les $period derniers écarts,
     * même convention que api_technical_indicators.php::calculateRSI)
     */
    private function computeRSI(array $closes, $period = 14) {
        if (count($closes) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];
        for ($i = 1; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = $change > 0 ? $change : 0;
            $losses[] = $change < 0 ? abs($change) : 0;
        }

        $avgGain = array_sum(array_slice($gains, -$period)) / $period;
        $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        return round(100 - (100 / (1 + $rs)), 4);
    }

    /**
     * Série complète d'EMA (une valeur par point à partir de l'index $period-1)
     */
    private function computeEMASeries(array $closes, $period) {
        if (count($closes) < $period) {
            return [];
        }

        $multiplier = 2 / ($period + 1);
        $series = [];
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;
        $series[] = $ema;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] - $ema) * $multiplier + $ema;
            $series[] = $ema;
        }

        return $series; // aligné sur les index [period-1 .. count($closes)-1] de $closes
    }

    private function computeMACD(array $closes, $fastPeriod = 12, $slowPeriod = 26, $signalPeriod = 9) {
        $result = ['line' => null, 'signal' => null, 'histogram' => null];

        if (count($closes) < $slowPeriod) {
            return $result;
        }

        $fastSeries = $this->computeEMASeries($closes, $fastPeriod);
        $slowSeries = $this->computeEMASeries($closes, $slowPeriod);

        // Aligner les deux séries sur la fin (slowSeries commence plus tard)
        $offset = count($fastSeries) - count($slowSeries);
        $macdLine = [];
        for ($i = 0; $i < count($slowSeries); $i++) {
            $macdLine[] = $fastSeries[$i + $offset] - $slowSeries[$i];
        }

        if (count($macdLine) < $signalPeriod) {
            $result['line'] = round(end($macdLine), 4);
            return $result;
        }

        $signalSeries = $this->computeEMASeries($macdLine, $signalPeriod);

        $line = end($macdLine);
        $signal = end($signalSeries);

        $result['line'] = round($line, 4);
        $result['signal'] = round($signal, 4);
        $result['histogram'] = round($line - $signal, 4);

        return $result;
    }

    private function computeBollinger(array $closes, $period = 20, $stdDevMultiplier = 2) {
        $result = ['upper' => null, 'middle' => null, 'lower' => null];

        if (count($closes) < $period) {
            return $result;
        }

        $slice = array_slice($closes, -$period);
        $middle = array_sum($slice) / $period;

        $variance = 0;
        foreach ($slice as $price) {
            $variance += ($price - $middle) ** 2;
        }
        $stdDev = sqrt($variance / $period);

        $result['middle'] = round($middle, 4);
        $result['upper'] = round($middle + ($stdDevMultiplier * $stdDev), 4);
        $result['lower'] = round($middle - ($stdDevMultiplier * $stdDev), 4);

        return $result;
    }

    /**
     * ATR final (moyenne de Wilder), à partir des cotations (high/low/close)
     */
    private function computeATR(array $quotes, $period = 14) {
        if (count($quotes) < $period + 1) {
            return null;
        }

        $trueRanges = [];
        for ($i = 1; $i < count($quotes); $i++) {
            $high = (float) $quotes[$i]['high_price'];
            $low = (float) $quotes[$i]['low_price'];
            $prevClose = (float) $quotes[$i - 1]['close_price'];

            $trueRanges[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        if (count($trueRanges) < $period) {
            return null;
        }

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
        }

        return round($atr, 4);
    }
}
