<?php
/**
 * Scraper des Bulletins Officiels de la Cote (BOC) publiés sur brvm.org
 * (fr/bulletins-officiels-de-la-cote) — un PDF quotidien récapitulant la
 * séance (cours de tous les titres, volumes, indices).
 *
 * Même thème Drupal Views que les rapports de sociétés cotées
 * (class/BRVMReportsScraper.php), mais une seule page à parser : pas de
 * matching entreprise↔slug ici.
 */
class BRVMBulletinsScraper {
    private $listUrl = 'https://www.brvm.org/fr/bulletins-officiels-de-la-cote';
    private $fetcher;
    private $logFile;

    public function __construct($logFile = null) {
        $this->fetcher = new HttpFetcher(30);
        $this->logFile = $logFile ?? __DIR__ . '/../logs/bulletins_scraper.log';

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }

    public function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);

        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }

    /**
     * Liste des bulletins visibles sur la page (les ~10-11 derniers jours de
     * bourse — la page ne propose ni pagination ni filtre par date).
     *
     * @return array<int,array{title:string,file_url:string}>|false
     */
    public function scrapeBulletinsList() {
        $this->log("Scraping des bulletins: {$this->listUrl}");

        $html = $this->fetcher->fetch($this->listUrl);
        if (!$html) {
            $this->log("Échec de récupération de la page bulletins");
            return false;
        }

        $bulletins = $this->parseBulletinsTables($html);
        $this->log(count($bulletins) . " bulletin(s) trouvé(s)");

        return $bulletins;
    }

    private function parseBulletinsTables($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query("//table[contains(@class, 'views-table')]//tr");
        $bulletins = [];
        $seenUrls = [];

        foreach ($rows as $row) {
            $titleNode = $xpath->query(".//td[contains(@class, 'views-field-title')]", $row)->item(0);
            $linkNode = $xpath->query(".//td[contains(@class, 'views-field-field-fichier-boc')]//a[@href]", $row)->item(0);

            if (!$titleNode || !$linkNode) {
                continue;
            }

            $title = trim($titleNode->textContent);
            $fileUrl = trim($linkNode->getAttribute('href'));

            if (empty($title) || empty($fileUrl) || isset($seenUrls[$fileUrl])) {
                continue;
            }

            $seenUrls[$fileUrl] = true;
            $bulletins[] = [
                'title' => $title,
                'file_url' => $fileUrl,
            ];
        }

        return $bulletins;
    }

    /**
     * Déduit la date de publication du nom de fichier (ex: boc_20260731_2.pdf)
     */
    public static function inferPublishDate($fileUrl) {
        $filename = basename(parse_url($fileUrl, PHP_URL_PATH));
        if (preg_match('/^boc_(\d{4})(\d{2})(\d{2})_/', $filename, $m)) {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $date;
            }
        }
        return null;
    }

    /**
     * Télécharge un fichier vers un chemin local
     */
    public function downloadFile($url, $destinationPath) {
        return $this->fetcher->download($url, $destinationPath);
    }

    /**
     * URL candidate pour le bulletin d'une date donnée, en suivant le format
     * observé sur brvm.org (boc_YYYYMMDD_2.pdf). Le suffixe numérique est
     * quasi toujours "2" (vérifié empiriquement), mais on essaie 1/3/4 en
     * repli au cas où — voir findBulletinUrlForDate().
     */
    public static function candidateUrl(string $date, int $suffix = 2): string {
        $compact = str_replace('-', '', $date);
        return "https://www.brvm.org/sites/default/files/boc_{$compact}_{$suffix}.pdf";
    }

    /**
     * Cherche l'URL réelle du bulletin d'une date donnée en testant les
     * suffixes numériques connus (HEAD request, pas de téléchargement).
     * Ne fonctionne de façon fiable que depuis ~fin 2021 : le site utilisait
     * un schéma d'URL différent avant (non deviné, testé et invalidé).
     *
     * @return string|null L'URL trouvée, ou null si aucun suffixe ne répond 200
     */
    public function findBulletinUrlForDate(string $date): ?string {
        foreach ([2, 1, 3, 4] as $suffix) {
            $url = self::candidateUrl($date, $suffix);
            if ($this->urlExists($url)) {
                return $url;
            }
        }
        return null;
    }

    private function urlExists(string $url): bool {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Titre au même format que ceux scrapés depuis la page ("Bulletin
     * Officiel de la Cote du 31 Juillet 2026"), pour une entrée ajoutée
     * manuellement par recherche de date.
     */
    public static function buildTitleForDate(string $date): string {
        $months = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];
        [$year, $month, $day] = explode('-', $date);
        return sprintf('Bulletin Officiel de la Cote du %d %s %d', (int) $day, $months[(int) $month], (int) $year);
    }
}
