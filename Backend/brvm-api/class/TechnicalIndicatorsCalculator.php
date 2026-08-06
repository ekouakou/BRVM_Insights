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
        $stochastic = $this->computeStochastic($quotes, 14, 3);

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
            'adx_14' => $this->computeADX($quotes, 14),
            'stoch_k' => $stochastic['k'],
            'stoch_d' => $stochastic['d'],
            'roc_12' => $this->computeROC($closes, 12),
            'obv' => $this->computeOBV($companyId, $quotes),
            'vwap' => $this->computeVWAP($companyId, $tradingDate),
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
            SELECT trading_date, close_price, high_price, low_price, volume
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

    /**
     * ADX final (force de tendance, 0-100, lissage de Wilder sur +DM/-DM/TR)
     * — même formule que api_technical_indicators.php::calculateADX(), mais
     * on ne garde que la dernière valeur de la série plutôt que l'historique
     * complet (voir TODO_ANALYSES.md, point 13).
     */
    private function computeADX(array $quotes, $period = 14) {
        $count = count($quotes);
        if ($count < $period * 2 + 1) {
            return null;
        }

        $plusDM = [];
        $minusDM = [];
        $trueRanges = [];
        for ($i = 1; $i < $count; $i++) {
            $high = (float) $quotes[$i]['high_price'];
            $low = (float) $quotes[$i]['low_price'];
            $prevHigh = (float) $quotes[$i - 1]['high_price'];
            $prevLow = (float) $quotes[$i - 1]['low_price'];
            $prevClose = (float) $quotes[$i - 1]['close_price'];

            $upMove = $high - $prevHigh;
            $downMove = $prevLow - $low;
            $plusDM[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0;
            $minusDM[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0;
            $trueRanges[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
        }

        if (count($plusDM) < $period * 2) {
            return null;
        }

        $smoothedPlusDM = array_sum(array_slice($plusDM, 0, $period));
        $smoothedMinusDM = array_sum(array_slice($minusDM, 0, $period));
        $smoothedTR = array_sum(array_slice($trueRanges, 0, $period));

        $dxValues = [];
        for ($i = $period; $i < count($plusDM); $i++) {
            $smoothedPlusDM = $smoothedPlusDM - ($smoothedPlusDM / $period) + $plusDM[$i];
            $smoothedMinusDM = $smoothedMinusDM - ($smoothedMinusDM / $period) + $minusDM[$i];
            $smoothedTR = $smoothedTR - ($smoothedTR / $period) + $trueRanges[$i];

            $plusDI = $smoothedTR != 0 ? ($smoothedPlusDM / $smoothedTR) * 100 : 0;
            $minusDI = $smoothedTR != 0 ? ($smoothedMinusDM / $smoothedTR) * 100 : 0;
            $diSum = $plusDI + $minusDI;
            $dxValues[] = $diSum != 0 ? (abs($plusDI - $minusDI) / $diSum) * 100 : 0;
        }

        if (count($dxValues) < $period) {
            return null;
        }

        $adx = array_sum(array_slice($dxValues, -$period)) / $period;
        return round($adx, 4);
    }

    /**
     * Oscillateur stochastique final (%K sur $periodK jours, %D = moyenne
     * mobile $periodD jours de %K) — même formule que
     * api_technical_indicators.php::calculateStochastic().
     */
    private function computeStochastic(array $quotes, $periodK = 14, $periodD = 3) {
        $result = ['k' => null, 'd' => null];
        $count = count($quotes);
        if ($count < $periodK) {
            return $result;
        }

        $kValues = [];
        for ($i = $periodK - 1; $i < $count; $i++) {
            $highestHigh = -INF;
            $lowestLow = INF;
            for ($j = 0; $j < $periodK; $j++) {
                $high = (float) $quotes[$i - $j]['high_price'];
                $low = (float) $quotes[$i - $j]['low_price'];
                if ($high > $highestHigh) $highestHigh = $high;
                if ($low < $lowestLow) $lowestLow = $low;
            }
            $close = (float) $quotes[$i]['close_price'];
            $range = $highestHigh - $lowestLow;
            $kValues[] = $range != 0 ? (($close - $lowestLow) / $range) * 100 : 50;
        }

        $result['k'] = round(end($kValues), 4);

        if (count($kValues) >= $periodD) {
            $result['d'] = round(array_sum(array_slice($kValues, -$periodD)) / $periodD, 4);
        }

        return $result;
    }

    /**
     * Rate of Change final (%) — cours actuel vs cours il y a $period jours,
     * même formule que api_technical_indicators.php::calculateROC().
     */
    private function computeROC(array $closes, $period = 12) {
        $count = count($closes);
        if ($count < $period + 1) {
            return null;
        }

        $current = $closes[$count - 1];
        $past = $closes[$count - 1 - $period];
        if ($past == 0) {
            return null;
        }

        return round((($current - $past) / $past) * 100, 4);
    }

    /**
     * On-Balance Volume — cumul incrémental (aujourd'hui = hier + volume
     * signé du jour) plutôt qu'un recalcul depuis le début de la fenêtre
     * d'historique à chaque appel : la valeur ABSOLUE de l'OBV est
     * arbitraire (dépend du point de départ), seule sa tendance a un sens,
     * donc autant garder une continuité jour après jour plutôt que de la
     * faire sauter à chaque recalcul sur une fenêtre glissante différente.
     * Amorcée au volume du jour si aucune valeur précédente n'existe encore
     * pour cette entreprise (premier jour calculé).
     */
    private function computeOBV($companyId, array $quotes) {
        $count = count($quotes);
        if ($count < 1) {
            return null;
        }

        $today = $quotes[$count - 1];
        $todayVolume = (float) ($today['volume'] ?? 0);

        if ($count < 2) {
            return round($todayVolume, 4);
        }

        $yesterday = $quotes[$count - 2];
        $previous = $this->crud->find('technical_indicators', [
            'company_id' => $companyId,
            'trading_date' => $yesterday['trading_date'],
        ]);
        $previousObv = (!empty($previous) && $previous[0]['obv'] !== null)
            ? (float) $previous[0]['obv']
            : null;

        if ($previousObv === null) {
            // Pas de valeur persistée la veille (premier calcul pour cette
            // entreprise, ou trou de synchro) : on repart du volume du jour
            // comme nouveau point de départ, comme le ferait un OBV "day 0".
            return round($todayVolume, 4);
        }

        $todayClose = (float) $today['close_price'];
        $yesterdayClose = (float) $yesterday['close_price'];

        if ($todayClose > $yesterdayClose) {
            return round($previousObv + $todayVolume, 4);
        }
        if ($todayClose < $yesterdayClose) {
            return round($previousObv - $todayVolume, 4);
        }
        return round($previousObv, 4);
    }

    /**
     * VWAP du jour — calculé à partir des relevés intrajournaliers
     * (intraday_quotes) de CETTE journée uniquement, donc réinitialisé
     * chaque jour par construction (contrairement à l'implémentation ad-hoc
     * de api_technical_indicators.php, qui cumule sur toute la fenêtre
     * demandée sans jamais repartir de zéro — bug connu corrigé ici).
     * Retourne null si aucun relevé intrajournalier n'existe pour ce jour
     * (ex. avant que le cron intrajournalier n'ait tourné).
     */
    private function computeVWAP($companyId, $tradingDate) {
        $sql = "
            SELECT price, volume
            FROM intraday_quotes
            WHERE company_id = ?
            AND DATE(quote_datetime) = ?
            ORDER BY quote_datetime ASC
        ";
        $ticks = $this->crud->executeCustomQuery($sql, [$companyId, $tradingDate]) ?: [];

        if (empty($ticks)) {
            return null;
        }

        $cumulativePV = 0.0;
        $cumulativeVolume = 0.0;
        foreach ($ticks as $tick) {
            $price = (float) $tick['price'];
            $volume = (float) $tick['volume'];
            $cumulativePV += $price * $volume;
            $cumulativeVolume += $volume;
        }

        if ($cumulativeVolume <= 0) {
            return null;
        }

        return round($cumulativePV / $cumulativeVolume, 4);
    }
}
