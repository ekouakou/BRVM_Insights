<?php
/**
 * Version améliorée du scraper BRVM
 * Compatible avec l'architecture de l'application
 */

class BRVMScraperFixed {
    private $url = 'https://www.brvm.org/fr/cours-actions/0';
    private $indicesUrl = 'https://www.brvm.org/fr/indices';
    private $logFile;
    private $dataFile;
    private $indicesDataFile;

    public function __construct($logFile = null, $dataFile = null, $indicesDataFile = null) {
        $this->logFile = $logFile ?? __DIR__ . '/../logs/brvm_log.txt';
        $this->dataFile = $dataFile ?? __DIR__ . '/../logs/brvm_data.json';
        $this->indicesDataFile = $indicesDataFile ?? __DIR__ . '/../logs/brvm_indices_data.json';

        // Créer les dossiers si nécessaire
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }

    /**
     * Récupération avec plusieurs méthodes de fallback
     */
    private function fetchHTML($url = null) {
        $url = $url ?? $this->url;
        $methods = ['curl', 'file_get_contents', 'wget'];

        foreach ($methods as $method) {
            $this->log("Tentative avec $method sur $url...");

            switch ($method) {
                case 'curl':
                    $html = $this->fetchWithCurl($url);
                    break;
                case 'file_get_contents':
                    $html = $this->fetchWithFileGetContents($url);
                    break;
                case 'wget':
                    $html = $this->fetchWithWget($url);
                    break;
            }

            if ($html && strlen($html) > 1000) {
                $this->log("Succès avec $method !");
                return $html;
            }
        }

        $this->log("Toutes les méthodes ont échoué");
        return false;
    }

    private function fetchWithCurl($url) {
        if (!function_exists('curl_init')) return false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ],
            CURLOPT_ENCODING => '', // Pour gérer gzip
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log("Erreur cURL: $error");
        }

        return ($html !== false && $httpCode == 200) ? $html : false;
    }

    private function fetchWithFileGetContents($url) {
        if (!ini_get('allow_url_fopen')) return false;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8'
                ]),
                'timeout' => 30
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);

        return @file_get_contents($url, false, $context);
    }

    private function fetchWithWget($url) {
        if (!function_exists('shell_exec')) return false;

        $tempFile = tempnam(sys_get_temp_dir(), 'brvm_');
        $escapedUrl = escapeshellarg($url);
        $cmd = "wget --no-check-certificate --user-agent='Mozilla/5.0' --timeout=30 -q -O '$tempFile' $escapedUrl 2>/dev/null";

        shell_exec($cmd);

        if (file_exists($tempFile) && filesize($tempFile) > 1000) {
            $html = file_get_contents($tempFile);
            unlink($tempFile);
            return $html;
        }

        if (file_exists($tempFile)) unlink($tempFile);
        return false;
    }
    
    /**
     * Parse le tableau avec recherche flexible
     */
    private function parseTable($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        $xpath = new DOMXPath($dom);
        
        // Chercher l'élément block-system-main
        $blockSystemMain = $xpath->query("//*[@id='block-system-main']")->item(0);
        
        if ($blockSystemMain) {
            $this->log("Élément #block-system-main trouvé : " . $blockSystemMain->nodeName);
            
            // Chercher le tableau à l'intérieur
            $table = $xpath->query(".//table", $blockSystemMain)->item(0);
            
            if ($table) {
                $this->log("Tableau trouvé dans #block-system-main");
                $tableClass = $table->getAttribute('class');
                $this->log("Classes du tableau: " . $tableClass);
            } else {
                $this->log("Aucun tableau trouvé dans #block-system-main");
                return false;
            }
        } else {
            $this->log("Élément #block-system-main non trouvé - recherche alternative");
            
            // Fallback : rechercher directement les tableaux
            $selectors = [
                "//table[contains(@class, 'table-hover')]",
                "//table[contains(@class, 'sticky-enabled')]",
                "//table[contains(@class, 'table-striped')]"
            ];
            
            foreach ($selectors as $selector) {
                $tables = $xpath->query($selector);
                if ($tables->length > 0) {
                    foreach ($tables as $potentialTable) {
                        if ($this->isStockTable($potentialTable)) {
                            $table = $potentialTable;
                            $this->log("Tableau d'actions trouvé via fallback");
                            break 2;
                        }
                    }
                }
            }
        }
        
        if (!$table) {
            $this->log("Aucun tableau d'actions trouvé");
            return false;
        }
        
        return $this->extractTableData($table);
    }
    
    /**
     * Vérifie si un tableau contient des données d'actions
     */
    private function isStockTable($table) {
        $rows = $table->getElementsByTagName('tr');
        if ($rows->length < 2) return false;
        
        $firstRow = $rows->item(0);
        $headers = [];
        
        $headerCells = $firstRow->getElementsByTagName('th');
        if ($headerCells->length == 0) {
            $headerCells = $firstRow->getElementsByTagName('td');
        }
        
        foreach ($headerCells as $cell) {
            $headers[] = strtolower(trim($cell->textContent));
        }
        
        // Vérifier les mots-clés typiques
        $stockKeywords = ['symbole', 'cours', 'volume', 'variation'];
        foreach ($stockKeywords as $keyword) {
            foreach ($headers as $header) {
                if (strpos($header, $keyword) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Extrait les données du tableau
     */
    private function extractTableData($table) {
        $data = [];
        $rows = $table->getElementsByTagName('tr');
        
        if ($rows->length < 2) {
            $this->log("Tableau insuffisant de lignes: " . $rows->length);
            return [];
        }
        
        // En-têtes
        $headers = [];
        $headerRow = $rows->item(0);
        if ($headerRow) {
            $headerCells = $headerRow->getElementsByTagName('th');
            if ($headerCells->length == 0) {
                $headerCells = $headerRow->getElementsByTagName('td');
            }
            
            foreach ($headerCells as $cell) {
                $headerText = trim($cell->textContent);
                $headers[] = $headerText;
            }
        }
        
        if (empty($headers)) {
            $this->log("Aucun en-tête trouvé");
            return [];
        }
        
        $this->log("Nombre d'en-têtes: " . count($headers));
        
        // Données
        $validRows = 0;
        for ($i = 1; $i < $rows->length; $i++) {
            $row = $rows->item($i);
            $cells = $row->getElementsByTagName('td');
            
            if ($cells->length > 0) {
                $rowData = [];
                $hasData = false;
                
                foreach ($cells as $index => $cell) {
                    $header = isset($headers[$index]) ? $headers[$index] : "colonne_$index";
                    $value = trim($cell->textContent);
                    
                    // Nettoyage
                    $value = preg_replace('/\s+/', ' ', $value);
                    
                    if (!empty($value)) {
                        $hasData = true;
                    }
                    
                    // Conversion numérique
                    if (preg_match('/^[\d\s,.-]+$/', $value) && !empty($value)) {
                        $numValue = str_replace([' ', ','], ['', '.'], $value);
                        if (is_numeric($numValue)) {
                            $value = (float) $numValue;
                        }
                    }
                    
                    $rowData[$header] = $value;
                }
                
                if ($hasData && !empty($rowData)) {
                    $data[] = $rowData;
                    $validRows++;
                }
            }
        }
        
        $this->log("Lignes de données extraites: $validRows");
        
        if (!empty($data)) {
            $this->log("Exemple première ligne: " . json_encode($data[0], JSON_UNESCAPED_UNICODE));
        }
        
        return $data;
    }
    
    /**
     * Sauvegarde les données
     */
    private function saveData($data) {
        $timestamp = date('Y-m-d H:i:s');
        $dataWithTimestamp = [
            'timestamp' => $timestamp,
            'data' => $data,
            'total_records' => count($data),
            'sample_record' => !empty($data) ? $data[0] : null
        ];
        
        $json = json_encode($dataWithTimestamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->dataFile, $json);
        
        $this->log("Données sauvegardées: " . count($data) . " enregistrements à $timestamp");
    }
    
    /**
     * Logging
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        
        // Afficher aussi en mode CLI
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    /**
     * Méthode principale de scraping
     */
    public function scrapeData() {
        $this->log("Début de la récupération des données BRVM...");
        
        $html = $this->fetchHTML();
        if (!$html) {
            $this->log("Impossible de récupérer le HTML");
            return false;
        }
        
        $this->log("HTML récupéré, taille: " . strlen($html) . " caractères");
        
        $data = $this->parseTable($html);
        if (!$data || empty($data)) {
            $this->log("Aucune donnée extraite du tableau");
            return false;
        }
        
        $this->saveData($data);
        $this->log("Récupération terminée avec succès !");

        return $data;
    }

    /**
     * Récupère les indices du marché (BRVM-30, BRVM-COMPOSITE, BRVM-PRESTIGE, BRVM-PRINCIPAL...)
     * depuis la page /fr/indices (bloc #block-tools-indices)
     */
    public function scrapeIndices() {
        $this->log("Début de la récupération des indices BRVM...");

        $html = $this->fetchHTML($this->indicesUrl);
        if (!$html) {
            $this->log("Impossible de récupérer le HTML des indices");
            return false;
        }

        $this->log("HTML des indices récupéré, taille: " . strlen($html) . " caractères");

        $data = $this->parseIndicesTable($html);
        if (!$data || empty($data)) {
            $this->log("Aucune donnée d'indice extraite");
            return false;
        }

        $this->saveIndicesData($data);
        $this->log("Récupération des indices terminée avec succès !");

        return $data;
    }

    /**
     * Parse les tableaux d'indices (bloc(s) #block-tools-indices).
     *
     * La page /fr/indices contient en réalité TROIS blocs distincts partageant
     * le même id "block-tools-indices" (HTML invalide mais bien réel côté
     * BRVM) : les 4 indices principaux, les indices sectoriels ("Indices
     * sectoriels"), et l'indice Total Return ("Indice Total Return", ex:
     * BRVM-COMPOSITE TOTAL RETURN). L'ancienne version ne prenait que
     * ->item(0) (le premier bloc), ignorant silencieusement les deux autres —
     * corrigé en itérant sur tous les blocs trouvés. ensureIndexExists()
     * côté BRVMSyncService crée déjà dynamiquement toute nouvelle ligne
     * d'indice rencontrée, donc aucune table supplémentaire n'est nécessaire
     * ici, juste ce fix.
     */
    private function parseIndicesTable($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        $xpath = new DOMXPath($dom);

        $blocks = $xpath->query("//*[@id='block-tools-indices']");

        if (!$blocks || $blocks->length === 0) {
            $this->log("Élément #block-tools-indices non trouvé");
            return false;
        }

        $data = [];
        foreach ($blocks as $block) {
            $table = $xpath->query(".//table", $block)->item(0);
            if (!$table) {
                continue;
            }
            $rows = $this->extractTableData($table);
            if ($rows) {
                $data = array_merge($data, $rows);
            }
        }

        if (empty($data)) {
            $this->log("Aucun tableau trouvé dans les blocs #block-tools-indices");
            return false;
        }

        return $data;
    }

    /**
     * Sauvegarde les données d'indices
     */
    private function saveIndicesData($data) {
        $timestamp = date('Y-m-d H:i:s');
        $dataWithTimestamp = [
            'timestamp' => $timestamp,
            'data' => $data,
            'total_records' => count($data)
        ];

        $json = json_encode($dataWithTimestamp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->indicesDataFile, $json);

        $this->log("Indices sauvegardés: " . count($data) . " enregistrements à $timestamp");
    }

    /**
     * Debug HTML structure
     */
    public function debugHTML() {
        $this->log("Mode debug - Analyse de la structure HTML...");
        
        $html = $this->fetchHTML();
        if (!$html) {
            $this->log("Impossible de récupérer le HTML pour le debug");
            return false;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xpath = new DOMXPath($dom);
        
        $blockElement = $xpath->query("//*[@id='block-system-main']")->item(0);
        
        if ($blockElement) {
            $this->log("=== STRUCTURE DE #block-system-main ===");
            $this->log("Type d'élément: " . $blockElement->nodeName);
            $this->log("Classes: " . $blockElement->getAttribute('class'));
            
            $children = $xpath->query("./*", $blockElement);
            $this->log("Enfants directs (" . $children->length . "):");
            
            foreach ($children as $child) {
                $this->log("  - " . $child->nodeName . " (class: '" . $child->getAttribute('class') . "')");
            }
            
            $tables = $xpath->query(".//table", $blockElement);
            $this->log("Tableaux trouvés: " . $tables->length);
        } else {
            $this->log("#block-system-main non trouvé");
        }
        
        return true;
    }
}

// Utilisation autonome du fichier
if (php_sapi_name() === 'cli' && basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    echo "=== Test du scraper BRVM ===\n\n";
    
    $scraper = new BRVMScraperFixed();
    $result = $scraper->scrapeData();
    
    if ($result) {
        echo "\n✓ Succès ! " . count($result) . " enregistrements récupérés.\n";
        if (!empty($result)) {
            echo "\nPremier enregistrement:\n";
            print_r($result[0]);
        }
    } else {
        echo "\n✗ Échec de la récupération.\n";
    }
}