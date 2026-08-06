<?php
/**
 * Rejoue uniquement le rattachement entreprise (CompanySlugMatcher::matchCompanyName)
 * sur les lignes déjà extraites de market_bulletin_corporate_actions, sans
 * rappeler l'IA — utile après une correction de l'algorithme de matching
 * (voir CompanySlugMatcher.php, correction du bug "SIB" rattaché à tort à
 * SITAB au lieu de SIBC : le repli par similarité ne comparait qu'aux noms
 * complets, jamais aux symboles/tickers courts).
 *
 * Usage: php scripts/rematch_corporate_actions.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/CompanySlugMatcher.php';

$crud = new DynamiqueCrud();
$companies = $crud->executeCustomQuery(
    "SELECT c.*, co.code AS country_code FROM companies c
     LEFT JOIN countries co ON co.id = c.country_id
     WHERE c.active = 1"
) ?: [];

$rows = $crud->executeCustomQuery("SELECT id, company_id, company_name_raw, match_confidence FROM market_bulletin_corporate_actions") ?: [];

$changed = 0;
foreach ($rows as $row) {
    $match = CompanySlugMatcher::matchCompanyName($row['company_name_raw'], $companies);
    $newCompanyId = $match['company_id'] ?? null;
    $newConfidence = $match['confidence'] ?? null;

    if ($newCompanyId !== ($row['company_id'] !== null ? (int) $row['company_id'] : null) || $newConfidence !== $row['match_confidence']) {
        $crud->merge('market_bulletin_corporate_actions', [
            'company_id' => $newCompanyId,
            'match_confidence' => $newConfidence,
        ], ['id' => $row['id']]);
        $changed++;
        echo "  #{$row['id']} \"{$row['company_name_raw']}\" : company_id " . var_export($row['company_id'], true) . " -> " . var_export($newCompanyId, true) . " (confidence: {$row['match_confidence']} -> {$newConfidence})\n";
    }
}

echo "\nTerminé : $changed ligne(s) modifiée(s) sur " . count($rows) . ".\n";
