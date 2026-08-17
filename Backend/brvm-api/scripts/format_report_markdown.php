<?php
/**
 * Restructure le texte extrait d'un rapport de société en Markdown
 * (tableaux) via IA — voir class/ReportMarkdownFormatterService.php. Prévu
 * pour être lancé en arrière-plan par api_reports.php (action
 * 'format_markdown'), détaché de la requête HTTP qui l'a déclenché (une
 * génération complète peut prendre plusieurs minutes, largement au-delà du
 * timeout FastCGI de MAMP).
 *
 * Usage:
 *   php scripts/format_report_markdown.php <report_id> [--provider=gemini] [--model=...]
 *
 * Toute erreur est capturée et persistée en base (markdown_status='failed')
 * plutôt que de planter silencieusement — l'appelant HTTP ne verra jamais la
 * sortie de ce script, seul l'état en base compte.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/AiClientInterface.php';
require_once __DIR__ . '/../class/AiChatClientInterface.php';
require_once __DIR__ . '/../class/GeminiClient.php';
require_once __DIR__ . '/../class/AnthropicClient.php';
require_once __DIR__ . '/../class/ReportMarkdownFormatterService.php';

$reportId = (int) ($argv[1] ?? 0);
if (!$reportId) {
    fwrite(STDERR, "Usage: php scripts/format_report_markdown.php <report_id> [--provider=gemini] [--model=...]\n");
    exit(1);
}

$options = getopt('', ['provider:', 'model:']);
$provider = $options['provider'] ?? null;
$model = $options['model'] ?? null;

$crud = new DynamiqueCrud();
$service = new ReportMarkdownFormatterService($crud);

try {
    $service->format($reportId, $provider, $model);
    echo "OK: rapport $reportId formaté\n";
} catch (Throwable $e) {
    $crud->merge('company_report_contents', [
        'markdown_status' => 'failed',
        'markdown_error' => 'Erreur inattendue: ' . $e->getMessage(),
        'markdown_updated_at' => date('Y-m-d H:i:s'),
    ], ['report_id' => $reportId]);
    fwrite(STDERR, "ÉCHEC: " . $e->getMessage() . "\n");
    exit(1);
}
