<?php
/**
 * Recalcule les nouveaux indicateurs ajoutés par la migration 011 (ADX,
 * Stochastique, ROC, OBV, VWAP — voir TODO_ANALYSES.md, point 13) pour
 * chaque ligne déjà existante de `technical_indicators`, sans toucher aux
 * colonnes déjà calculées (SMA/EMA/RSI/MACD/Bollinger/ATR sont
 * recalculées à l'identique au passage, TechnicalIndicatorsCalculator ne
 * distinguant pas "nouveau" de "déjà connu").
 *
 * Ordre chronologique croissant PAR ENTREPRISE obligatoire : l'OBV est
 * incrémental (aujourd'hui = hier + volume signé du jour), donc rejouer
 * dans le désordre produirait un OBV faux (voir
 * TechnicalIndicatorsCalculator::computeOBV()).
 *
 * Idempotent : relançable sans risque, chaque jour est un upsert
 * (merge) sur la ligne déjà existante.
 *
 * Usage: php scripts/backfill_new_indicators.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/TechnicalIndicatorsCalculator.php';

$crud = new DynamiqueCrud();
$calculator = new TechnicalIndicatorsCalculator($crud);

echo "Lecture des lignes technical_indicators existantes...\n";
$rows = $crud->executeCustomQuery(
    "SELECT company_id, trading_date FROM technical_indicators ORDER BY company_id ASC, trading_date ASC"
) ?: [];

echo "→ " . count($rows) . " ligne(s) entreprise/jour à recalculer.\n";

$done = 0;
foreach ($rows as $row) {
    $companyId = (int) $row['company_id'];
    $tradingDate = $row['trading_date'];

    if ($calculator->computeAndPersist($companyId, $tradingDate)) {
        $done++;
    }

    if ($done % 50 === 0) {
        echo "  ... $done ligne(s) traitée(s)\n";
    }
}

echo "Terminé : $done ligne(s) recalculée(s) avec ADX/Stochastique/ROC/OBV/VWAP.\n";
