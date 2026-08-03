<?php
/**
 * Diagnostic à uploader et ouvrir dans un navigateur UNE SEULE FOIS juste
 * après le déploiement sur un nouvel hébergement (mutualisé/cPanel en
 * particulier), pour savoir ce qui fonctionnera avant de perdre du temps à
 * tout configurer. Vérifie les points connus pour être restreints sur un
 * hébergement mutualisé (contrairement à MAMP en local) :
 *
 *   - exec()/shell_exec() : nécessaires pour PdfTextExtractor (pdftotext,
 *     tesseract) ET pour le pattern "process détaché" de format_markdown()
 *     dans api_reports.php/api_bulletins.php. Souvent désactivés par
 *     défaut sur du mutualisé pour des raisons de sécurité.
 *   - pdftotext / pdftoppm / tesseract : binaires système, jamais installés
 *     par défaut sur du mutualisé (pas d'accès root pour les installer).
 *   - Extensions PHP : pdo_mysql, curl, mbstring, dom — utilisées par ce
 *     projet, pas forcément activées selon la configuration PHP de l'hébergeur.
 *   - max_execution_time : les appels aux fournisseurs IA (60-90s, voir
 *     class/AnthropicClient.php / GeminiClient.php) peuvent dépasser la
 *     limite par défaut (souvent 30s sur du mutualisé) si le compte ne
 *     permet pas de l'augmenter.
 *
 * IMPORTANT : supprime ce fichier du serveur une fois la vérification
 * faite — il expose des détails de configuration serveur (chemins,
 * fonctions désactivées) qui ne doivent pas rester accessibles publiquement.
 */

header('Content-Type: text/plain; charset=utf-8');

function checkLine($label, $ok, $detail = '') {
    $status = $ok ? 'OK  ' : 'MANQUANT';
    echo str_pad($status, 10) . " " . str_pad($label, 32) . " " . $detail . "\n";
}

function detectBinary($name) {
    $candidates = ["/usr/bin/$name", "/usr/local/bin/$name", "/opt/cpanel/composer/bin/$name"];
    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }
    if (function_exists('shell_exec')) {
        $which = @shell_exec("command -v $name 2>/dev/null");
        $which = $which !== null ? trim($which) : '';
        return $which !== '' ? $which : null;
    }
    return null;
}

echo "=== Diagnostic hébergement — BRVM Insights ===\n\n";

echo "--- PHP ---\n";
checkLine('Version PHP', version_compare(PHP_VERSION, '8.1', '>='), PHP_VERSION . ' (8.1+ recommandé)');
checkLine('Extension pdo_mysql', extension_loaded('pdo_mysql'));
checkLine('Extension curl', extension_loaded('curl'));
checkLine('Extension mbstring', extension_loaded('mbstring'));
checkLine('Extension dom', extension_loaded('dom'));
checkLine('Extension json', extension_loaded('json'));

echo "\n--- Fonctions exec (extraction PDF + formatage markdown asynchrone) ---\n";
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
checkLine('exec()', function_exists('exec') && !in_array('exec', $disabled, true));
checkLine('shell_exec()', function_exists('shell_exec') && !in_array('shell_exec', $disabled, true));
checkLine('proc_open()', function_exists('proc_open') && !in_array('proc_open', $disabled, true));
if (!empty(array_filter($disabled))) {
    echo "  disable_functions (php.ini) : " . implode(', ', array_filter($disabled)) . "\n";
}

echo "\n--- Binaires système (extraction PDF, voir class/PdfTextExtractor.php) ---\n";
$pdftotext = detectBinary('pdftotext');
$pdftoppm = detectBinary('pdftoppm');
$tesseract = detectBinary('tesseract');
checkLine('pdftotext (poppler)', $pdftotext !== null, $pdftotext ?? "absent — l'extraction de texte natif des PDF ne fonctionnera pas");
checkLine('pdftoppm (poppler)', $pdftoppm !== null, $pdftoppm ?? 'absent — pas de repli OCR possible');
checkLine('tesseract (OCR)', $tesseract !== null, $tesseract ?? 'absent — pas de repli OCR possible');

echo "\n--- Limites d'exécution (appels IA : 60-90s, voir class/AnthropicClient.php / GeminiClient.php) ---\n";
checkLine('max_execution_time', (int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 90, ini_get('max_execution_time') . 's');
checkLine('memory_limit', true, ini_get('memory_limit'));
checkLine('upload_max_filesize', true, ini_get('upload_max_filesize') . ' (import PDF/markdown manuel)');

echo "\n--- Connectivité sortante (scraping brvm.org + appels IA) ---\n";
$outboundOk = false;
if (function_exists('curl_init')) {
    $ch = curl_init('https://www.brvm.org/fr/cours-actions/0');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $outboundOk = $httpCode >= 200 && $httpCode < 400;
    checkLine('HTTPS sortant vers brvm.org', $outboundOk, $outboundOk ? "HTTP $httpCode" : ($error ?: "HTTP $httpCode"));
} else {
    checkLine('HTTPS sortant vers brvm.org', false, 'extension curl absente, test impossible');
}

echo "\n--- Verdict ---\n";
if (!$pdftotext || !(function_exists('exec') && !in_array('exec', $disabled, true))) {
    echo "L'extraction automatique de texte PDF et le formatage markdown en\n";
    echo "arrière-plan risquent fort de NE PAS fonctionner sur cet hébergement.\n";
    echo "Solution de repli déjà intégrée à l'app : extraire/formater les PDF en\n";
    echo "local (où pdftotext/tesseract sont dispo, ex: ta machine de dev) puis\n";
    echo "utiliser le bouton \"Importer un markdown\" du panneau d'admin — pas\n";
    echo "besoin d'exec() côté serveur pour ce chemin-là.\n";
} else {
    echo "Tout semble disponible pour un fonctionnement complet.\n";
}

echo "\n⚠️  Supprime ce fichier du serveur maintenant que tu l'as consulté.\n";
