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
 *   php scripts/backfill_reports.php --match-only           Fait juste le rattachement slug↔entreprise,
 *                                                           affiche la liste à vérifier manuellement, ne
 *                                                           télécharge rien.
 *   php scripts/backfill_reports.php --set-slug=SYMBOLE:slug
 *                                                           Rattache manuellement une entreprise à un slug
 *                                                           (pour résoudre les cas ambigus signalés).
 *   php scripts/backfill_reports.php --symbol=SYMBOLE       Ne traite qu'une seule entreprise.
 *   php scripts/backfill_reports.php --limit=N               Limite le nombre d'entreprises traitées (tests).
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

const REQUEST_DELAY_SECONDS = 1; // pause entre chaque requête HTTP vers brvm.org
const STORAGE_DIR = __DIR__ . '/../storage/reports';

// Mots vides retirés avant comparaison (pays, forme juridique) — évitent que deux
// entreprises de pays différents ("BANK OF AFRICA BENIN" vs "... MALI") se
// confondent sur leur seule partie commune.
const STOPWORDS = ['CI', 'COTE', 'D', 'IVOIRE', 'DIVOIRE', 'BENIN', 'BURKINA', 'FASO', 'SENEGAL', 'MALI', 'NIGER', 'TOGO', 'SA'];

// Code pays (countries.code) -> suffixe utilisé dans les slugs brvm.org
const COUNTRY_SLUG_SUFFIX = ['CI' => 'ci', 'SN' => 'sn', 'BF' => 'bf', 'BJ' => 'bn', 'TG' => 'tg', 'NE' => 'ng', 'ML' => 'ml', 'GW' => 'gw'];

function cliLog($message) {
    echo '[' . date('Y-m-d H:i:s') . "] $message" . PHP_EOL;
}

function normalizeForMatch($str) {
    $str = strtoupper($str);
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    $str = preg_replace('/[^A-Z0-9]+/', ' ', $str);
    $tokens = preg_split('/\s+/', trim($str));
    $tokens = array_filter($tokens, fn($t) => $t !== '' && !in_array($t, STOPWORDS));
    return implode(' ', $tokens);
}

/**
 * Calcule un rattachement automatique sûr (slug non ambigu, score >= 90%,
 * pas de collision avec une autre entreprise). Les cas incertains ne sont
 * PAS assignés — mieux vaut un rapport manquant qu'un rapport mal attribué.
 *
 * @return array{assignments: array<string,string>, review: array<string,array>}
 */
function computeSlugAssignments(array $companies, array $slugs) {
    $assignments = [];
    $review = [];

    foreach ($companies as $c) {
        if (!empty($c['brvm_report_slug'])) {
            continue; // déjà rattachée (auto précédemment ou manuellement)
        }

        $target = normalizeForMatch($c['full_name'] ?: $c['name']);
        $exactTier = [];
        $bestSlug = null;
        $bestScore = 0;

        foreach ($slugs as $s) {
            $candidate = normalizeForMatch($s['name']);
            if ($candidate === '') continue;

            similar_text($target, $candidate, $pct);
            if ($pct >= 90) {
                $exactTier[] = array_merge($s, ['score' => $pct]);
            }
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $bestSlug = $s['slug'];
            }
        }

        $chosen = null;
        if (count($exactTier) === 1) {
            $chosen = $exactTier[0]['slug'];
        } elseif (count($exactTier) > 1) {
            $suffix = COUNTRY_SLUG_SUFFIX[$c['country_code']] ?? null;
            $candidates = array_values(array_filter(
                $exactTier,
                fn($e) => $suffix && str_ends_with($e['slug'], "-{$suffix}")
            ));
            if (count($candidates) === 1) {
                $chosen = $candidates[0]['slug'];
            }
        }

        if ($chosen) {
            $assignments[$c['symbol']] = $chosen;
        } else {
            $review[$c['symbol']] = ['suggestion' => $bestSlug, 'score' => $bestScore];
        }
    }

    // Sécurité supplémentaire : si deux entreprises se voient assigner le même
    // slug (ex: homonymes après normalisation), on annule les deux plutôt que
    // de deviner laquelle est la bonne.
    $bySlug = [];
    foreach ($assignments as $symbol => $slug) {
        $bySlug[$slug][] = $symbol;
    }
    foreach ($bySlug as $slug => $symbols) {
        if (count($symbols) > 1) {
            foreach ($symbols as $symbol) {
                $review[$symbol] = ['suggestion' => $slug, 'score' => 100];
                unset($assignments[$symbol]);
            }
        }
    }

    return ['assignments' => $assignments, 'review' => $review];
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

// ---------------------------------------------------------------------------

$options = getopt('', ['match-only', 'set-slug:', 'symbol:', 'limit:']);

$crud = new DynamiqueCrud();

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

$result = computeSlugAssignments($companies, $slugs);

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

if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

$extractor = new PdfTextExtractor();
if (!$extractor->isAvailable()) {
    cliLog("ATTENTION: pdftotext introuvable, le texte ne sera pas extrait (installer poppler: brew install poppler)");
}

$stats = ['reports_seen' => 0, 'reports_new' => 0, 'downloaded' => 0, 'extracted' => 0, 'extracted_via_ocr' => 0, 'errors' => 0];

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
cliLog("Rapports vus: {$stats['reports_seen']} | nouveaux: {$stats['reports_new']} | téléchargés: {$stats['downloaded']} | textes extraits: {$stats['extracted']} (dont {$stats['extracted_via_ocr']} via OCR) | erreurs: {$stats['errors']}");
