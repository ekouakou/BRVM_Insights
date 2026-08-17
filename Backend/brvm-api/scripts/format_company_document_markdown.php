<?php
/**
 * Restructure le texte extrait d'un document complémentaire d'entreprise en
 * Markdown (tableaux) via IA — voir
 * class/CompanyDocumentMarkdownFormatterService.php. Prévu pour être lancé
 * en arrière-plan par api_company_documents.php (action 'format_markdown'),
 * détaché de la requête HTTP qui l'a déclenché (une génération complète peut
 * prendre plusieurs minutes, largement au-delà du timeout FastCGI de MAMP).
 *
 * Usage:
 *   php scripts/format_company_document_markdown.php <document_id> [--provider=gemini] [--model=...]
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
require_once __DIR__ . '/../class/CompanyDocumentMarkdownFormatterService.php';

$documentId = (int) ($argv[1] ?? 0);
if (!$documentId) {
    fwrite(STDERR, "Usage: php scripts/format_company_document_markdown.php <document_id> [--provider=gemini] [--model=...]\n");
    exit(1);
}

$options = getopt('', ['provider:', 'model:']);
$provider = $options['provider'] ?? null;
$model = $options['model'] ?? null;

$crud = new DynamiqueCrud();
$service = new CompanyDocumentMarkdownFormatterService($crud);

try {
    $service->format($documentId, $provider, $model);
    echo "OK: document $documentId formaté\n";
} catch (Throwable $e) {
    $crud->merge('company_document_contents', [
        'markdown_status' => 'failed',
        'markdown_error' => 'Erreur inattendue: ' . $e->getMessage(),
        'markdown_updated_at' => date('Y-m-d H:i:s'),
    ], ['document_id' => $documentId]);
    fwrite(STDERR, "ÉCHEC: " . $e->getMessage() . "\n");
    exit(1);
}
