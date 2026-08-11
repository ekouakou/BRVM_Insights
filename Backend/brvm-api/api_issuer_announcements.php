<?php
/**
 * API des annonces émetteurs & publications BRVM
 * Endpoint: api_issuer_announcements.php
 *
 * 3e pipeline documentaire du projet (après rapports et bulletins), même
 * enchaînement : discover (scraping des listings brvm.org, voir
 * BRVMAnnouncementsScraper::TYPES) → process (téléchargement PDF +
 * extraction texte) → format_markdown (restructuration IA en arrière-plan)
 * → analyze (analyse IA structurée, mise en cache). Le rattachement à une
 * entreprise se fait à la découverte via CompanySlugMatcher::
 * matchCompanyName() sur la colonne "Société" du listing.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();
require_once 'class/AiClientInterface.php';
require_once 'class/AiChatClientInterface.php';
require_once 'class/GeminiClient.php';
require_once 'class/AnthropicClient.php';
require_once 'class/GrokClient.php';
require_once 'class/BRVMAnnouncementsScraper.php';
require_once 'class/CompanySlugMatcher.php';
require_once 'class/PdfTextExtractor.php';
require_once 'class/IssuerAnnouncementAnalysisService.php';

define('ANNOUNCEMENTS_STORAGE_DIR', __DIR__ . '/storage/announcements');

class IssuerAnnouncementsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'types':
                    return $this->listTypes();

                case 'discover':
                    return $this->discover($input);

                case 'list':
                    return $this->listAnnouncements($input);

                case 'process':
                    return $this->processAnnouncement($input);

                case 'format_markdown':
                    return $this->formatMarkdown($input);

                case 'analyze':
                    return $this->analyze($input);

                case 'get':
                    return $this->getAnnouncement($input);

                case 'get_analysis':
                    return $this->getAnalysis($input);

                case 'download':
                    return $this->download($input);

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

    private function listTypes() {
        $types = [];
        foreach (BRVMAnnouncementsScraper::TYPES as $key => $def) {
            $types[] = [
                'key' => $key,
                'label' => $def['label'],
                'url' => $def['url'],
                // Les listings sans colonne Société ne peuvent pas être
                // rattachés à un émetteur à la découverte.
                'has_company_column' => in_array($def['parser'], ['standard', 'dividendes'], true),
            ];
        }
        return ['success' => true, 'data' => ['types' => $types]];
    }

    /**
     * Découvre les annonces récentes d'un type (ou de tous) et insère les
     * nouvelles — les file_url déjà connus sont ignorés (découverte
     * incrémentale, comme les bulletins).
     */
    private function discover($input) {
        $typeKey = $input['type'] ?? null;
        $pages = max(1, min(10, (int) ($input['pages'] ?? 2)));

        $typeKeys = $typeKey !== null && $typeKey !== ''
            ? [$typeKey]
            : array_keys(BRVMAnnouncementsScraper::TYPES);

        $scraper = new BRVMAnnouncementsScraper();

        // Entreprises actives avec code pays, pour le rattachement (même
        // requête que BulletinCorporateActionsService).
        $companies = $this->crud->executeCustomQuery(
            "SELECT c.*, co.code AS country_code FROM companies c
             LEFT JOIN countries co ON co.id = c.country_id
             WHERE c.active = 1"
        ) ?: [];

        $totalFound = 0;
        $newCount = 0;
        $newItems = [];

        foreach ($typeKeys as $key) {
            $items = $scraper->discover($key, $pages);
            $totalFound += count($items);

            foreach ($items as $item) {
                $existing = $this->crud->find('issuer_announcements', ['file_url' => $item['file_url']]);
                if (!empty($existing)) {
                    continue;
                }

                $companyId = null;
                $matchConfidence = null;
                if (!empty($item['company_name_raw'])) {
                    $match = CompanySlugMatcher::matchCompanyName($item['company_name_raw'], $companies);
                    if ($match !== null) {
                        $companyId = $match['company_id'];
                        $matchConfidence = $match['confidence'];
                    }
                }

                $id = $this->crud->persist('issuer_announcements', [
                    'announcement_type' => $key,
                    'publish_date' => $item['publish_date'],
                    'company_name_raw' => $item['company_name_raw'],
                    'company_id' => $companyId,
                    'match_confidence' => $matchConfidence,
                    'title' => mb_substr($item['title'], 0, 500),
                    'file_url' => $item['file_url'],
                ]);
                $newCount++;
                $newItems[] = ['id' => (int) $id, 'type' => $key, 'title' => $item['title'], 'publish_date' => $item['publish_date']];
            }
        }

        return [
            'success' => true,
            'data' => [
                'total_on_site' => $totalFound,
                'new_count' => $newCount,
                'new_announcements' => $newItems,
            ],
        ];
    }

    /**
     * Liste filtrable — company_id, type, période. Les statuts de
     * traitement (texte extrait, markdown, analyses) sont joints pour que
     * le frontend affiche l'état de chaque document.
     */
    private function listAnnouncements($input) {
        $conditions = [];
        $params = [];

        if (!empty($input['company_id'])) {
            $conditions[] = 'a.company_id = ?';
            $params[] = (int) $input['company_id'];
        }
        if (!empty($input['type'])) {
            $conditions[] = 'a.announcement_type = ?';
            $params[] = $input['type'];
        }
        if (!empty($input['start_date'])) {
            $conditions[] = 'a.publish_date >= ?';
            $params[] = $input['start_date'];
        }
        if (!empty($input['end_date'])) {
            $conditions[] = 'a.publish_date <= ?';
            $params[] = $input['end_date'];
        }
        // Par défaut : tout ; only_unlinked=1 pour ne voir que les annonces
        // générales du marché (avis, données éco) sans entreprise rattachée.
        if (!empty($input['only_unlinked'])) {
            $conditions[] = 'a.company_id IS NULL';
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $limit = max(1, min(500, (int) ($input['limit'] ?? 200)));

        $rows = $this->crud->executeCustomQuery(
            "SELECT a.*, c.symbol AS company_symbol, c.name AS company_name,
                    ct.markdown_status, ct.char_count,
                    (SELECT COUNT(*) FROM issuer_announcement_analyses an WHERE an.announcement_id = a.id AND an.status = 'success') AS analyses_count
             FROM issuer_announcements a
             LEFT JOIN companies c ON c.id = a.company_id
             LEFT JOIN issuer_announcement_contents ct ON ct.announcement_id = a.id
             $where
             ORDER BY a.publish_date IS NULL, a.publish_date DESC, a.id DESC
             LIMIT $limit",
            $params
        ) ?: [];

        // Ne pas renvoyer les LONGTEXT dans une liste.
        foreach ($rows as &$row) {
            unset($row['extracted_text'], $row['formatted_markdown']);
        }
        unset($row);

        return ['success' => true, 'data' => ['announcements' => $rows, 'count' => count($rows)]];
    }

    /**
     * Téléchargement du PDF + extraction du texte (même enchaînement que
     * les bulletins).
     */
    private function processAnnouncement($input) {
        $id = (int) ($input['id'] ?? 0);
        $announcement = $id ? $this->crud->findById('issuer_announcements', $id) : null;
        if (!$announcement) {
            throw new Exception("Annonce non trouvée (id=$id)");
        }

        if (!empty($announcement['text_extracted'])) {
            return ['success' => true, 'data' => ['id' => $id, 'status' => 'success']];
        }

        if (empty($announcement['local_path']) || !is_file($announcement['local_path'])) {
            if (!is_dir(ANNOUNCEMENTS_STORAGE_DIR)) {
                mkdir(ANNOUNCEMENTS_STORAGE_DIR, 0755, true);
            }
            $scraper = new BRVMAnnouncementsScraper();
            $filename = $id . '_' . basename(parse_url($announcement['file_url'], PHP_URL_PATH));
            $localPath = ANNOUNCEMENTS_STORAGE_DIR . '/' . $filename;

            if (!$scraper->downloadFile($announcement['file_url'], $localPath) || !is_file($localPath)) {
                $this->crud->merge('issuer_announcements', ['extraction_error' => 'Échec du téléchargement'], ['id' => $id]);
                return ['success' => true, 'data' => ['id' => $id, 'status' => 'failed', 'error' => 'Échec du téléchargement']];
            }

            $this->crud->merge('issuer_announcements', [
                'local_path' => $localPath,
                'file_size' => filesize($localPath),
                'file_hash' => hash_file('sha256', $localPath),
                'downloaded_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            $announcement['local_path'] = $localPath;
        }

        $extractor = new PdfTextExtractor();
        $result = $extractor->extract($announcement['local_path']);

        if (!$result['success']) {
            $this->crud->merge('issuer_announcements', ['extraction_error' => $result['error']], ['id' => $id]);
            return ['success' => true, 'data' => ['id' => $id, 'status' => 'failed', 'error' => $result['error']]];
        }

        $contentData = [
            'announcement_id' => $id,
            'extracted_text' => $result['text'],
            'char_count' => strlen($result['text']),
        ];
        $existing = $this->crud->find('issuer_announcement_contents', ['announcement_id' => $id]);
        if (!empty($existing)) {
            $this->crud->merge('issuer_announcement_contents', $contentData, ['announcement_id' => $id]);
        } else {
            $this->crud->persist('issuer_announcement_contents', $contentData);
        }

        $this->crud->merge('issuer_announcements', [
            'text_extracted' => 1,
            'extraction_method' => $result['method'],
            'extraction_error' => null,
        ], ['id' => $id]);

        return ['success' => true, 'data' => ['id' => $id, 'status' => 'success', 'char_count' => strlen($result['text'])]];
    }

    /**
     * Restructuration markdown en arrière-plan — même mécanisme détaché que
     * api_bulletins.php::formatMarkdown() (timeout FastCGI MAMP 30s, pas de
     * nohup — voir le commentaire là-bas). Le frontend interroge 'get'
     * périodiquement (markdown_status).
     */
    private function formatMarkdown($input) {
        $id = (int) ($input['id'] ?? 0);
        $announcement = $id ? $this->crud->findById('issuer_announcements', $id) : null;
        if (!$announcement) {
            throw new Exception("Annonce non trouvée (id=$id)");
        }
        if (empty($announcement['text_extracted'])) {
            throw new Exception("Le texte de cette annonce n'a pas encore été extrait");
        }

        $content = $this->crud->find('issuer_announcement_contents', ['announcement_id' => $id]);
        $currentStatus = $content[0]['markdown_status'] ?? null;
        $currentUpdatedAt = $content[0]['markdown_updated_at'] ?? null;

        $isRecentlyProcessing = $currentStatus === 'processing'
            && $currentUpdatedAt
            && (time() - strtotime($currentUpdatedAt)) < 600;

        if ($isRecentlyProcessing) {
            return ['success' => true, 'data' => ['id' => $id, 'status' => 'processing']];
        }

        $this->crud->merge('issuer_announcement_contents', [
            'markdown_status' => 'processing',
            'markdown_error' => null,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['announcement_id' => $id]);

        $provider = $input['provider'] ?? 'gemini';
        $scriptPath = __DIR__ . '/scripts/format_announcement_markdown.php';
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logPath = $logDir . '/format_announcement_' . $id . '.log';

        $phpBin = $this->resolveCliPhpBinary();

        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' ' . $id
            . ' ' . escapeshellarg('--provider=' . $provider);
        $detached = 'sh -c ' . escapeshellarg($cmd) . ' > ' . escapeshellarg($logPath) . ' 2>&1 &';
        exec($detached);

        return ['success' => true, 'data' => ['id' => $id, 'status' => 'processing']];
    }

    /**
     * Binaire PHP CLI utilisable pour un processus détaché. Sous
     * Apache/mod_fastcgi de MAMP, PHP_BINARY peut être vide ou pointer vers
     * php-cgi (constaté le 11/08/2026 : la commande détachée tentait alors
     * d'exécuter le script .php directement → « Permission denied » et
     * markdown_status bloqué à 'processing') — repli explicite sur le
     * binaire CLI le plus récent de MAMP.
     */
    private function resolveCliPhpBinary(): string {
        $phpBin = PHP_BINARY;
        if (php_sapi_name() !== 'cli') {
            $cliCandidate = $phpBin !== '' ? dirname($phpBin) . '/php' : '';
            if ($cliCandidate !== '' && is_executable($cliCandidate)) {
                return $cliCandidate;
            }
            $mampBinaries = glob('/Applications/MAMP/bin/php/php*/bin/php') ?: [];
            if (!empty($mampBinaries)) {
                sort($mampBinaries);
                return end($mampBinaries);
            }
        }
        return $phpBin !== '' ? $phpBin : 'php';
    }

    private function analyze($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("id requis");
        }
        $provider = $input['provider'] ?? null;
        $model = $input['model'] ?? null;
        $forceRefresh = !empty($input['force_refresh']);

        $service = new IssuerAnnouncementAnalysisService($this->crud);

        try {
            $result = $service->analyze($id, $provider, $model, $forceRefresh);
            return ['success' => true, 'data' => $result];
        } catch (Exception $e) {
            // Erreur fournisseur IA/réseau/config : pas un crash serveur
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function getAnalysis($input) {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            throw new Exception("id requis");
        }
        $service = new IssuerAnnouncementAnalysisService($this->crud);
        $result = $service->getLatest($id);

        if (!$result) {
            return ['success' => true, 'data' => null, 'message' => "Aucune analyse en cache pour cette annonce"];
        }
        return ['success' => true, 'data' => $result];
    }

    /**
     * Détail d'une annonce (texte extrait + markdown inclus).
     */
    private function getAnnouncement($input) {
        $id = (int) ($input['id'] ?? 0);
        $announcement = $id ? $this->crud->findById('issuer_announcements', $id) : null;
        if (!$announcement) {
            throw new Exception("Annonce non trouvée (id=$id)");
        }

        $content = $this->crud->find('issuer_announcement_contents', ['announcement_id' => $id]);
        $row = $content[0] ?? null;

        $company = $announcement['company_id'] ? $this->crud->findById('companies', (int) $announcement['company_id']) : null;

        return [
            'success' => true,
            'data' => [
                'id' => (int) $announcement['id'],
                'announcement_type' => $announcement['announcement_type'],
                'type_label' => BRVMAnnouncementsScraper::TYPES[$announcement['announcement_type']]['label'] ?? $announcement['announcement_type'],
                'publish_date' => $announcement['publish_date'],
                'company_name_raw' => $announcement['company_name_raw'],
                'company' => $company ? ['id' => (int) $company['id'], 'symbol' => $company['symbol'], 'name' => $company['name']] : null,
                'title' => $announcement['title'],
                'file_url' => $announcement['file_url'],
                'file_size' => $announcement['file_size'] !== null ? (int) $announcement['file_size'] : null,
                'text_extracted' => (bool) $announcement['text_extracted'],
                'extraction_method' => $announcement['extraction_method'],
                'extraction_error' => $announcement['extraction_error'],
                'extracted_text' => $row['extracted_text'] ?? null,
                'char_count' => isset($row['char_count']) ? (int) $row['char_count'] : null,
                'formatted_markdown' => $row['formatted_markdown'] ?? null,
                'markdown_status' => $row['markdown_status'] ?? null,
                'markdown_error' => $row['markdown_error'] ?? null,
            ],
        ];
    }

    /**
     * Consultation directe du PDF (token en query param — voir
     * apiClient.ts::reportDownloadUrl() pour le principe).
     */
    private function download($input) {
        $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        $announcement = $id ? $this->crud->findById('issuer_announcements', $id) : null;
        if (!$announcement) {
            throw new Exception("Annonce non trouvée (id=$id)");
        }
        if (empty($announcement['local_path']) || !is_file($announcement['local_path'])) {
            throw new Exception("PDF non téléchargé — utilise 'Traiter' d'abord");
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($announcement['local_path']) . '"');
        header('Content-Length: ' . filesize($announcement['local_path']));
        readfile($announcement['local_path']);
        exit;
    }
}

// Exécution
$api = new IssuerAnnouncementsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
