<?php
/**
 * Restructure en Markdown le texte d'une annonce émetteur/publication BRVM,
 * en arrière-plan — lancé détaché par api_issuer_announcements.php (action
 * format_markdown), même mécanisme que scripts/format_bulletin_markdown.php
 * (timeout FastCGI de MAMP à 30s, la génération peut dépasser).
 *
 * Usage: php scripts/format_announcement_markdown.php <announcement_id> [--provider=gemini]
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/AiClientInterface.php';
require_once __DIR__ . '/../class/AiChatClientInterface.php';
require_once __DIR__ . '/../class/GeminiClient.php';
require_once __DIR__ . '/../class/AnthropicClient.php';
require_once __DIR__ . '/../class/AnnouncementMarkdownFormatterService.php';

$announcementId = (int) ($argv[1] ?? 0);
if (!$announcementId) {
    fwrite(STDERR, "Usage: php format_announcement_markdown.php <announcement_id> [--provider=...]\n");
    exit(1);
}
$options = getopt('', ['provider::'], $optind);
$provider = $options['provider'] ?? null;

$crud = new DynamiqueCrud();
$service = new AnnouncementMarkdownFormatterService($crud);

echo "Formatage markdown de l'annonce #$announcementId" . ($provider ? " (fournisseur: $provider)" : "") . "...\n";
$service->format($announcementId, $provider);

$content = $crud->find('issuer_announcement_contents', ['announcement_id' => $announcementId]);
$status = $content[0]['markdown_status'] ?? 'inconnu';
echo "Terminé : statut = $status\n";
if ($status === 'failed') {
    echo "Erreur : " . ($content[0]['markdown_error'] ?? '?') . "\n";
    exit(1);
}
