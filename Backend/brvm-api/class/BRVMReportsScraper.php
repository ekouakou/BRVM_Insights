<?php
/**
 * Scraper des rapports publiés par les sociétés cotées sur brvm.org
 * (annuaire /fr/rapports-societes-cotees + pages /fr/rapports-societe-cotes/{slug})
 */
class BRVMReportsScraper {
    private $baseUrl = 'https://www.brvm.org';
    private $directoryUrl = 'https://www.brvm.org/fr/rapports-societes-cotees';
    private $fetcher;
    private $logFile;

    public function __construct($logFile = null) {
        $this->fetcher = new HttpFetcher(30);
        $this->logFile = $logFile ?? __DIR__ . '/../logs/reports_scraper.log';

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
     * Parcourt l'annuaire paginé et retourne la liste [['slug' => ..., 'name' => ...], ...]
     */
    public function discoverCompanySlugs($maxPages = 10) {
        $seen = [];
        $result = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $url = $page === 0 ? $this->directoryUrl : "{$this->directoryUrl}?page={$page}";
            $this->log("Découverte annuaire, page $page: $url");

            $html = $this->fetcher->fetch($url);
            if (!$html) {
                $this->log("Échec de récupération de la page $page, arrêt de la pagination");
                break;
            }

            $entries = $this->parseDirectoryPage($html);

            $newCount = 0;
            foreach ($entries as $entry) {
                if (!isset($seen[$entry['slug']])) {
                    $seen[$entry['slug']] = true;
                    $result[] = $entry;
                    $newCount++;
                }
            }

            $this->log("Page $page: " . count($entries) . " entrées, dont $newCount nouvelles");

            if ($newCount === 0) {
                break; // fin de la pagination
            }
        }

        return $result;
    }

    private function parseDirectoryPage($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $links = $xpath->query("//a[contains(@href, '/fr/rapports-societe-cotes/')]");
        $entries = [];

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!preg_match('#/fr/rapports-societe-cotes/([a-z0-9-]+)#i', $href, $m)) {
                continue;
            }

            $entries[] = [
                'slug' => $m[1],
                'name' => trim($link->textContent)
            ];
        }

        return $entries;
    }

    /**
     * Récupère la liste des rapports d'une entreprise (titre + URL du PDF)
     */
    public function scrapeCompanyReports($slug) {
        $url = "{$this->baseUrl}/fr/rapports-societe-cotes/{$slug}";
        $this->log("Scraping des rapports pour '$slug': $url");

        $html = $this->fetcher->fetch($url);
        if (!$html) {
            $this->log("Échec de récupération de la page rapports pour '$slug'");
            return false;
        }

        $reports = $this->parseReportsTable($html);
        $this->log(count($reports) . " rapport(s) trouvé(s) pour '$slug'");

        return $reports;
    }

    private function parseReportsTable($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query("//table[contains(@class, 'views-table')]//tr");
        $reports = [];

        foreach ($rows as $row) {
            $titleNode = $xpath->query(".//td[contains(@class, 'views-field-nothing')]//strong", $row)->item(0);
            $linkNode = $xpath->query(".//td[contains(@class, 'views-field-field-fichier')]//a[@href]", $row)->item(0);

            if (!$titleNode || !$linkNode) {
                continue;
            }

            $title = trim($titleNode->textContent);
            $fileUrl = trim($linkNode->getAttribute('href'));

            if (empty($title) || empty($fileUrl)) {
                continue;
            }

            $reports[] = [
                'title' => $title,
                'file_url' => $fileUrl
            ];
        }

        return $reports;
    }

    /**
     * Télécharge un fichier vers un chemin local
     */
    public function downloadFile($url, $destinationPath) {
        return $this->fetcher->download($url, $destinationPath);
    }
}
