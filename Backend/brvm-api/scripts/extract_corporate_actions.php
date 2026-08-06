<?php
/**
 * Traite en lot l'extraction des opérations sur titres (dividendes,
 * augmentations de capital, admissions, assemblées générales...) pour tous
 * les bulletins dont le texte est disponible mais qui n'ont pas encore
 * d'extraction réussie — voir class/BulletinCorporateActionsService.php et
 * TODO_ANALYSES.md, point 12.
 *
 * Réutilise directement le service (pas api_bulletin_corporate_actions.php,
 * qui fait AuthGuard::requireAuth() en tête de fichier — incompatible avec
 * une exécution CLI).
 *
 * Usage:
 *   php scripts/extract_corporate_actions.php [--provider=gemini] [--force]
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/AiClientInterface.php';
require_once __DIR__ . '/../class/GeminiClient.php';
require_once __DIR__ . '/../class/AnthropicClient.php';
require_once __DIR__ . '/../class/GrokClient.php';
require_once __DIR__ . '/../class/CompanySlugMatcher.php';
require_once __DIR__ . '/../class/BulletinCorporateActionsService.php';

$options = getopt('', ['provider::', 'force']);
$provider = $options['provider'] ?? null;
$forceRefresh = isset($options['force']);

$crud = new DynamiqueCrud();
$service = new BulletinCorporateActionsService($crud);

$sql = $forceRefresh
    ? "SELECT b.id, b.title FROM market_bulletins b
       JOIN market_bulletin_contents c ON c.bulletin_id = b.id
       WHERE c.extracted_text IS NOT NULL AND c.extracted_text != ''
       ORDER BY b.id ASC"
    : "SELECT b.id, b.title FROM market_bulletins b
       JOIN market_bulletin_contents c ON c.bulletin_id = b.id
       WHERE (c.extracted_text IS NOT NULL AND c.extracted_text != '')
         AND (c.corporate_actions_status IS NULL OR c.corporate_actions_status != 'success')
       ORDER BY b.id ASC";

$pending = $crud->executeCustomQuery($sql) ?: [];

echo "→ " . count($pending) . " bulletin(s) à (ré)extraire" . ($provider ? " (fournisseur: $provider)" : "") . ".\n\n";

$success = 0;
$failed = 0;

foreach ($pending as $bulletin) {
    $id = $bulletin['id'];
    echo "--- Bulletin #$id ({$bulletin['title']}) ---\n";

    try {
        $result = $service->extract($id, $provider, null, $forceRefresh);
        $count = count($result['actions']);
        echo "  ✓ $count opération(s) extraite(s).\n";
        $success++;
    } catch (Exception $e) {
        echo "  ✗ Échec : " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nTerminé : $success réussi(s), $failed échoué(s).\n";
