<?php
/**
 * Construit intraday_execution_flow depuis intraday_quotes : le volume
 * affiché par brvm.org étant CUMULATIF sur la séance, la différence entre
 * deux relevés consécutifs = actions réellement échangées dans l'intervalle
 * (donnée 🟨 calculée depuis des observés — voir TODO_CARNET_ORDRES.md §2).
 *
 * Pièges neutralisés (tous constatés sur données réelles) :
 *  1. Avant ~09h10, la page affiche ENCORE la séance de la VEILLE (ex. réel :
 *     cumul 5 155 à 08h30 puis 0 à 09h10). La séance commence au premier
 *     relevé où le cumul RETOMBE (reset) ; tout relevé antérieur est ignoré.
 *     S'il n'y a aucun reset (collecte démarrée après l'ouverture), on prend
 *     le premier relevé comme base — les volumes d'avant sont inconnus.
 *  2. Delta négatif EN séance (correction du site) : intervalle ignoré,
 *     jamais un volume négatif ; le relevé fautif ne sert pas de base au
 *     delta suivant (on garde le dernier cumul fiable).
 *  3. Le cumul du dernier relevé peut être INFÉRIEUR au volume officiel du
 *     jour (fixing de clôture après le dernier passage du scraper) : l'écart
 *     devient un intervalle synthétique is_closing_auction=1 au prix de
 *     clôture officiel.
 *
 * pressure_side est une ESTIMATION (tick rule : prix qui monte pendant
 * l'intervalle = initiative acheteuse probable, prix qui baisse = vendeuse) —
 * NULL quand le prix est inchangé ou le volume nul.
 *
 * Seuls les intervalles à volume > 0 (et l'intervalle de clôture) sont
 * persistés : l'absence de ligne = aucun échange dans l'intervalle.
 */
class ExecutionFlowBuilder {
    private $crud;

    public function __construct(DynamiqueCrud $crud) {
        $this->crud = $crud;
    }

    /**
     * (Re)construit la séance d'une entreprise. Remplace les intervalles
     * existants du jour (rejouable sans doublon).
     *
     * @return array{company_id:int, trading_date:string, intervals:int, executed_total:int, official_volume:?int, closing_gap:int}
     */
    public function buildDay(int $companyId, string $tradingDate, bool $persist = true): array {
        $quotes = $this->crud->executeCustomQuery(
            'SELECT quote_datetime, price, volume FROM intraday_quotes
             WHERE company_id = :cid AND DATE(quote_datetime) = :d
             ORDER BY quote_datetime',
            ['cid' => $companyId, 'd' => $tradingDate]
        ) ?: [];

        $intervals = $this->computeIntervals($quotes);

        // Piège 3 : écart avec le volume officiel du jour (fixing de clôture).
        $official = $this->crud->executeCustomQuery(
            'SELECT volume, close_price FROM stock_quotes WHERE company_id = :cid AND trading_date = :d',
            ['cid' => $companyId, 'd' => $tradingDate]
        );
        $officialVolume = !empty($official) ? (int) $official[0]['volume'] : null;
        $executedTotal = 0;
        foreach ($intervals as $iv) {
            $executedTotal += $iv['executed_volume'];
        }
        $closingGap = 0;
        if ($officialVolume !== null && $officialVolume > $executedTotal && !empty($quotes)) {
            $closingGap = $officialVolume - $executedTotal;
            $last = $quotes[count($quotes) - 1];
            $closePrice = $official[0]['close_price'] !== null ? (float) $official[0]['close_price'] : (float) $last['price'];
            // Fin conventionnelle 14:45, sauf si le dernier relevé est plus
            // tardif (scrape post-clôture) — l'intervalle ne remonte jamais le temps.
            $closingEnd = max($tradingDate . ' 14:45:00', date('Y-m-d H:i:s', strtotime($last['quote_datetime']) + 60));
            $intervals[] = [
                'interval_start' => $last['quote_datetime'],
                'interval_end' => $closingEnd,
                'price_start' => (float) $last['price'],
                'price_end' => $closePrice,
                'executed_volume' => $closingGap,
                'executed_value' => round($closingGap * $closePrice, 2),
                'price_direction' => $closePrice <=> (float) $last['price'],
                'pressure_side' => null, // fixing : pas de tick rule fiable
                'is_closing_auction' => 1,
            ];
        }

        if ($persist) {
            $this->crud->remove('intraday_execution_flow', ['company_id' => $companyId, 'trading_date' => $tradingDate]);
            foreach ($intervals as $iv) {
                $iv['company_id'] = $companyId;
                $iv['trading_date'] = $tradingDate;
                $this->crud->persist('intraday_execution_flow', $iv);
            }
        }

        return [
            'company_id' => $companyId,
            'trading_date' => $tradingDate,
            'intervals' => count($intervals),
            'executed_total' => $executedTotal + $closingGap,
            'official_volume' => $officialVolume,
            'closing_gap' => $closingGap,
        ];
    }

    /**
     * Construit toutes les séances closes pas encore consolidées (utilisé par
     * le hook de fin de séance et le backfill). Une séance est "à faire" si
     * intraday_quotes a des relevés ce jour-là mais intraday_execution_flow
     * aucune ligne — les jours sans aucun échange restent donc re-scannés,
     * c'est voulu (recalcul à vide, quasi gratuit) tant que le volume
     * officiel n'apporte pas d'intervalle de clôture.
     */
    public function buildPending(?string $onlyDate = null): array {
        $params = [];
        $dateFilter = '';
        if ($onlyDate !== null) {
            $dateFilter = 'AND DATE(q.quote_datetime) = :d';
            $params['d'] = $onlyDate;
        }
        $pairs = $this->crud->executeCustomQuery(
            "SELECT DISTINCT q.company_id, DATE(q.quote_datetime) AS trading_date
             FROM intraday_quotes q
             LEFT JOIN intraday_execution_flow f
               ON f.company_id = q.company_id AND f.trading_date = DATE(q.quote_datetime)
             WHERE f.id IS NULL $dateFilter
               AND (DATE(q.quote_datetime) < CURDATE() OR CURTIME() >= '14:45:00')
             ORDER BY trading_date, q.company_id",
            $params
        ) ?: [];

        $results = [];
        foreach ($pairs as $p) {
            $results[] = $this->buildDay((int) $p['company_id'], $p['trading_date']);
        }
        return $results;
    }

    /**
     * Cœur du calcul, sans effet de bord — utilisé aussi par l'API pour la
     * séance EN COURS (non persistée tant qu'elle n'est pas close).
     *
     * @param array<array{quote_datetime:string, price:string|float, volume:string|int}> $quotes ordonnés par datetime
     */
    public function computeIntervals(array $quotes): array {
        if (count($quotes) < 2) {
            return [];
        }

        // Piège 1 : début de séance = dernier reset du cumul (le cumul de la
        // veille reste affiché jusqu'à ~09h10). Sans reset, base = premier relevé.
        $startIdx = 0;
        for ($i = 1; $i < count($quotes); $i++) {
            $prev = (int) $quotes[$i - 1]['volume'];
            $cur = (int) $quotes[$i]['volume'];
            // Un reset est une chute franche vers ~0 en tout début de journée,
            // pas une petite correction : on ne le cherche que tant que le
            // cumul n'a pas commencé à croître depuis la base courante.
            if ($cur < $prev) {
                $startIdx = $i;
            }
            if ($cur > (int) $quotes[$startIdx]['volume']) {
                break; // la séance a commencé à cumuler : plus de reset attendu
            }
        }

        $intervals = [];
        $baseVolume = (int) $quotes[$startIdx]['volume'];
        $basePrice = (float) $quotes[$startIdx]['price'];
        $baseDatetime = $quotes[$startIdx]['quote_datetime'];

        for ($i = $startIdx + 1; $i < count($quotes); $i++) {
            $cur = (int) $quotes[$i]['volume'];
            $curPrice = (float) $quotes[$i]['price'];
            $delta = $cur - $baseVolume;
            if ($delta < 0) {
                continue; // piège 2 : correction du site — on garde la base fiable
            }
            if ($delta > 0) {
                $direction = $curPrice <=> $basePrice;
                $avgPrice = ($curPrice + $basePrice) / 2;
                $intervals[] = [
                    'interval_start' => $baseDatetime,
                    'interval_end' => $quotes[$i]['quote_datetime'],
                    'price_start' => $basePrice,
                    'price_end' => $curPrice,
                    'executed_volume' => $delta,
                    'executed_value' => round($delta * $avgPrice, 2),
                    'price_direction' => $direction,
                    'pressure_side' => $direction > 0 ? 'achat' : ($direction < 0 ? 'vente' : null),
                    'is_closing_auction' => 0,
                ];
            }
            $baseVolume = $cur;
            $basePrice = $curPrice;
            $baseDatetime = $quotes[$i]['quote_datetime'];
        }

        return $intervals;
    }
}
