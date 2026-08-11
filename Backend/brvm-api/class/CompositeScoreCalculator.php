<?php
/**
 * Moteur de calcul du score composite 0-100 par entreprise — brique
 * PARTAGÉE (contrairement à la convention habituelle de duplication des
 * petites formules dans ce projet, voir api_composite_score.php) car ce
 * calcul est substantiel (~300 lignes, 6 sous-scores pondérés) et doit
 * rester identique entre `api_composite_score.php` et la fonctionnalité
 * "Mon Équipe BRVM" (voir TODO_PORTFOLIO_TEAM.md) qui en a besoin une
 * seconde fois : une duplication ici risquerait un dérapage de formule
 * entre les deux, contrairement aux petites formules dupliquées ailleurs.
 *
 * Toutes les méthodes sont statiques et sans dépendance base de données :
 * elles prennent en entrée des données déjà chargées par l'appelant (voir
 * api_composite_score.php pour les requêtes SQL correspondantes).
 */
class CompositeScoreCalculator {
    // Pondérations demandées par l'utilisateur (somme = 100).
    public const WEIGHTS = [
        'fundamental' => 30,
        'technical' => 25,
        'momentum' => 15,
        'liquidity' => 10,
        'sector' => 10,
        'market' => 10,
    ];

    public static function clamp(float $value): float {
        return max(0, min(100, $value));
    }

    /**
     * Sous-score Fondamental (0-100) : moyenne des composantes disponibles
     * parmi PER, rendement du dividende, ROE, croissance du CA, marge nette
     * — chacune normalisée par un barème simple documenté ci-dessous.
     * Barèmes volontairement simples (pas de comparaison au secteur/marché,
     * pas de calibrage statistique) — à affiner si besoin, mais transparent
     * et déterministe plutôt qu'une boîte noire.
     */
    public static function fundamentalSubScore(?array $f): ?float {
        if ($f === null) return null;

        $components = [];

        // PER : récompense un PER modéré (proche de 8), pénalise un PER élevé. Pas de récompense pour un PER très bas (souvent signe de détresse plutôt que d'opportunité).
        if ($f['pe_ratio'] !== null && $f['pe_ratio'] > 0) {
            $components[] = self::clamp(100 - max(0, $f['pe_ratio'] - 8) * 4);
        }
        // Rendement du dividende : 8%+ = score maximal.
        if ($f['dividend_yield_percent'] !== null) {
            $components[] = self::clamp($f['dividend_yield_percent'] / 8 * 100);
        }
        // ROE : 25%+ = score maximal.
        if ($f['roe_percent'] !== null) {
            $components[] = self::clamp($f['roe_percent'] / 25 * 100);
        }
        // Croissance du CA : -10% = 0, +20% = 100.
        if ($f['revenue_growth_percent'] !== null) {
            $components[] = self::clamp(($f['revenue_growth_percent'] + 10) / 30 * 100);
        }
        // Marge nette : 20%+ = score maximal.
        if ($f['net_margin_percent'] !== null) {
            $components[] = self::clamp($f['net_margin_percent'] / 20 * 100);
        }

        if (empty($components)) return null;
        return round(array_sum($components) / count($components), 1);
    }

    // Pas de `match` ici : le serveur Apache de MAMP exécute PHP 7.4 (le CLI
    // est en 8.2, d'où un piège silencieux — un parse error PHP 8 ne se voit
    // qu'à la requête HTTP réelle, jamais en php -l 8.2 ni en test CLI).
    public static function liquiditySubScore(?string $liquidity): ?float {
        $map = ['Élevée' => 100.0, 'Moyenne' => 70.0, 'Faible' => 40.0, 'Illiquide' => 10.0];
        return $liquidity !== null && isset($map[$liquidity]) ? $map[$liquidity] : null;
    }

    /**
     * Construit le score composite technique (-2 à +2) — copie de
     * api_signals.php::buildSignal() (voir note en tête d'api_composite_score.php).
     */
    public static function buildSignal(array $row, ?string $liquidity = null): array {
        $sum = 0;
        $count = 0;
        $signals = [];

        if ($row['rsi_14'] !== null) {
            $rsi = (float) $row['rsi_14'];
            $signals[] = $rsi < 30 ? 1 : ($rsi > 70 ? -1 : 0);
        }
        if ($row['macd_line'] !== null && $row['macd_signal'] !== null) {
            $macdLine = (float) $row['macd_line'];
            $macdSignal = (float) $row['macd_signal'];
            $signals[] = $macdLine > $macdSignal ? 1 : ($macdLine < $macdSignal ? -1 : 0);
        }
        $sma = $row['sma_20'] !== null ? $row['sma_20'] : $row['sma_10'];
        if ($sma !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            $smaValue = (float) $sma;
            $signals[] = $close > $smaValue ? 1 : ($close < $smaValue ? -1 : 0);
        }
        if ($row['bb_upper'] !== null && $row['bb_lower'] !== null && $row['close_price'] !== null) {
            $close = (float) $row['close_price'];
            if ($close < (float) $row['bb_lower']) {
                $signals[] = 1;
            } elseif ($close > (float) $row['bb_upper']) {
                $signals[] = -1;
            } else {
                $signals[] = 0;
            }
        }

        foreach ($signals as $s) {
            $sum += $s;
            $count++;
        }

        $score = null;
        $label = 'Indéterminé';

        if ($count > 0) {
            $score = (int) round(($sum / $count) * 2);
            $score = max(-2, min(2, $score));

            if ($liquidity === 'Illiquide' && abs($score) === 2) {
                $score = $score > 0 ? 1 : -1;
            }

            $labels = [2 => 'Achat fort', 1 => 'Achat', 0 => 'Neutre', -1 => 'Vente', -2 => 'Vente forte'];
            $label = $labels[$score] ?? 'Indéterminé';
        }

        return ['score' => $score, 'label' => $label];
    }

    /**
     * Assemble les 6 sous-scores (0-100 ou null si donnée indisponible)
     * à partir des briques déjà chargées par l'appelant — même arithmétique
     * que l'ancien CompositeScoreAPI::compute() (voir historique git).
     */
    public static function computeSubScores(
        array $row,
        ?array $fundamentals,
        ?string $liquidity,
        ?float $periodPerformance,
        ?int $sectorRank,
        ?int $sectorSize,
        ?float $benchmarkReturn
    ): array {
        $signal = self::buildSignal($row, $liquidity);

        return [
            'fundamental' => self::fundamentalSubScore($fundamentals),
            'technical' => $signal['score'] !== null ? self::clamp(($signal['score'] + 2) / 4 * 100) : null,
            'momentum' => $periodPerformance !== null ? self::clamp(($periodPerformance + 30) / 60 * 100) : null,
            'liquidity' => self::liquiditySubScore($liquidity),
            'sector' => ($sectorRank !== null && $sectorSize) ? self::clamp((($sectorSize - $sectorRank + 1) / $sectorSize) * 100) : null,
            'market' => ($periodPerformance !== null && $benchmarkReturn !== null)
                ? self::clamp((($periodPerformance - $benchmarkReturn) + 15) / 30 * 100)
                : null,
        ];
    }

    /**
     * Score composite = somme pondérée des sous-scores disponibles,
     * renormalisée sur le seul poids couvert (voir note en tête
     * d'api_composite_score.php sur la couverture partielle).
     *
     * @return array{composite_score: ?float, coverage_percent: int}
     */
    public static function weightedScore(array $subScores): array {
        $weightedSum = 0;
        $coveredWeight = 0;
        foreach ($subScores as $key => $value) {
            if ($value !== null) {
                $weightedSum += $value * self::WEIGHTS[$key];
                $coveredWeight += self::WEIGHTS[$key];
            }
        }
        return [
            'composite_score' => $coveredWeight > 0 ? round($weightedSum / $coveredWeight, 1) : null,
            'coverage_percent' => $coveredWeight,
        ];
    }
}
