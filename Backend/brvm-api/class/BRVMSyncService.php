<?php
/**
 * Service de synchronisation BRVM (cotations, entreprises, indices).
 * Mutualise la logique utilisée par cron_sync_brvm.php et api_brvm_sync.php.
 */
class BRVMSyncService {
    private $crud;
    private $scraper;

    public function __construct(DynamiqueCrud $crud, BRVMScraperFixed $scraper) {
        $this->crud = $crud;
        $this->scraper = $scraper;
    }

    /**
     * Synchronise les cotations + entreprises depuis le scraper d'actions
     */
    public function syncQuotes() {
        $scrapedData = $this->scraper->scrapeData();

        if (!$scrapedData || empty($scrapedData)) {
            throw new Exception("Aucune donnée récupérée du site BRVM (actions)");
        }

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        $today = date('Y-m-d');
        $touchedCompanyIds = [];

        foreach ($scrapedData as $row) {
            $stats['processed']++;

            try {
                $companyId = $this->ensureCompanyExists($row);

                if (!$companyId) {
                    $stats['failed']++;
                    $stats['errors'][] = "Impossible de créer/trouver l'entreprise: " . ($row['Symbole'] ?? 'UNKNOWN');
                    continue;
                }

                $quoteData = $this->prepareQuoteData($row, $companyId, $today);

                $existing = $this->crud->find('stock_quotes', [
                    'company_id' => $companyId,
                    'trading_date' => $today
                ]);

                if (!empty($existing)) {
                    $result = $this->crud->merge('stock_quotes', $quoteData, [
                        'company_id' => $companyId,
                        'trading_date' => $today
                    ]);
                    if ($result !== false) {
                        $stats['updated']++;
                    } else {
                        $stats['failed']++;
                    }
                } else {
                    $result = $this->crud->persist('stock_quotes', $quoteData);
                    if ($result) {
                        $stats['inserted']++;
                    } else {
                        $stats['failed']++;
                    }
                }

                $this->recordIntradaySnapshot($companyId, $quoteData);

                $touchedCompanyIds[] = $companyId;

            } catch (Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = ($row['Symbole'] ?? 'UNKNOWN') . ": " . $e->getMessage();
            }
        }

        $stats['touched_company_ids'] = array_values(array_unique($touchedCompanyIds));

        return $stats;
    }

    /**
     * Ajoute un relevé dans l'historique intrajournalier (une ligne par
     * synchronisation, jamais écrasée) pour pouvoir observer la variation du
     * cours au fil de la séance.
     */
    private function recordIntradaySnapshot($companyId, $quoteData) {
        $this->crud->persist('intraday_quotes', [
            'company_id' => $companyId,
            'quote_datetime' => date('Y-m-d H:i:s'),
            'price' => $quoteData['close_price'],
            'volume' => $quoteData['volume'],
            'variation_percent' => $quoteData['variation_percent']
        ]);
    }

    /**
     * Synchronise les indices (BRVM-30, BRVM-COMPOSITE, BRVM-PRESTIGE, BRVM-PRINCIPAL...)
     */
    public function syncIndices() {
        $scrapedData = $this->scraper->scrapeIndices();

        if (!$scrapedData || empty($scrapedData)) {
            throw new Exception("Aucune donnée récupérée du site BRVM (indices)");
        }

        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        $today = date('Y-m-d');

        foreach ($scrapedData as $row) {
            $stats['processed']++;

            try {
                $indexId = $this->ensureIndexExists($row);

                if (!$indexId) {
                    $stats['failed']++;
                    $stats['errors'][] = "Impossible de créer/trouver l'indice: " . ($row['Nom'] ?? 'UNKNOWN');
                    continue;
                }

                $indexValueData = $this->prepareIndexValueData($row, $indexId, $today);

                $existing = $this->crud->find('index_values', [
                    'index_id' => $indexId,
                    'trading_date' => $today
                ]);

                if (!empty($existing)) {
                    $result = $this->crud->merge('index_values', $indexValueData, [
                        'index_id' => $indexId,
                        'trading_date' => $today
                    ]);
                    if ($result !== false) {
                        $stats['updated']++;
                    } else {
                        $stats['failed']++;
                    }
                } else {
                    $result = $this->crud->persist('index_values', $indexValueData);
                    if ($result) {
                        $stats['inserted']++;
                    } else {
                        $stats['failed']++;
                    }
                }

            } catch (Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = ($row['Nom'] ?? 'UNKNOWN') . ": " . $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * S'assure que l'entreprise existe
     */
    private function ensureCompanyExists($row) {
        $symbol = trim($row['Symbole'] ?? '');

        if (empty($symbol)) {
            return false;
        }

        $existing = $this->crud->find('companies', ['symbol' => $symbol]);

        if (!empty($existing)) {
            return $existing[0]['id'];
        }

        $companyData = [
            'symbol' => $symbol,
            'name' => trim($row['Nom'] ?? $symbol),
            'full_name' => trim($row['Nom'] ?? $symbol),
            'active' => 1
        ];

        $countryId = $this->detectCountry($companyData['name']);
        if ($countryId) {
            $companyData['country_id'] = $countryId;
        }

        return $this->crud->persist('companies', $companyData);
    }

    /**
     * Détecte le pays à partir du nom
     */
    private function detectCountry($companyName) {
        $countryPatterns = [
            'COTE D\'IVOIRE' => 'CI',
            'SENEGAL' => 'SN',
            'BURKINA FASO' => 'BF',
            'BENIN' => 'BJ',
            'TOGO' => 'TG',
            'NIGER' => 'NE',
            'MALI' => 'ML'
        ];

        $upperName = strtoupper($companyName);

        foreach ($countryPatterns as $pattern => $code) {
            if (strpos($upperName, $pattern) !== false) {
                $country = $this->crud->find('countries', ['code' => $code]);
                return !empty($country) ? $country[0]['id'] : null;
            }
        }

        return null;
    }

    /**
     * Prépare les données de cotation
     */
    private function prepareQuoteData($row, $companyId, $tradingDate) {
        $openPrice = $this->parseNumber($row['Cours Ouverture (FCFA)'] ?? 0);
        $closePrice = $this->parseNumber($row['Cours Clôture (FCFA)'] ?? 0);
        $previousClose = $this->parseNumber($row['Cours veille (FCFA)'] ?? 0);
        $volume = $this->parseNumber($row['Volume'] ?? 0);
        $variationPercent = $this->parseNumber($row['Variation (%)'] ?? 0);

        $variationValue = $closePrice - $previousClose;
        $turnover = $volume * $closePrice;

        return [
            'company_id' => $companyId,
            'trading_date' => $tradingDate,
            'open_price' => $openPrice,
            'close_price' => $closePrice,
            // NB: le site BRVM n'expose pas le vrai plus haut/plus bas intrajournalier sur
            // cette page ; on approxime avec open/close en attendant une source plus précise.
            'high_price' => max($openPrice, $closePrice),
            'low_price' => min($openPrice, $closePrice),
            'previous_close' => $previousClose,
            'volume' => $volume,
            'variation_percent' => $variationPercent,
            'variation_value' => $variationValue,
            'turnover' => $turnover
        ];
    }

    /**
     * S'assure que l'indice existe (créé automatiquement si le site en ajoute un nouveau)
     */
    private function ensureIndexExists($row) {
        $name = trim($row['Nom'] ?? '');

        if (empty($name)) {
            return false;
        }

        $code = $this->nameToIndexCode($name);

        $existing = $this->crud->find('market_indices', ['code' => $code]);

        if (!empty($existing)) {
            return $existing[0]['id'];
        }

        return $this->crud->persist('market_indices', [
            'code' => $code,
            'name' => $name,
            'active' => 1
        ]);
    }

    /**
     * Normalise un nom d'indice ("BRVM - COMPOSITE") en code ("BRVM-COMPOSITE")
     */
    private function nameToIndexCode($name) {
        $code = strtoupper(trim($name));
        $code = preg_replace('/\s*-\s*/', '-', $code);
        $code = preg_replace('/\s+/', '-', $code);
        return substr($code, 0, 20);
    }

    /**
     * Prépare les données de valeur d'indice
     */
    private function prepareIndexValueData($row, $indexId, $tradingDate) {
        $closeValue = $this->parseNumber($row['Fermeture'] ?? 0);
        $previousClose = $this->parseNumber($row['Fermeture précédente'] ?? 0);
        $variationPercent = $this->parseNumber($row['Variation (%)'] ?? 0);

        return [
            'index_id' => $indexId,
            'trading_date' => $tradingDate,
            // open/high/low ne sont pas fournis par cette page ; seule la clôture est fiable.
            'close_value' => $closeValue,
            'variation_percent' => $variationPercent
        ];
    }

    /**
     * Parse un nombre depuis différents formats
     */
    private function parseNumber($value) {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $cleaned = str_replace([' ', ','], ['', '.'], trim($value));
        return is_numeric($cleaned) ? (float) $cleaned : 0;
    }
}
