<?php
/**
 * Récupération HTTP générique avec repli en cascade (curl -> file_get_contents -> wget),
 * utilisée par les différents scrapers du projet (pages HTML comme fichiers binaires).
 */
class HttpFetcher {
    private $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';
    private $timeout;

    public function __construct($timeout = 30) {
        $this->timeout = $timeout;
    }

    /**
     * Récupère le contenu brut d'une URL (HTML ou binaire), ou false en cas d'échec
     */
    public function fetch($url) {
        $content = $this->fetchWithCurl($url);
        if ($content !== false && strlen($content) > 0) {
            return $content;
        }

        $content = $this->fetchWithFileGetContents($url);
        if ($content !== false && strlen($content) > 0) {
            return $content;
        }

        return $this->fetchWithWget($url);
    }

    /**
     * Télécharge une URL directement vers un fichier (évite de charger un gros
     * PDF entièrement en mémoire avant écriture)
     */
    public function download($url, $destinationPath) {
        if (!function_exists('curl_init')) {
            $content = $this->fetch($url);
            if ($content === false) {
                return false;
            }
            return file_put_contents($destinationPath, $content) !== false;
        }

        $fp = fopen($destinationPath, 'wb');
        if (!$fp) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => max($this->timeout, 60),
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode != 200) {
            @unlink($destinationPath);
            return false;
        }

        return true;
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
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING => '',
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($content !== false && $httpCode == 200) ? $content : false;
    }

    private function fetchWithFileGetContents($url) {
        if (!ini_get('allow_url_fopen')) return false;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: {$this->userAgent}\r\n",
                'timeout' => $this->timeout
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

        $tempFile = tempnam(sys_get_temp_dir(), 'brvm_fetch_');
        $escapedUrl = escapeshellarg($url);
        $cmd = "wget --no-check-certificate --user-agent='{$this->userAgent}' --timeout={$this->timeout} -q -O '$tempFile' $escapedUrl 2>/dev/null";

        shell_exec($cmd);

        if (file_exists($tempFile) && filesize($tempFile) > 0) {
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            return $content;
        }

        if (file_exists($tempFile)) unlink($tempFile);
        return false;
    }
}
