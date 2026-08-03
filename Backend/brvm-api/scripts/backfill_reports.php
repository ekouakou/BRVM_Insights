<?php
/**
 * Backfill des rapports des sociétés cotées (PDF) : découverte, téléchargement,
 * extraction de texte. Script CLI à lancer manuellement (pas dans le cron
 * quotidien) — peut prendre du temps et fait beaucoup de requêtes vers brvm.org.
 *
 * Usage:
 *   php scripts/backfill_reports.php                       Rattache les slugs manquants (matches sûrs
 *                                                           uniquement) puis scrape les rapports de toutes
 *                                                           les entreprises déjà rattachées.
 *                                                           Exemple : php scripts/backfill_reports.php
 *
 *   php scripts/backfill_reports.php --match-only           Fait juste le rattachement slug↔entreprise,
 *                                                           affiche la liste à vérifier manuellement, ne
 *                                                           télécharge rien.
 *                                                           Exemple : php scripts/backfill_reports.php --match-only
 *
 *   php scripts/backfill_reports.php --set-slug=SYMBOLE:slug
 *                                                           Rattache manuellement une entreprise à un slug
 *                                                           (pour résoudre les cas ambigus signalés).
 *                                                           Exemple : php scripts/backfill_reports.php --set-slug=BICC:bank-of-africa-ci
 *
 *   php scripts/backfill_reports.php --symbol=SYMBOLE       Ne traite qu'une seule entreprise.
 *                                                           Exemple : php scripts/backfill_reports.php --symbol=CABC
 *
 *   php scripts/backfill_reports.php --limit=N               Limite le nombre d'entreprises traitées (tests).
 *                                                           Exemple : php scripts/backfill_reports.php --limit=5
 *
 *   php scripts/backfill_reports.php --start-date=YYYY-MM-DD --end-date=YYYY-MM-DD
 *                                                           Ne télécharge/extrait que les rapports dont la date
 *                                                           (déduite du nom de fichier, ex: 20250913_...) tombe
 *                                                           dans cette période. Les deux bornes sont optionnelles
 *                                                           et peuvent être utilisées seules. Les rapports dont la
 *                                                           date n'a pas pu être déduite sont ignorés dès qu'un
 *                                                           filtre de date est actif.
 *                                                           Exemple : php scripts/backfill_reports.php --symbol=CABC --start-date=2026-01-01 --end-date=2026-12-31
 *
 *   php scripts/backfill_reports.php --stats [--symbol=SYMBOLE]
 *                                                           Affiche l'état d'avancement (déjà en base, aucune
 *                                                           requête vers brvm.org) : rapports découverts,
 *                                                           téléchargés, texte extrait, en erreur — par
 *                                                           entreprise et au total. Ne télécharge/n'extrait rien.
 *                                                           Exemple : php scripts/backfill_reports.php --stats --symbol=BOAB
 *
 *   php scripts/backfill_reports.php --stats --details [--symbol=SYMBOLE]
 *                                                           Comme --stats, mais liste en plus le titre de chaque
 *                                                           rapport par entreprise, séparé en "Traités" / "Non
 *                                                           traités". Sans --symbol, liste TOUTES les entreprises
 *                                                           (sortie potentiellement longue).
 *                                                           Exemple : php scripts/backfill_reports.php --stats --details --symbol=BOAB
 *
 * Idempotent : peut être relancé, ne re-télécharge/ré-extrait pas ce qui est déjà fait
 * (utile pour reprendre après interruption ou pour aller chercher les nouveaux rapports).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';
require_once __DIR__ . '/../class/HttpFetcher.php';
require_once __DIR__ . '/../class/BRVMReportsScraper.php';
require_once __DIR__ . '/../class/PdfTextExtractor.php';
require_once __DIR__ . '/../class/CompanySlugMatcher.php';

const REQUEST_DELAY_SECONDS = 1; // pause entre chaque requête HTTP vers brvm.org
const STORAGE_DIR = __DIR__ . '/../storage/reports';

function cliLog($message) {
    echo '[' . date('Y-m-d H:i:s') . "] $message" . PHP_EOL;
}

function inferReportType($title) {
    $t = strtoupper($title);
    if (str_contains($t, 'RAPPORT ANNUEL')) return 'annuel';
    if (str_contains($t, 'ETATS FINANCIERS') || str_contains($t, 'ÉTATS FINANCIERS')) return 'etats_financiers';
    if (str_contains($t, 'SEMESTRE') || str_contains($t, 'SEMESTRIEL')) return 'semestriel';
    if (str_contains($t, 'TRIMESTRE') || str_contains($t, 'TRIMESTRIEL')) return 'trimestriel';
    if (str_contains($t, 'COMMISSAIRE') || str_contains($t, 'CAC') || str_contains($t, 'ATTESTATION')) return 'attestation_cac';
    return 'autre';
}

function inferPublishDate($fileUrl) {
    $filename = basename(parse_url($fileUrl, PHP_URL_PATH));
    if (preg_match('/^(\d{4})(\d{2})(\d{2})_/', $filename, $m)) {
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        // Validation basique : rejette les dates aberrantes (faute de frappe sur le site)
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $date;
        }
    }
    return null;
}

/**
 * Affiche l'état d'avancement du backfill à partir de ce qui est déjà en
 * base (aucune requête vers brvm.org) : par entreprise, puis un total.
 */
function showStats(DynamiqueCrud $crud, $symbolFilter = null) {
    $sql = "
        SELECT
            c.symbol, c.name,
            COUNT(cr.id) AS total,
            SUM(cr.downloaded_at IS NOT NULL) AS downloaded,
            SUM(cr.text_extracted = 1) AS extracted,
            SUM(cr.extraction_error IS NOT NULL AND (cr.text_extracted IS NULL OR cr.text_extracted = 0)) AS errors
        FROM companies c
        LEFT JOIN company_reports cr ON cr.company_id = c.id
        WHERE c.active = 1
    ";
    $params = [];
    if ($symbolFilter) {
        $sql .= " AND c.symbol = ?";
        $params[] = strtoupper($symbolFilter);
    }
    $sql .= " GROUP BY c.id, c.symbol, c.name HAVING total > 0 ORDER BY c.symbol";

    $rows = $crud->executeCustomQuery($sql, $params) ?: [];

    if (empty($rows)) {
        cliLog("Aucun rapport en base" . ($symbolFilter ? " pour $symbolFilter" : "") . ".");
        return;
    }

    printf("%-8s %6s %12s %10s %8s %10s\n", "SYMBOLE", "TOTAL", "TELECHARGES", "EXTRAITS", "ERREURS", "EN ATTENTE");
    $totals = ['total' => 0, 'downloaded' => 0, 'extracted' => 0, 'errors' => 0];

    foreach ($rows as $row) {
        $pending = $row['total'] - $row['extracted'];
        printf(
            "%-8s %6d %12d %10d %8d %10d\n",
            $row['symbol'], $row['total'], $row['downloaded'], $row['extracted'], $row['errors'], $pending
        );
        $totals['total'] += $row['total'];
        $totals['downloaded'] += $row['downloaded'];
        $totals['extracted'] += $row['extracted'];
        $totals['errors'] += $row['errors'];
    }

    echo str_repeat('-', 60) . "\n";
    printf(
        "%-8s %6d %12d %10d %8d %10d\n",
        "TOTAL", $totals['total'], $totals['downloaded'], $totals['extracted'], $totals['errors'],
        $totals['total'] - $totals['extracted']
    );
}

/**
 * Liste, par entreprise, le titre de chaque rapport connu en base — séparé
 * en "Traités" (texte extrait) et "Non traités" (téléchargement/extraction
 * pas encore fait, ou en erreur). Complète showStats() avec le détail
 * nominatif plutôt que juste des compteurs.
 */
function showReportDetails(DynamiqueCrud $crud, $symbolFilter = null) {
    $sql = "
        SELECT c.symbol, cr.title, cr.report_type, cr.publish_date, cr.text_extracted, cr.extraction_error
        FROM company_reports cr
        JOIN companies c ON c.id = cr.company_id
        WHERE c.active = 1
    ";
    $params = [];
    if ($symbolFilter) {
        $sql .= " AND c.symbol = ?";
        $params[] = strtoupper($symbolFilter);
    }
    $sql .= " ORDER BY c.symbol, cr.publish_date DESC";

    $rows = $crud->executeCustomQuery($sql, $params) ?: [];

    if (empty($rows)) {
        cliLog("Aucun rapport en base" . ($symbolFilter ? " pour $symbolFilter" : "") . ".");
        return;
    }

    $bySymbol = [];
    foreach ($rows as $row) {
        $bySymbol[$row['symbol']][] = $row;
    }

    foreach ($bySymbol as $symbol => $reports) {
        $done = array_filter($reports, fn($r) => (int) $r['text_extracted'] === 1);
        $pending = array_filter($reports, fn($r) => (int) $r['text_extracted'] !== 1);

        echo "\n=== $symbol ===\n";

        echo "  Traités (" . count($done) . "):\n";
        foreach ($done as $r) {
            printf("    - [%s] %s : %s\n", $r['report_type'] ?: '?', $r['publish_date'] ?: '?', $r['title']);
        }

        echo "  Non traités (" . count($pending) . "):\n";
        foreach ($pending as $r) {
            $suffix = $r['extraction_error'] ? " (erreur: {$r['extraction_error']})" : " (en attente)";
            printf("    - [%s] %s : %s%s\n", $r['report_type'] ?: '?', $r['publish_date'] ?: '?', $r['title'], $suffix);
        }
    }
}

// ---------------------------------------------------------------------------

$options = getopt('', ['match-only', 'set-slug:', 'symbol:', 'limit:', 'start-date:', 'end-date:', 'stats', 'details']);

$crud = new DynamiqueCrud();

if (isset($options['stats'])) {
    showStats($crud, $options['symbol'] ?? null);
    if (isset($options['details'])) {
        showReportDetails($crud, $options['symbol'] ?? null);
    }
    exit(0);
}

if (isset($options['set-slug'])) {
    [$symbol, $slug] = array_pad(explode(':', $options['set-slug'], 2), 2, null);
    if (!$symbol || !$slug) {
        cliLog("Usage: --set-slug=SYMBOLE:slug");
        exit(1);
    }
    $company = $crud->find('companies', ['symbol' => strtoupper($symbol)]);
    if (empty($company)) {
        cliLog("Entreprise '$symbol' introuvable");
        exit(1);
    }
    $crud->merge('companies', ['brvm_report_slug' => $slug], ['id' => $company[0]['id']]);
    cliLog("OK: {$company[0]['symbol']} -> $slug");
    exit(0);
}

cliLog("=== Backfill rapports BRVM ===");

$scraper = new BRVMReportsScraper();

cliLog("Découverte de l'annuaire des sociétés (rapports)...");
$slugs = $scraper->discoverCompanySlugs();
cliLog(count($slugs) . " sociétés trouvées dans l'annuaire brvm.org");

$companies = $crud->executeCustomQuery(
    "SELECT c.*, co.code AS country_code FROM companies c
     LEFT JOIN countries co ON co.id = c.country_id
     WHERE c.active = 1 ORDER BY c.symbol"
);

$result = CompanySlugMatcher::computeSlugAssignments($companies, $slugs);

foreach ($result['assignments'] as $symbol => $slug) {
    $company = current(array_filter($companies, fn($c) => $c['symbol'] === $symbol));
    $crud->merge('companies', ['brvm_report_slug' => $slug], ['id' => $company['id']]);
    cliLog("Rattachement auto: $symbol -> $slug");
}

if (!empty($result['review'])) {
    cliLog("--- À vérifier manuellement (utiliser --set-slug=SYMBOLE:slug) ---");
    foreach ($result['review'] as $symbol => $info) {
        $suggestion = $info['suggestion'] ?? '-';
        printf("  %-6s suggestion: %-30s (%.0f%%)\n", $symbol, $suggestion, $info['score']);
    }
}

if (isset($options['match-only'])) {
    cliLog("--match-only: arrêt après le rattachement.");
    exit(0);
}

// Rechargement (pour avoir les brvm_report_slug fraîchement écrits)
$companies = $crud->executeCustomQuery(
    "SELECT * FROM companies WHERE active = 1 AND brvm_report_slug IS NOT NULL ORDER BY symbol"
);

if (isset($options['symbol'])) {
    $companies = array_values(array_filter($companies, fn($c) => $c['symbol'] === strtoupper($options['symbol'])));
}
if (isset($options['limit'])) {
    $companies = array_slice($companies, 0, (int) $options['limit']);
}

cliLog(count($companies) . " entreprise(s) avec un slug rattaché, à scraper.");

$startDate = $options['start-date'] ?? null;
$endDate = $options['end-date'] ?? null;
foreach (['start-date' => $startDate, 'end-date' => $endDate] as $flag => $value) {
    if ($value !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        cliLog("--$flag doit être au format YYYY-MM-DD");
        exit(1);
    }
}
if ($startDate || $endDate) {
    cliLog("Filtre période: " . ($startDate ?: '...') . " -> " . ($endDate ?: '...'));
}

if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

$extractor = new PdfTextExtractor();
if (!$extractor->isAvailable()) {
    cliLog("ATTENTION: pdftotext introuvable, le texte ne sera pas extrait (installer poppler: brew install poppler)");
}

$stats = ['reports_seen' => 0, 'reports_new' => 0, 'downloaded' => 0, 'extracted' => 0, 'extracted_via_ocr' => 0, 'errors' => 0, 'skipped_period' => 0];

foreach ($companies as $company) {
    cliLog("--- {$company['symbol']} ({$company['brvm_report_slug']}) ---");

    $reports = $scraper->scrapeCompanyReports($company['brvm_report_slug']);
    sleep(REQUEST_DELAY_SECONDS);

    if ($reports === false) {
        cliLog("Échec du scraping pour {$company['symbol']}, on continue");
        $stats['errors']++;
        continue;
    }

    $companyDir = STORAGE_DIR . '/' . $company['symbol'];
    if (!is_dir($companyDir)) {
        mkdir($companyDir, 0755, true);
    }

    foreach ($reports as $report) {
        if ($startDate || $endDate) {
            $publishDate = inferPublishDate($report['file_url']);
            $outOfPeriod = !$publishDate
                || ($startDate && $publishDate < $startDate)
                || ($endDate && $publishDate > $endDate);
            if ($outOfPeriod) {
                $stats['skipped_period']++;
                continue;
            }
        }

        $stats['reports_seen']++;

        $existing = $crud->find('company_reports', ['file_url' => $report['file_url']]);

        if (!empty($existing)) {
            $reportRow = $existing[0];
        } else {
            $reportId = $crud->persist('company_reports', [
                'company_id' => $company['id'],
                'report_type' => inferReportType($report['title']),
                'title' => $report['title'],
                'publish_date' => inferPublishDate($report['file_url']),
                'file_url' => $report['file_url'],
            ]);
            $reportRow = $crud->findById('company_reports', $reportId);
            $stats['reports_new']++;
        }

        // Téléchargement si pas déjà fait
        if (empty($reportRow['downloaded_at'])) {
            $filename = basename(parse_url($report['file_url'], PHP_URL_PATH));
            $localPath = $companyDir . '/' . $filename;

            $ok = $scraper->downloadFile($report['file_url'], $localPath);
            sleep(REQUEST_DELAY_SECONDS);

            if ($ok && is_file($localPath)) {
                $crud->merge('company_reports', [
                    'local_path' => $localPath,
                    'file_size' => filesize($localPath),
                    'file_hash' => hash_file('sha256', $localPath),
                    'downloaded_at' => date('Y-m-d H:i:s'),
                ], ['id' => $reportRow['id']]);
                $reportRow['local_path'] = $localPath;
                $stats['downloaded']++;
                cliLog("Téléchargé: $filename");
            } else {
                $crud->merge('company_reports', [
                    'extraction_error' => 'Échec du téléchargement',
                ], ['id' => $reportRow['id']]);
                $stats['errors']++;
                cliLog("ÉCHEC téléchargement: $filename");
                continue;
            }
        }

        // Extraction du texte si pas déjà fait
        if (empty($reportRow['text_extracted']) && !empty($reportRow['local_path']) && is_file($reportRow['local_path'])) {
            $extraction = $extractor->extract($reportRow['local_path']);

            if ($extraction['success']) {
                $existingContent = $crud->find('company_report_contents', ['report_id' => $reportRow['id']]);
                $contentData = [
                    'report_id' => $reportRow['id'],
                    'extracted_text' => $extraction['text'],
                    'char_count' => strlen($extraction['text']),
                ];

                if (!empty($existingContent)) {
                    $crud->merge('company_report_contents', $contentData, ['report_id' => $reportRow['id']]);
                } else {
                    $crud->persist('company_report_contents', $contentData);
                }

                $crud->merge('company_reports', [
                    'text_extracted' => 1,
                    'extraction_method' => $extraction['method'],
                    'extraction_error' => null,
                ], ['id' => $reportRow['id']]);
                $stats['extracted']++;
                if ($extraction['method'] === 'ocr') $stats['extracted_via_ocr']++;
                cliLog("Texte extrait ({$extraction['method']}): {$reportRow['title']}");
            } else {
                $crud->merge('company_reports', ['extraction_error' => $extraction['error']], ['id' => $reportRow['id']]);
                $stats['errors']++;
                cliLog("Échec extraction: {$reportRow['title']} ({$extraction['error']})");
            }
        }
    }
}

cliLog("=== Terminé ===");
cliLog("Rapports vus: {$stats['reports_seen']} | hors période: {$stats['skipped_period']} | nouveaux: {$stats['reports_new']} | téléchargés: {$stats['downloaded']} | textes extraits: {$stats['extracted']} (dont {$stats['extracted_via_ocr']} via OCR) | erreurs: {$stats['errors']}");
