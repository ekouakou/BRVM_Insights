<?php
/**
 * Peuple companies.market_cap / companies.shares_outstanding (TODO_ANALYSES.md
 * point 0, prérequis bloquant plusieurs analyses) depuis
 * https://www.brvm.org/fr/capitalisations/0, et la composition de l'indice
 * BRVM-COMPOSITE (toutes les entreprises cotées, pondérées par capitalisation
 * globale — c'est la définition même de cet indice, donc directement
 * déductible de cette même page).
 *
 * Volontairement un script à part, PAS une modification de
 * class/BRVMScraperFixed.php : ces données (nombre de titres, capitalisation)
 * changent rarement — pas besoin de les rescraper à chaque synchro comme les
 * cours, donc pas de raison de faire tourner ce script dans le cron de
 * production. Relance-le ponctuellement (ex: une fois par trimestre, ou après
 * une opération sur titres) plutôt qu'automatiquement.
 *
 * N'a PAS pu déterminer la composition de BRVM-30/PRESTIGE/PRINCIPAL
 * (répartition par compartiment) : aucune page BRVM trouvée listant ces
 * constituants avec poids sous une forme simple à scraper — seule la
 * composition de BRVM-COMPOSITE (= toutes les sociétés cotées) est déduite
 * ici. Voir TODO_ANALYSES.md point 10.
 *
 * Usage: php scripts/populate_market_cap.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';

function fetchHtml($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BRVMInsightsBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($html === false) {
        throw new Exception("Échec du téléchargement de $url : $error");
    }

    return $html;
}

/**
 * Parse le tableau "Code obligation / Nom / Nombre de titres / Cours du
 * jour / Capitalisation flottante / Capitalisation globale / Capitalisation
 * globale (%)" de la page capitalisations — identifié par son en-tête, pas
 * par sa position, pour rester robuste si la page change de structure.
 */
function parseCapitalizationsTable($html) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $tables = $xpath->query("//table[contains(@class, 'table-hover')]");

    foreach ($tables as $table) {
        $headerText = $table->textContent;
        if (strpos($headerText, 'Nombre de titres') === false || strpos($headerText, 'Capitalisation globale') === false) {
            continue;
        }

        $rows = $xpath->query(".//tbody/tr", $table);
        $result = [];

        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 7) {
                continue;
            }

            $symbol = trim($cells->item(0)->textContent);
            $sharesOutstanding = parseFrenchNumber($cells->item(2)->textContent);
            $marketCap = parseFrenchNumber($cells->item(5)->textContent);
            $weightPercent = parseFrenchNumber($cells->item(6)->textContent);

            if ($symbol === '') {
                continue;
            }

            $result[] = [
                'symbol' => $symbol,
                'shares_outstanding' => $sharesOutstanding,
                'market_cap' => $marketCap,
                'weight_percent' => $weightPercent,
            ];
        }

        return $result;
    }

    throw new Exception("Tableau des capitalisations introuvable dans la page (structure modifiée ?)");
}

/** "10 912 000" -> 10912000.0 ; "0,17" -> 0.17 (espaces = séparateur de milliers, virgule = décimale) */
function parseFrenchNumber($text) {
    $clean = str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], trim($text));
    return $clean === '' ? null : (float) $clean;
}

echo "Téléchargement de https://www.brvm.org/fr/capitalisations/0...\n";
$html = fetchHtml('https://www.brvm.org/fr/capitalisations/0');

echo "Analyse du tableau...\n";
$rows = parseCapitalizationsTable($html);
echo "→ " . count($rows) . " entreprise(s) trouvée(s).\n";

$crud = new DynamiqueCrud();

$compositeIndex = $crud->find('market_indices', ['code' => 'BRVM-COMPOSITE']);
if (empty($compositeIndex)) {
    throw new Exception("Indice BRVM-COMPOSITE introuvable dans market_indices");
}
$compositeIndexId = $compositeIndex[0]['id'];

$updated = 0;
$compositionRows = 0;
$notFound = [];

foreach ($rows as $row) {
    $company = $crud->find('companies', ['symbol' => $row['symbol']]);

    if (empty($company)) {
        $notFound[] = $row['symbol'];
        continue;
    }

    $companyId = $company[0]['id'];

    $crud->merge('companies', [
        'shares_outstanding' => $row['shares_outstanding'],
        'market_cap' => $row['market_cap'],
    ], ['id' => $companyId]);
    $updated++;

    $existingComposition = $crud->find('index_composition', [
        'index_id' => $compositeIndexId,
        'company_id' => $companyId,
    ]);

    $compositionData = [
        'index_id' => $compositeIndexId,
        'company_id' => $companyId,
        'weight' => $row['weight_percent'],
        'active' => 1,
    ];

    if (!empty($existingComposition)) {
        $crud->merge('index_composition', $compositionData, ['id' => $existingComposition[0]['id']]);
    } else {
        $compositionData['entry_date'] = date('Y-m-d');
        $crud->persist('index_composition', $compositionData);
    }
    $compositionRows++;
}

echo "✓ $updated entreprise(s) mise(s) à jour (market_cap, shares_outstanding).\n";
echo "✓ $compositionRows ligne(s) de composition BRVM-COMPOSITE écrite(s).\n";

if (!empty($notFound)) {
    echo "⚠ Symboles non trouvés en base (ignorés) : " . implode(', ', $notFound) . "\n";
}

echo "Terminé.\n";
