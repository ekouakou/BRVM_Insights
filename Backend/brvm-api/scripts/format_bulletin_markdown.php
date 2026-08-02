<?php
/**
 * Restructure le texte extrait d'un bulletin en Markdown (tableaux) via IA —
 * voir class/BulletinMarkdownFormatterService.php. Prévu pour être lancé en
 * arrière-plan par api_bulletins.php (action 'format_markdown'), détaché de
 * la requête HTTP qui l'a déclenché (une génération complète prend
 * couramment ~2 minutes, largement au-delà du timeout FastCGI de MAMP).
 *
 * Usage:
 *   php scripts/format_bulletin_markdown.php <bulletin_id> [--provider=gemini] [--model=...]
 *
 * Toute erreur est capturée et persistée en base (markdown_status='failed')
 * plutôt que de planter silencieusement — l'appelant HTTP ne verra jamais la
 * sortie de ce script, seul l'état en base compte.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/AiClientInterface.php';
require_once __DIR__ . '/../class/GeminiClient.php';
require_once __DIR__ . '/../class/AnthropicClient.php';
require_once __DIR__ . '/../class/BulletinMarkdownFormatterService.php';

$bulletinId = (int) ($argv[1] ?? 0);
if (!$bulletinId) {
    fwrite(STDERR, "Usage: php scripts/format_bulletin_markdown.php <bulletin_id> [--provider=gemini] [--model=...]\n");
    exit(1);
}

$options = getopt('', ['provider:', 'model:']);
$provider = $options['provider'] ?? null;
$model = $options['model'] ?? null;

$crud = new DynamiqueCrud();
$service = new BulletinMarkdownFormatterService($crud);

try {
    $service->format($bulletinId, $provider, $model);
    echo "OK: bulletin $bulletinId formaté\n";
} catch (Throwable $e) {
    $crud->merge('market_bulletin_contents', [
        'markdown_status' => 'failed',
        'markdown_error' => 'Erreur inattendue: ' . $e->getMessage(),
        'markdown_updated_at' => date('Y-m-d H:i:s'),
    ], ['bulletin_id' => $bulletinId]);
    fwrite(STDERR, "ÉCHEC: " . $e->getMessage() . "\n");
    exit(1);
}
