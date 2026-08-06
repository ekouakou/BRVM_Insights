<?php
/**
 * Utilitaires de calcul sur les rendements — brique commune aux métriques
 * de risque/performance (Sharpe, Sortino, Maximum Drawdown, Calmar,
 * VaR/CVaR historique, skewness/kurtosis, bêta vs indice), plutôt que
 * dupliquée dans chaque endpoint qui en a besoin (voir TODO_ANALYSES.md,
 * point 17 — prérequis pour les points 18 à 22).
 *
 * Convention : les rendements journaliers utilisés ici sont des
 * LOG-rendements (ln(close[i] / close[i-1])), plus adaptés à
 * l'agrégation dans le temps (additifs) que les rendements simples déjà
 * utilisés ailleurs dans l'app pour l'affichage jour-à-jour classique
 * (ex. stock_quotes.variation_percent, utilisé par getCorrelation()/
 * getRelativeStrength() — inchangés, pas concernés par cette classe).
 *
 * Toutes les méthodes sont statiques et sans dépendance base de données :
 * elles prennent des tableaux déjà chargés en entrée.
 */
class ReturnsCalculator {
    /** Nombre de jours de bourse par an, convention standard pour l'annualisation. */
    const TRADING_DAYS_PER_YEAR = 252;

    /**
     * @param float[] $closes Cours de clôture, ordre chronologique croissant
     * @return float[] Log-rendements journaliers (une valeur de moins que $closes)
     */
    public static function dailyLogReturns(array $closes): array {
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            if ($closes[$i - 1] > 0 && $closes[$i] > 0) {
                $returns[] = log($closes[$i] / $closes[$i - 1]);
            }
        }
        return $returns;
    }

    public static function mean(array $values): ?float {
        $n = count($values);
        return $n > 0 ? array_sum($values) / $n : null;
    }

    /** Écart-type non biaisé (division par n-1). */
    public static function stdDev(array $values, ?float $mean = null): ?float {
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $mean = $mean ?? self::mean($values);
        $sumSq = 0.0;
        foreach ($values as $v) {
            $sumSq += ($v - $mean) ** 2;
        }
        return sqrt($sumSq / ($n - 1));
    }

    /**
     * Rendement cumulé annualisé (CAGR, %) entre deux cours, sur une durée
     * calendaire donnée (pas seulement le nombre de jours de bourse — un
     * CAGR se définit sur une durée réelle écoulée).
     */
    public static function cagrPercent(float $firstClose, float $lastClose, int $calendarDays): ?float {
        if ($firstClose <= 0 || $lastClose <= 0 || $calendarDays <= 0) {
            return null;
        }
        $years = $calendarDays / 365;
        if ($years <= 0) {
            return null;
        }
        return (pow($lastClose / $firstClose, 1 / $years) - 1) * 100;
    }

    /** Volatilité annualisée (%) à partir d'une série de rendements journaliers (log). */
    public static function annualizedVolatilityPercent(array $dailyReturns): ?float {
        $sd = self::stdDev($dailyReturns);
        return $sd !== null ? $sd * sqrt(self::TRADING_DAYS_PER_YEAR) * 100 : null;
    }

    /**
     * Rendement moyen annualisé (%) — moyenne des rendements journaliers ×
     * 252. Volontairement basé sur la même série que la volatilité
     * annualisée (cohérence du couple rendement/risque pour Sharpe et
     * Sortino), plutôt que le CAGR (géométrique, calculé différemment).
     */
    public static function annualizedMeanReturnPercent(array $dailyReturns): ?float {
        $mean = self::mean($dailyReturns);
        return $mean !== null ? $mean * self::TRADING_DAYS_PER_YEAR * 100 : null;
    }

    /**
     * Downside deviation annualisée (%) — écart-type des seuls rendements
     * négatifs, par rapport à une cible de rendement nul (convention
     * standard du ratio de Sortino). Null si moins de 2 rendements
     * négatifs dans la période (pas assez pour un écart-type).
     */
    public static function downsideDeviationPercent(array $dailyReturns): ?float {
        $negative = array_values(array_filter($dailyReturns, fn($r) => $r < 0));
        if (count($negative) < 2) {
            return null;
        }
        $sumSq = array_sum(array_map(fn($r) => $r ** 2, $negative));
        $sd = sqrt($sumSq / count($negative));
        return $sd * sqrt(self::TRADING_DAYS_PER_YEAR) * 100;
    }

    /**
     * Maximum drawdown — plus forte perte en % depuis un sommet précédent
     * sur la période, avec les index (dans $closes) du sommet et du creux
     * correspondants pour retrouver leurs dates côté appelant.
     *
     * @param float[] $closes Cours de clôture, ordre chronologique croissant
     * @return array{drawdown_percent: float, peak_index: int, trough_index: int}|null
     */
    public static function maxDrawdown(array $closes): ?array {
        $n = count($closes);
        if ($n < 2) {
            return null;
        }

        $peak = $closes[0];
        $peakIndex = 0;
        $maxDrawdown = 0.0;
        $bestPeakIndex = 0;
        $bestTroughIndex = 0;

        for ($i = 1; $i < $n; $i++) {
            if ($closes[$i] > $peak) {
                $peak = $closes[$i];
                $peakIndex = $i;
            }
            $drawdown = ($closes[$i] - $peak) / $peak; // toujours <= 0
            if ($drawdown < $maxDrawdown) {
                $maxDrawdown = $drawdown;
                $bestPeakIndex = $peakIndex;
                $bestTroughIndex = $i;
            }
        }

        return [
            'drawdown_percent' => $maxDrawdown * 100,
            'peak_index' => $bestPeakIndex,
            'trough_index' => $bestTroughIndex,
        ];
    }

    /**
     * Value at Risk historique (%) — approche par percentile empirique
     * (pas de Monte-Carlo ni d'hypothèse de distribution normale, plus
     * honnête sur un marché frontière comme la BRVM dont les rendements
     * peuvent être fortement asymétriques, voir skewness()). Retourne une
     * valeur négative ou nulle : ex. -3.2 = perte d'au moins 3.2% observée
     * dans les (1-$confidence) pires jours de la période.
     */
    public static function historicalVaRPercent(array $dailyReturns, float $confidence = 0.95): ?float {
        $n = count($dailyReturns);
        if ($n < 10) {
            return null; // pas assez de points pour un percentile fiable
        }
        $sorted = $dailyReturns;
        sort($sorted);
        $index = (int) floor((1 - $confidence) * $n);
        $index = max(0, min($n - 1, $index));
        return $sorted[$index] * 100;
    }

    /**
     * CVaR / Expected Shortfall (%) — moyenne des rendements dans la queue
     * au-delà du seuil VaR (pire que VaR en moyenne, pas seulement au
     * seuil).
     */
    public static function historicalCVaRPercent(array $dailyReturns, float $confidence = 0.95): ?float {
        $n = count($dailyReturns);
        if ($n < 10) {
            return null;
        }
        $sorted = $dailyReturns;
        sort($sorted);
        $cutoff = (int) floor((1 - $confidence) * $n);
        $cutoff = max(1, min($n, $cutoff + 1));
        $tail = array_slice($sorted, 0, $cutoff);
        $mean = self::mean($tail);
        return $mean !== null ? $mean * 100 : null;
    }

    /** Skewness (asymétrie) — moment d'ordre 3 normalisé, formule non biaisée. */
    public static function skewness(array $values): ?float {
        $n = count($values);
        if ($n < 3) {
            return null;
        }
        $mean = self::mean($values);
        $sd = self::stdDev($values, $mean);
        if (!$sd) {
            return null;
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += (($v - $mean) / $sd) ** 3;
        }
        return ($n / (($n - 1) * ($n - 2))) * $sum;
    }

    /**
     * Excès de kurtosis (0 = queues d'une loi normale, >0 = queues plus
     * épaisses donc risque d'événements extrêmes sous-estimé par un
     * Sharpe/écart-type classique) — formule non biaisée.
     */
    public static function excessKurtosis(array $values): ?float {
        $n = count($values);
        if ($n < 4) {
            return null;
        }
        $mean = self::mean($values);
        $sd = self::stdDev($values, $mean);
        if (!$sd) {
            return null;
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += (($v - $mean) / $sd) ** 4;
        }
        $numerator = ($n * ($n + 1)) / (($n - 1) * ($n - 2) * ($n - 3));
        $adjustment = (3 * (($n - 1) ** 2)) / (($n - 2) * ($n - 3));
        return $numerator * $sum - $adjustment;
    }

    /** Covariance entre deux séries alignées (même longueur, même ordre = mêmes jours). */
    public static function covariance(array $a, array $b): ?float {
        $n = count($a);
        if ($n < 2 || count($b) !== $n) {
            return null;
        }
        $meanA = self::mean($a);
        $meanB = self::mean($b);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += ($a[$i] - $meanA) * ($b[$i] - $meanB);
        }
        return $sum / ($n - 1);
    }

    /**
     * Bêta = covariance(rendements titre, rendements indice) /
     * variance(rendements indice), sur des séries déjà alignées sur les
     * mêmes jours par l'appelant (voir api_risk_metrics.php).
     */
    public static function beta(array $assetReturns, array $indexReturns): ?float {
        $cov = self::covariance($assetReturns, $indexReturns);
        $sdIndex = self::stdDev($indexReturns);
        if ($cov === null || $sdIndex === null || $sdIndex == 0) {
            return null;
        }
        return $cov / ($sdIndex ** 2);
    }
}
