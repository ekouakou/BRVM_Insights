<?php
/**
 * Reconstitue intraday_total_variation (variation totale / churn
 * intrajournalier, voir TODO_ANALYSES.md point 8) à partir de
 * intraday_quotes déjà en base — nécessaire une fois après la migration
 * 008 pour couvrir l'historique déjà accumulé avant que
 * BRVMSyncService::recordIntradaySnapshot() ne prenne le relais en continu.
 *
 * Idempotent : vide la table avant de la reconstruire depuis la source de
 * vérité (intraday_quotes), donc relançable sans risque de double comptage
 * si on veut recalculer après une correction de données.
 *
 * Usage: php scripts/backfill_total_variation.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';

$crud = new DynamiqueCrud();

echo "Vidage de intraday_total_variation...\n";
$crud->executeCustomQuery('TRUNCATE TABLE intraday_total_variation');

echo "Lecture de intraday_quotes...\n";
$rows = $crud->executeCustomQuery(
    "SELECT company_id, quote_datetime, price FROM intraday_quotes ORDER BY company_id ASC, quote_datetime ASC"
) ?: [];

echo "→ " . count($rows) . " relevé(s) à rejouer.\n";

// [company_id|trading_date] => état en cours d'accumulation
$state = [];

foreach ($rows as $row) {
    $companyId = (int) $row['company_id'];
    $price = (float) $row['price'];
    $quoteDatetime = $row['quote_datetime'];
    $tradingDate = substr($quoteDatetime, 0, 10);
    $key = $companyId . '|' . $tradingDate;

    if (!isset($state[$key])) {
        // Premier relevé du jour pour cette entreprise : accumulateur à 0,
        // même règle que BRVMSyncService::accumulateTotalVariation().
        $state[$key] = [
            'company_id' => $companyId,
            'trading_date' => $tradingDate,
            'gain' => 0.0,
            'loss' => 0.0,
            'last_price' => $price,
            'last_datetime' => $quoteDatetime,
            'count' => 1
        ];
        continue;
    }

    $s = &$state[$key];
    if ($s['last_price'] > 0) {
        $delta = ($price - $s['last_price']) / $s['last_price'] * 100;
        if ($delta > 0) {
            $s['gain'] += $delta;
        } elseif ($delta < 0) {
            $s['loss'] += abs($delta);
        }
    }
    $s['last_price'] = $price;
    $s['last_datetime'] = $quoteDatetime;
    $s['count']++;
    unset($s);
}

echo "Écriture de " . count($state) . " ligne(s) entreprise/jour...\n";

foreach ($state as $s) {
    $crud->persist('intraday_total_variation', [
        'company_id' => $s['company_id'],
        'trading_date' => $s['trading_date'],
        'total_gain_percent' => round($s['gain'], 4),
        'total_loss_percent' => round($s['loss'], 4),
        'total_variation_percent' => round($s['gain'] + $s['loss'], 4),
        'snapshots_count' => $s['count'],
        'last_price' => $s['last_price'],
        'last_quote_datetime' => $s['last_datetime']
    ]);
}

echo "Terminé.\n";
