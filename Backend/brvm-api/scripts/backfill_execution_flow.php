<?php
/**
 * Backfill du flux d'exécution intraday (intraday_execution_flow) depuis
 * tout l'historique intraday_quotes — voir TODO_CARNET_ORDRES.md phase 1.
 *
 * Rejouable sans doublon (buildDay remplace la séance) ; la séance du jour
 * en cours est exclue tant qu'elle n'est pas close (avant 14h45) — elle est
 * servie à la volée par api_order_book.php (action execution_flow, live=1).
 *
 * Usage : php scripts/backfill_execution_flow.php [YYYY-MM-DD]
 *         (avec une date : ne consolide que cette séance)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/ExecutionFlowBuilder.php';

$onlyDate = null;
if (isset($argv[1])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
        fwrite(STDERR, "Date invalide: {$argv[1]} (attendu YYYY-MM-DD)\n");
        exit(1);
    }
    $onlyDate = $argv[1];
}

$builder = new ExecutionFlowBuilder(new DynamiqueCrud());
$results = $builder->buildPending($onlyDate);

$byDate = [];
$totalIntervals = 0;
foreach ($results as $r) {
    $byDate[$r['trading_date']] = ($byDate[$r['trading_date']] ?? 0) + $r['intervals'];
    $totalIntervals += $r['intervals'];
}
ksort($byDate);
foreach ($byDate as $date => $count) {
    echo "$date : $count intervalle(s)\n";
}
echo "Terminé : " . count($results) . " séance(s)/entreprise(s) consolidée(s), $totalIntervals intervalle(s).\n";
