<?php
/**
 * API de consultation des rapports des sociétés cotées
 * Endpoint: api_reports.php
 *
 * Expose les métadonnées et le texte extrait des rapports (PDF) scrapés par
 * scripts/backfill_reports.php, pensé pour être consommé par une analyse IA
 * (résumé, extraction de chiffres clés, etc.) en plus d'un affichage classique.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
require_once 'class/HttpFetcher.php';
require_once 'class/BRVMReportsScraper.php';
require_once 'class/PdfTextExtractor.php';
AuthGuard::requireAuth();

define('REPORTS_STORAGE_DIR', __DIR__ . '/storage/reports');

class ReportsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        // Le remplacement de fichier (upload_replacement) arrive en multipart/form-data,
        // pas en JSON : l'action est alors dans $_POST plutôt que dans le corps JSON.
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'list_companies':
                    return $this->listCompaniesWithReports($input);

                case 'list':
                    return $this->listReports($input);

                case 'get':
                    return $this->getReport($input);

                case 'stats':
                    return $this->getStats($input);

                case 'backfill_progress':
                    return $this->getBackfillProgress($input);

                case 'process':
                    return $this->processReport($input);

                case 'format_markdown':
                    return $this->formatMarkdown($input);

                case 'upload_replacement':
                    return $this->uploadReplacement($input);

                case 'upload_markdown':
                    return $this->uploadMarkdown($input);

                case 'discover_new':
                    return $this->discoverNewReports($input);

                case 'download':
                    $this->downloadReport($input); // termine par exit(), ne renvoie jamais de JSON
                    return null;

                default:
                    throw new Exception("Action non reconnue: $action");
            }
        } catch (Exception $e) {
            http_response_code(500);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Entreprises pour lesquelles des rapports ont été (ou peuvent être) collectés
     */
    private function listCompaniesWithReports($input) {
        $sql = "
            SELECT
                c.id AS company_id,
                c.symbol,
                c.name,
                c.brvm_report_slug,
                COUNT(cr.id) AS reports_count,
                SUM(cr.text_extracted) AS reports_with_text,
                MAX(cr.publish_date) AS latest_report_date
            FROM companies c
            LEFT JOIN company_reports cr ON cr.company_id = c.id
            WHERE c.active = 1 AND c.brvm_report_slug IS NOT NULL
            GROUP BY c.id, c.symbol, c.name, c.brvm_report_slug
            ORDER BY c.symbol
        ";

        $data = $this->crud->executeCustomQuery($sql) ?: [];

        return [
            'success' => true,
            'data' => $data,
            'count' => count($data)
        ];
    }

    /**
     * Liste des rapports d'une entreprise (métadonnées uniquement, sans le texte complet)
     */
    private function listReports($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if (!$companyId && $symbol) {
            $company = $this->crud->find('companies', ['symbol' => $symbol]);
            if (empty($company)) {
                throw new Exception("Entreprise non trouvée");
            }
            $companyId = $company[0]['id'];
        }

        $where = ['company_id' => $companyId];
        if (!empty($input['report_type'])) {
            $where['report_type'] = $input['report_type'];
        }

        $reports = $this->crud->find(
            'company_reports',
            $where,
            PDO::FETCH_ASSOC,
            true,
            'publish_date DESC, id DESC'
        );

        $markdownStatusByReportId = [];
        $analysesByReportId = [];
        if (!empty($reports)) {
            $reportIds = array_column($reports, 'id');
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
            $markdownRows = $this->crud->executeCustomQuery(
                "SELECT report_id, markdown_status FROM company_report_contents WHERE report_id IN ($placeholders)",
                $reportIds
            ) ?: [];
            foreach ($markdownRows as $row) {
                $markdownStatusByReportId[$row['report_id']] = $row['markdown_status'];
            }

            $analysisRows = $this->crud->executeCustomQuery(
                "SELECT report_id, COUNT(*) AS analyses_count,
                        GROUP_CONCAT(DISTINCT CONCAT(provider, '/', model) ORDER BY provider, model SEPARATOR ', ') AS analyses_models
                 FROM company_report_analyses
                 WHERE report_id IN ($placeholders) AND status = 'success'
                 GROUP BY report_id",
                $reportIds
            ) ?: [];
            foreach ($analysisRows as $row) {
                $analysesByReportId[$row['report_id']] = [
                    'count' => (int) $row['analyses_count'],
                    'models' => $row['analyses_models'] ? explode(', ', $row['analyses_models']) : [],
                ];
            }
        }

        // Ne pas renvoyer local_path (détail interne) dans la liste
        $data = array_map(function ($r) use ($markdownStatusByReportId, $analysesByReportId) {
            return [
                'id' => $r['id'],
                'report_type' => $r['report_type'],
                'title' => $r['title'],
                'publish_date' => $r['publish_date'],
                'file_url' => $r['file_url'],
                'file_size' => $r['file_size'],
                'text_extracted' => (bool) $r['text_extracted'],
                'extraction_method' => $r['extraction_method'],
                'extraction_error' => $r['extraction_error'],
                'markdown_status' => $markdownStatusByReportId[$r['id']] ?? null,
                'analyses_count' => $analysesByReportId[$r['id']]['count'] ?? 0,
                'analyzed_models' => $analysesByReportId[$r['id']]['models'] ?? [],
            ];
        }, $reports);

        return [
            'success' => true,
            'data' => $data,
            'count' => count($data),
            'company_id' => $companyId
        ];
    }

    /**
     * Détail d'un rapport, avec le texte extrait (si disponible) — prêt à être
     * envoyé à un modèle d'IA pour analyse/résumé.
     */
    private function getReport($input) {
        $reportId = (int) ($input['id'] ?? 0);

        if (!$reportId) {
            throw new Exception("ID du rapport requis");
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé");
        }

        $company = $this->crud->findById('companies', $report['company_id']);

        $content = $this->crud->find('company_report_contents', ['report_id' => $reportId]);

        return [
            'success' => true,
            'data' => [
                'id' => $report['id'],
                'company' => [
                    'id' => $company['id'] ?? null,
                    'symbol' => $company['symbol'] ?? null,
                    'name' => $company['name'] ?? null,
                ],
                'report_type' => $report['report_type'],
                'title' => $report['title'],
                'publish_date' => $report['publish_date'],
                'file_url' => $report['file_url'],
                'file_size' => $report['file_size'],
                'text_extracted' => (bool) $report['text_extracted'],
                'extraction_method' => $report['extraction_method'],
                'extraction_error' => $report['extraction_error'],
                'extracted_text' => $content[0]['extracted_text'] ?? null,
                'char_count' => $content[0]['char_count'] ?? null,
                'formatted_markdown' => $content[0]['formatted_markdown'] ?? null,
                'markdown_status' => $content[0]['markdown_status'] ?? null,
                'markdown_error' => $content[0]['markdown_error'] ?? null,
            ]
        ];
    }

    /**
     * Statistiques globales de collecte (utile pour suivre l'avancement du backfill)
     */
    private function getStats($input) {
        $sql = "
            SELECT
                COUNT(*) AS total_reports,
                SUM(text_extracted) AS extracted_reports,
                SUM(downloaded_at IS NOT NULL) AS downloaded_reports,
                COUNT(DISTINCT company_id) AS companies_with_reports,
                MIN(publish_date) AS oldest_report,
                MAX(publish_date) AS newest_report
            FROM company_reports
        ";

        $stats = $this->crud->executeCustomQuery($sql);

        $byType = $this->crud->executeCustomQuery(
            "SELECT report_type, COUNT(*) AS count FROM company_reports GROUP BY report_type ORDER BY count DESC"
        );

        return [
            'success' => true,
            'data' => [
                'overview' => $stats[0] ?? null,
                'by_type' => $byType ?: []
            ]
        ];
    }

    /**
     * État d'avancement du backfill par entreprise (même requête que
     * scripts/backfill_reports.php --stats) : combien de rapports découverts,
     * téléchargés, texte extrait, en erreur. Lecture seule, ne déclenche
     * aucun scraping — utilisé par la page "Rapports" du panneau d'admin.
     */
    private function getBackfillProgress($input) {
        $symbolFilter = $input['symbol'] ?? null;

        $sql = "
            SELECT
                c.id AS company_id, c.symbol, c.name,
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

        $rows = $this->crud->executeCustomQuery($sql, $params) ?: [];

        $totals = ['total' => 0, 'downloaded' => 0, 'extracted' => 0, 'errors' => 0];
        $byCompany = array_map(function ($row) use (&$totals) {
            $pending = $row['total'] - $row['extracted'];
            $totals['total'] += (int) $row['total'];
            $totals['downloaded'] += (int) $row['downloaded'];
            $totals['extracted'] += (int) $row['extracted'];
            $totals['errors'] += (int) $row['errors'];

            return [
                'company_id' => (int) $row['company_id'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'total' => (int) $row['total'],
                'downloaded' => (int) $row['downloaded'],
                'extracted' => (int) $row['extracted'],
                'errors' => (int) $row['errors'],
                'pending' => (int) $pending,
            ];
        }, $rows);
        $totals['pending'] = $totals['total'] - $totals['extracted'];

        return [
            'success' => true,
            'data' => [
                'by_company' => $byCompany,
                'totals' => $totals,
            ]
        ];
    }

    /**
     * Traite un rapport individuellement (téléchargement si besoin, puis
     * extraction de texte) — équivalent de scripts/backfill_reports.php mais
     * ciblé sur un seul rapport, appelable depuis le panneau d'admin.
     * `success` (top-level) reflète l'appel API ; `data.status` reflète le
     * résultat métier (extraction réussie ou non), comme pour l'analyse IA.
     */
    private function processReport($input) {
        $reportId = (int) ($input['id'] ?? 0);
        if (!$reportId) {
            throw new Exception("ID du rapport requis");
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé");
        }
        $company = $this->crud->findById('companies', $report['company_id']);
        if (!$company) {
            throw new Exception("Entreprise non trouvée");
        }

        if (!empty($report['text_extracted'])) {
            return ['success' => true, 'data' => $this->reportStatusPayload($reportId, 'success')];
        }

        if (empty($report['local_path']) || !is_file($report['local_path'])) {
            $companyDir = REPORTS_STORAGE_DIR . '/' . $company['symbol'];
            if (!is_dir($companyDir)) {
                mkdir($companyDir, 0755, true);
            }

            $scraper = new BRVMReportsScraper();
            $filename = basename(parse_url($report['file_url'], PHP_URL_PATH));
            $localPath = $companyDir . '/' . $filename;
            $ok = $scraper->downloadFile($report['file_url'], $localPath);

            if (!$ok || !is_file($localPath)) {
                $this->crud->merge('company_reports', ['extraction_error' => 'Échec du téléchargement'], ['id' => $reportId]);
                return ['success' => true, 'data' => $this->reportStatusPayload($reportId, 'failed')];
            }

            $this->crud->merge('company_reports', [
                'local_path' => $localPath,
                'file_size' => filesize($localPath),
                'file_hash' => hash_file('sha256', $localPath),
                'downloaded_at' => date('Y-m-d H:i:s'),
            ], ['id' => $reportId]);
            $report['local_path'] = $localPath;
        }

        $status = $this->extractAndPersist($reportId, $report['local_path']);
        return ['success' => true, 'data' => $this->reportStatusPayload($reportId, $status)];
    }

    /**
     * Lance la restructuration markdown d'un rapport en arrière-plan (voir
     * class/ReportMarkdownFormatterService.php) : mirror exact de
     * BulletinsAPI::formatMarkdown() (voir api_bulletins.php pour le détail
     * des contraintes — génération longue, détachement sans `nohup`, binaire
     * CLI résolu depuis PHP_BINARY, garde-fou anti-statut bloqué).
     */
    private function formatMarkdown($input) {
        $reportId = (int) ($input['id'] ?? 0);
        if (!$reportId) {
            throw new Exception("ID du rapport requis");
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé");
        }
        if (empty($report['text_extracted'])) {
            throw new Exception("Le texte de ce rapport n'a pas encore été extrait");
        }

        $content = $this->crud->find('company_report_contents', ['report_id' => $reportId]);
        $currentStatus = $content[0]['markdown_status'] ?? null;
        $currentUpdatedAt = $content[0]['markdown_updated_at'] ?? null;

        $isRecentlyProcessing = $currentStatus === 'processing'
            && $currentUpdatedAt
            && (time() - strtotime($currentUpdatedAt)) < 600;

        if ($isRecentlyProcessing) {
            return ['success' => true, 'data' => ['id' => $reportId, 'status' => 'processing']];
        }

        $this->crud->merge('company_report_contents', [
            'markdown_status' => 'processing',
            'markdown_error' => null,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['report_id' => $reportId]);

        $provider = $input['provider'] ?? 'gemini';
        $scriptPath = __DIR__ . '/scripts/format_report_markdown.php';
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logPath = $logDir . '/format_report_' . $reportId . '.log';

        $phpBin = PHP_BINARY;
        if (php_sapi_name() !== 'cli') {
            $cliCandidate = dirname($phpBin) . '/php';
            if (is_executable($cliCandidate)) {
                $phpBin = $cliCandidate;
            }
        }

        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' ' . $reportId
            . ' ' . escapeshellarg('--provider=' . $provider);
        $detached = 'sh -c ' . escapeshellarg($cmd) . ' > ' . escapeshellarg($logPath) . ' 2>&1 &';
        exec($detached);

        return ['success' => true, 'data' => ['id' => $reportId, 'status' => 'processing']];
    }

    /**
     * Remplace le PDF d'un rapport par un fichier envoyé manuellement (cas où
     * le téléchargement/l'extraction automatique échoue de façon persistante,
     * ex: PDF corrompu côté brvm.org) puis retente l'extraction dessus.
     * Arrive en multipart/form-data (pas JSON) à cause de l'upload de fichier.
     */
    private function uploadReplacement($input) {
        $reportId = (int) ($_POST['id'] ?? $input['id'] ?? 0);
        if (!$reportId) {
            throw new Exception("ID du rapport requis");
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Fichier PDF requis");
        }
        $originalName = $_FILES['file']['name'];
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new Exception("Le fichier doit être un PDF");
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé");
        }
        $company = $this->crud->findById('companies', $report['company_id']);
        if (!$company) {
            throw new Exception("Entreprise non trouvée");
        }

        $companyDir = REPORTS_STORAGE_DIR . '/' . $company['symbol'];
        if (!is_dir($companyDir)) {
            mkdir($companyDir, 0755, true);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $localPath = $companyDir . '/replacement_' . $reportId . '_' . time() . '_' . $safeName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $localPath)) {
            throw new Exception("Échec de l'enregistrement du fichier envoyé");
        }

        $this->crud->merge('company_reports', [
            'local_path' => $localPath,
            'file_size' => filesize($localPath),
            'file_hash' => hash_file('sha256', $localPath),
            'downloaded_at' => date('Y-m-d H:i:s'),
            'text_extracted' => null,
            'extraction_error' => null,
        ], ['id' => $reportId]);

        $status = $this->extractAndPersist($reportId, $localPath);
        return ['success' => true, 'data' => $this->reportStatusPayload($reportId, $status)];
    }

    /**
     * Remplace le markdown formaté d'un rapport par un fichier .md envoyé
     * manuellement (relecture/correction humaine, ou reformatage fait
     * ailleurs) — pas de nouvel appel IA. Arrive en multipart/form-data.
     */
    private function uploadMarkdown($input) {
        $reportId = (int) ($_POST['id'] ?? $input['id'] ?? 0);
        if (!$reportId) {
            throw new Exception("ID du rapport requis");
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Fichier markdown requis");
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            throw new Exception("Rapport non trouvé");
        }

        $markdown = file_get_contents($_FILES['file']['tmp_name']);
        if ($markdown === false || trim($markdown) === '') {
            throw new Exception("Fichier markdown vide ou illisible");
        }

        $contentData = [
            'formatted_markdown' => $markdown,
            'markdown_status' => 'success',
            'markdown_error' => null,
            'markdown_provider' => 'manuel',
            'markdown_model' => null,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ];

        // merge() est un UPDATE pur (voir DynamiqueCrud::merge) : si l'extraction
        // avait échoué, aucune ligne company_report_contents n'existe encore pour
        // ce rapport — sans ce garde-fou, l'import silencieux ne faisait rien
        // (0 ligne affectée) tout en répondant "success".
        $existingContent = $this->crud->find('company_report_contents', ['report_id' => $reportId]);
        if (!empty($existingContent)) {
            $this->crud->merge('company_report_contents', $contentData, ['report_id' => $reportId]);
        } else {
            $this->crud->persist('company_report_contents', array_merge(['report_id' => $reportId], $contentData));
        }

        // Un markdown importé manuellement EST du texte exploitable : sans ça,
        // text_extracted resterait à 0 et masquerait les boutons "Analyser"/
        // "Comparer" côté admin malgré un contenu utilisable.
        $this->crud->merge('company_reports', [
            'text_extracted' => 1,
            'extraction_error' => null,
        ], ['id' => $reportId]);

        return ['success' => true, 'data' => ['id' => $reportId, 'status' => 'success']];
    }

    /**
     * Extrait le texte d'un PDF déjà téléchargé et persiste le résultat
     * (contenu + statut). Partagé par processReport() et uploadReplacement().
     */
    private function extractAndPersist($reportId, $localPath) {
        $extractor = new PdfTextExtractor();
        $extraction = $extractor->extract($localPath);

        if ($extraction['success']) {
            $contentData = [
                'report_id' => $reportId,
                'extracted_text' => $extraction['text'],
                'char_count' => strlen($extraction['text']),
            ];
            $existingContent = $this->crud->find('company_report_contents', ['report_id' => $reportId]);
            if (!empty($existingContent)) {
                $this->crud->merge('company_report_contents', $contentData, ['report_id' => $reportId]);
            } else {
                $this->crud->persist('company_report_contents', $contentData);
            }

            $this->crud->merge('company_reports', [
                'text_extracted' => 1,
                'extraction_method' => $extraction['method'],
                'extraction_error' => null,
            ], ['id' => $reportId]);

            return 'success';
        }

        $this->crud->merge('company_reports', ['extraction_error' => $extraction['error']], ['id' => $reportId]);
        return 'failed';
    }

    /**
     * Relit l'état à jour d'un rapport (après process/upload_replacement) pour
     * le renvoyer au frontend.
     */
    private function reportStatusPayload($reportId, $status) {
        $report = $this->crud->findById('company_reports', $reportId);
        $company = $this->crud->findById('companies', $report['company_id']);

        return [
            'id' => (int) $report['id'],
            'company_id' => (int) $company['id'],
            'symbol' => $company['symbol'],
            'report_type' => $report['report_type'],
            'title' => $report['title'],
            'status' => $status,
            'text_extracted' => (bool) $report['text_extracted'],
            'extraction_method' => $report['extraction_method'],
            'extraction_error' => $report['extraction_error'],
        ];
    }

    /**
     * Sert le PDF d'un rapport pour consultation directe (ouverture dans un
     * nouvel onglet) : le fichier local téléchargé s'il existe, sinon un
     * renvoi vers l'URL source brvm.org. Termine la requête elle-même (pas de
     * JSON) — appelé en GET depuis un lien, pas via callApi().
     */
    private function downloadReport($input) {
        $reportId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        if (!$reportId) {
            http_response_code(400);
            echo "ID du rapport requis";
            exit;
        }

        $report = $this->crud->findById('company_reports', $reportId);
        if (!$report) {
            http_response_code(404);
            echo "Rapport non trouvé";
            exit;
        }

        if (!empty($report['local_path']) && is_file($report['local_path'])) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($report['local_path']) . '"');
            header('Content-Length: ' . filesize($report['local_path']));
            readfile($report['local_path']);
            exit;
        }

        if (!empty($report['file_url'])) {
            header('Location: ' . $report['file_url']);
            exit;
        }

        http_response_code(404);
        echo "Fichier introuvable (ni copie locale, ni URL source)";
        exit;
    }

    /**
     * Interroge brvm.org pour la liste actuelle des rapports d'une entreprise
     * et ajoute en base ceux qui ne sont pas encore connus (métadonnées
     * seulement — pas de téléchargement/extraction ici, cf. bouton "Traiter"
     * par rapport). Permet de repérer les nouveaux rapports publiés sans
     * relancer tout scripts/backfill_reports.php.
     */
    private function discoverNewReports($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        $symbol = $input['symbol'] ?? '';

        if (!$companyId && !$symbol) {
            throw new Exception("ID ou symbole de l'entreprise requis");
        }

        if ($companyId) {
            $company = $this->crud->findById('companies', $companyId);
        } else {
            $rows = $this->crud->find('companies', ['symbol' => strtoupper($symbol)]);
            $company = $rows[0] ?? null;
        }
        if (!$company) {
            throw new Exception("Entreprise non trouvée");
        }
        if (empty($company['brvm_report_slug'])) {
            throw new Exception("Cette entreprise n'est pas rattachée à une page de rapports brvm.org (brvm_report_slug manquant)");
        }

        $scraper = new BRVMReportsScraper();
        $reports = $scraper->scrapeCompanyReports($company['brvm_report_slug']);

        if ($reports === false) {
            throw new Exception("Échec de la récupération de la page rapports sur brvm.org (site indisponible ?)");
        }

        $newReports = [];
        foreach ($reports as $report) {
            $existing = $this->crud->find('company_reports', ['file_url' => $report['file_url']]);
            if (!empty($existing)) {
                continue;
            }

            $reportId = $this->crud->persist('company_reports', [
                'company_id' => $company['id'],
                'report_type' => $this->inferReportType($report['title']),
                'title' => $report['title'],
                'publish_date' => $this->inferPublishDate($report['file_url']),
                'file_url' => $report['file_url'],
            ]);

            $newReports[] = [
                'id' => (int) $reportId,
                'title' => $report['title'],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'company_id' => (int) $company['id'],
                'symbol' => $company['symbol'],
                'total_on_site' => count($reports),
                'new_count' => count($newReports),
                'new_reports' => $newReports,
            ],
        ];
    }

    private function inferReportType($title) {
        $t = strtoupper($title);
        if (str_contains($t, 'RAPPORT ANNUEL')) return 'annuel';
        if (str_contains($t, 'ETATS FINANCIERS') || str_contains($t, 'ÉTATS FINANCIERS')) return 'etats_financiers';
        if (str_contains($t, 'SEMESTRE') || str_contains($t, 'SEMESTRIEL')) return 'semestriel';
        if (str_contains($t, 'TRIMESTRE') || str_contains($t, 'TRIMESTRIEL')) return 'trimestriel';
        if (str_contains($t, 'COMMISSAIRE') || str_contains($t, 'CAC') || str_contains($t, 'ATTESTATION')) return 'attestation_cac';
        return 'autre';
    }

    private function inferPublishDate($fileUrl) {
        $filename = basename(parse_url($fileUrl, PHP_URL_PATH));
        if (preg_match('/^(\d{4})(\d{2})(\d{2})_/', $filename, $m)) {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $date;
            }
        }
        return null;
    }
}

// Exécution
$api = new ReportsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
