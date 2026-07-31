<?php
/**
 * Extraction de texte depuis un PDF.
 *
 * Deux méthodes, essayées dans l'ordre :
 *   1. `pdftotext` (poppler) — instantané, mais ne lit que la couche de texte
 *      numérique d'un PDF (rien pour un scan papier).
 *   2. OCR via `tesseract` (+ `pdftoppm` pour rasteriser les pages) — beaucoup
 *      plus lent (plusieurs secondes par page), mais fonctionne sur les PDF
 *      scannés/image, courants pour les attestations signées.
 *
 * Installation (macOS): `brew install poppler tesseract tesseract-lang`
 */
class PdfTextExtractor {
    private $pdftotextPath;
    private $pdftoppmPath;
    private $tesseractPath;
    private $ocrLanguage;
    private $ocrDpi;

    public function __construct($ocrLanguage = 'fra', $ocrDpi = 200) {
        $this->pdftotextPath = $this->detectBinary('pdftotext');
        $this->pdftoppmPath = $this->detectBinary('pdftoppm');
        $this->tesseractPath = $this->detectBinary('tesseract');
        $this->ocrLanguage = $ocrLanguage;
        $this->ocrDpi = $ocrDpi;
    }

    public function isAvailable() {
        return $this->pdftotextPath !== null;
    }

    public function isOcrAvailable() {
        return $this->pdftoppmPath !== null && $this->tesseractPath !== null;
    }

    /**
     * Extrait le texte d'un PDF local : texte natif d'abord, puis repli OCR
     * si le PDF n'a pas de couche de texte exploitable (scan/image).
     *
     * @return array{success: bool, text: ?string, error: ?string, method: ?string}
     */
    public function extract($pdfPath) {
        if (!is_file($pdfPath)) {
            return ['success' => false, 'text' => null, 'error' => "Fichier introuvable: $pdfPath", 'method' => null];
        }

        if ($this->isAvailable()) {
            $native = $this->extractNativeText($pdfPath);
            if ($native['success']) {
                return array_merge($native, ['method' => 'text']);
            }
        } else {
            $native = ['error' => "pdftotext introuvable (installer poppler: brew install poppler)"];
        }

        if ($this->isOcrAvailable()) {
            $ocr = $this->extractWithOcr($pdfPath);
            if ($ocr['success']) {
                return array_merge($ocr, ['method' => 'ocr']);
            }
            return [
                'success' => false,
                'text' => null,
                'error' => "Texte natif: {$native['error']} | OCR: {$ocr['error']}",
                'method' => null,
            ];
        }

        return [
            'success' => false,
            'text' => null,
            'error' => $native['error'] . " (OCR non disponible: installer tesseract avec 'brew install tesseract tesseract-lang')",
            'method' => null,
        ];
    }

    /**
     * Texte natif via pdftotext (rapide, mais rien sur un PDF scanné)
     */
    private function extractNativeText($pdfPath) {
        $tmpTxt = tempnam(sys_get_temp_dir(), 'brvm_pdf_') . '.txt';

        $cmd = escapeshellcmd($this->pdftotextPath) . ' -layout ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpTxt) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($tmpTxt)) {
            @unlink($tmpTxt);
            return ['success' => false, 'text' => null, 'error' => "pdftotext a échoué (code $exitCode): " . implode(' ', $output)];
        }

        $text = file_get_contents($tmpTxt);
        @unlink($tmpTxt);

        // pdftotext insère un saut de page (\f) même quand une page ne contient
        // aucun texte (cas des PDF scannés) : un résultat composé uniquement de
        // \f/espaces n'est PAS un texte exploitable, même si non vide.
        $text = str_replace("\f", "\n\n", trim($text));

        if (!$this->isMeaningful($text)) {
            return ['success' => false, 'text' => null, 'error' => "Aucun texte natif (PDF probablement scanné/image)"];
        }

        return ['success' => true, 'text' => $text, 'error' => null];
    }

    /**
     * OCR : rasterise chaque page en image (pdftoppm) puis la fait lire par tesseract
     */
    private function extractWithOcr($pdfPath) {
        $tmpDir = sys_get_temp_dir() . '/brvm_ocr_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $prefix = $tmpDir . '/page';

        $rasterCmd = escapeshellcmd($this->pdftoppmPath)
            . ' -r ' . (int) $this->ocrDpi
            . ' -png '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg($prefix) . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($rasterCmd, $output, $exitCode);

        $pages = glob($tmpDir . '/page-*.png');
        sort($pages, SORT_NATURAL);

        if ($exitCode !== 0 || empty($pages)) {
            $this->cleanupDir($tmpDir);
            return ['success' => false, 'text' => null, 'error' => "pdftoppm a échoué (code $exitCode): " . implode(' ', $output)];
        }

        $pageTexts = [];
        foreach ($pages as $pageImage) {
            $ocrText = $this->ocrImage($pageImage);
            if ($ocrText !== null) {
                $pageTexts[] = trim($ocrText);
            }
        }

        $this->cleanupDir($tmpDir);

        $text = trim(implode("\n\n", array_filter($pageTexts)));

        if (!$this->isMeaningful($text)) {
            return ['success' => false, 'text' => null, 'error' => "OCR n'a détecté aucun texte exploitable sur " . count($pages) . " page(s)"];
        }

        return ['success' => true, 'text' => $text, 'error' => null];
    }

    private function ocrImage($imagePath) {
        // tesseract ajoute lui-même ".txt" au nom de sortie qu'on lui donne
        $outBase = tempnam(sys_get_temp_dir(), 'brvm_ocr_out_');
        @unlink($outBase);

        $cmd = escapeshellcmd($this->tesseractPath) . ' '
            . escapeshellarg($imagePath) . ' '
            . escapeshellarg($outBase) . ' '
            . '-l ' . escapeshellarg($this->ocrLanguage) . ' '
            . '--psm 3 2>&1';

        exec($cmd, $output, $exitCode);

        $outFile = $outBase . '.txt';
        $text = is_file($outFile) ? file_get_contents($outFile) : null;
        @unlink($outFile);

        return $text;
    }

    private function isMeaningful($text) {
        $meaningfulLength = strlen(trim(preg_replace('/\s+/u', '', $text)));
        return $meaningfulLength >= 20;
    }

    private function cleanupDir($dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function detectBinary($name) {
        $candidates = ["/opt/homebrew/bin/$name", "/usr/local/bin/$name", "/usr/bin/$name"];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec("command -v $name 2>/dev/null"));
        return $which !== '' ? $which : null;
    }
}
