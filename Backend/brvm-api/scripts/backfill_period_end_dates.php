<?php
/**
 * Re-analyse en lot tous les rapports déjà analysés avec succès pour
 * peupler le nouveau champ key_financials.period_end_date (date de fin
 * d'exercice/période couverte par les chiffres, distincte de la date de
 * publication du rapport — voir ReportAnalysisService::buildPrompt() et
 * api_fundamentals.php::resolvePeriodDate()). Sans ce backfill, les
 * graphes d'historique des fondamentaux continuent d'utiliser la date de
 * publication (repli) pour toute analyse faite avant l'ajout de ce champ.
 *
 * Réutilise directement le service (pas api_report_analysis.php, qui fait
 * AuthGuard::requireAuth() en tête de fichier — incompatible avec une
 * exécution CLI).
 *
 * Usage:
 *   php scripts/backfill_period_end_dates.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/AiClientInterface.php';
require_once __DIR__ . '/../class/AiChatClientInterface.php';
require_once __DIR__ . '/../class/GeminiClient.php';
require_once __DIR__ . '/../class/AnthropicClient.php';
require_once __DIR__ . '/../class/GrokClient.php';
require_once __DIR__ . '/../class/ReportAnalysisService.php';

$crud = new DynamiqueCrud();
$service = new ReportAnalysisService($crud);

$pending = $crud->executeCustomQuery(
    "SELECT id, report_id, provider, model FROM company_report_analyses WHERE status = 'success' ORDER BY id ASC"
) ?: [];

echo "→ " . count($pending) . " analyse(s) réussie(s) à re-générer avec period_end_date.\n\n";

$success = 0;
$failed = 0;

foreach ($pending as $analysis) {
    $reportId = (int) $analysis['report_id'];
    echo "--- Analyse #{$analysis['id']} (rapport #$reportId, {$analysis['provider']}/{$analysis['model']}) ---\n";

    try {
        $result = $service->analyze($reportId, $analysis['provider'], $analysis['model'], true);
        $periodEndDate = $result['analysis']['key_financials']['period_end_date'] ?? null;
        echo "  ✓ period_end_date = " . ($periodEndDate ?? 'null (IA n\'a pas pu la déterminer)') . "\n";
        $success++;
    } catch (Exception $e) {
        echo "  ✗ Échec : " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nTerminé : $success réussi(s), $failed échoué(s).\n";
