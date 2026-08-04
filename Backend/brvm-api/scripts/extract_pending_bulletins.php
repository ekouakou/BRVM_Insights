<?php
/**
 * Rattrape l'extraction de texte des bulletins en attente — constaté le
 * 04/08/2026 : 10 des 11 bulletins en base avaient échoué avec l'erreur
 * "pdftotext introuvable (installer poppler : brew install poppler)",
 * simplement parce que poppler/tesseract n'étaient pas installés sur cette
 * machine au moment de la tentative. Les deux sont maintenant disponibles
 * (`which pdftotext`, `which tesseract`) — ce script rejoue l'extraction
 * pour tout bulletin où `text_extracted = 0`, ou `= 1` mais dont le contenu
 * est en fait vide (incohérence historique constatée sur le bulletin #1,
 * probablement issue d'un import de dump avec encodage corrompu — voir
 * RESET_DATABASE.md).
 *
 * Réutilise directement PdfTextExtractor / BRVMBulletinsScraper (pas
 * api_bulletins.php, qui fait AuthGuard::requireAuth() en tête de fichier —
 * incompatible avec une exécution CLI).
 *
 * Usage: php scripts/extract_pending_bulletins.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/HttpFetcher.php';
require_once __DIR__ . '/../class/BRVMBulletinsScraper.php';
require_once __DIR__ . '/../class/PdfTextExtractor.php';

define('BULLETINS_STORAGE_DIR', __DIR__ . '/../storage/bulletins');

$crud = new DynamiqueCrud();
$scraper = new BRVMBulletinsScraper();
$extractor = new PdfTextExtractor();

$sql = "
    SELECT b.id, b.title, b.file_url, b.local_path, b.text_extracted, c.extracted_text
    FROM market_bulletins b
    LEFT JOIN market_bulletin_contents c ON c.bulletin_id = b.id
    WHERE b.text_extracted = 0 OR c.extracted_text IS NULL OR c.extracted_text = ''
    ORDER BY b.id ASC
";
$pending = $crud->executeCustomQuery($sql) ?: [];

echo "→ " . count($pending) . " bulletin(s) à (re)traiter.\n\n";

if (!is_dir(BULLETINS_STORAGE_DIR)) {
    mkdir(BULLETINS_STORAGE_DIR, 0755, true);
}

$success = 0;
$failed = 0;

foreach ($pending as $bulletin) {
    $id = $bulletin['id'];
    echo "--- Bulletin #$id ({$bulletin['title']}) ---\n";

    $localPath = $bulletin['local_path'];

    if (empty($localPath) || !is_file($localPath)) {
        $filename = basename(parse_url($bulletin['file_url'], PHP_URL_PATH));
        $localPath = BULLETINS_STORAGE_DIR . '/' . $filename;

        if (!is_file($localPath)) {
            echo "  Téléchargement depuis {$bulletin['file_url']}...\n";
            $ok = $scraper->downloadFile($bulletin['file_url'], $localPath);
            if (!$ok || !is_file($localPath)) {
                echo "  ✗ Échec du téléchargement.\n";
                $crud->merge('market_bulletins', ['extraction_error' => 'Échec du téléchargement'], ['id' => $id]);
                $failed++;
                continue;
            }
        } else {
            echo "  Déjà présent localement : $localPath\n";
        }

        $crud->merge('market_bulletins', [
            'local_path' => $localPath,
            'file_size' => filesize($localPath),
            'file_hash' => hash_file('sha256', $localPath),
            'downloaded_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    $result = $extractor->extract($localPath);

    if (!$result['success']) {
        echo "  ✗ Extraction échouée : {$result['error']}\n";
        $crud->merge('market_bulletins', ['extraction_error' => $result['error']], ['id' => $id]);
        $failed++;
        continue;
    }

    $contentData = [
        'bulletin_id' => $id,
        'extracted_text' => $result['text'],
        'char_count' => strlen($result['text']),
    ];
    $existing = $crud->find('market_bulletin_contents', ['bulletin_id' => $id]);
    if (!empty($existing)) {
        $crud->merge('market_bulletin_contents', $contentData, ['bulletin_id' => $id]);
    } else {
        $crud->persist('market_bulletin_contents', $contentData);
    }

    $crud->merge('market_bulletins', [
        'text_extracted' => 1,
        'extraction_method' => $result['method'],
        'extraction_error' => null,
    ], ['id' => $id]);

    echo "  ✓ Extrait via '{$result['method']}' — " . strlen($result['text']) . " caractères.\n";
    $success++;
}

echo "\nTerminé : $success réussi(s), $failed échoué(s).\n";
