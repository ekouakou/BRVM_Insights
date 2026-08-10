<?php
/**
 * API des documents complémentaires ajoutés manuellement par entreprise
 * Endpoint: api_company_documents.php
 *
 * Contrairement à company_reports (scrapé automatiquement depuis brvm.org),
 * ces documents sont uploadés manuellement — rapports détaillés publiés sur
 * le site de l'entreprise, présentations investisseurs, etc., souvent plus
 * complets que les rapports officiels résumés disponibles sur brvm.org.
 * Même principe qu'un rapport (extraction de texte + formatage markdown IA,
 * voir class/PdfTextExtractor.php et
 * class/CompanyDocumentMarkdownFormatterService.php) : servent ensuite de
 * contexte additionnel pour les analyses IA (voir
 * class/ChartAnalysisService.php et class/ReportAnalysisService.php).
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
require_once 'class/PdfTextExtractor.php';
AuthGuard::requireAuth();

define('DOCUMENTS_STORAGE_DIR', __DIR__ . '/storage/documents');

class CompanyDocumentsAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        // upload arrive en multipart/form-data (fichier joint), pas en JSON.
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'list':
                    return $this->listDocuments($input);

                case 'get':
                    return $this->getDocument($input);

                case 'upload':
                    return $this->uploadDocument($input);

                case 'format_markdown':
                    return $this->formatMarkdown($input);

                case 'delete':
                    return $this->deleteDocument($input);

                case 'download':
                    $this->downloadDocument($input); // termine par exit(), ne renvoie jamais de JSON
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
     * Liste des documents d'une entreprise (métadonnées uniquement, sans le texte complet)
     */
    private function listDocuments($input) {
        $companyId = (int) ($input['company_id'] ?? 0);
        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }

        $documents = $this->crud->find(
            'company_documents',
            ['company_id' => $companyId],
            PDO::FETCH_ASSOC,
            true,
            'uploaded_at DESC, id DESC'
        );

        $markdownStatusById = [];
        $analysesById = [];
        if (!empty($documents)) {
            $ids = array_column($documents, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->crud->executeCustomQuery(
                "SELECT document_id, markdown_status FROM company_document_contents WHERE document_id IN ($placeholders)",
                $ids
            ) ?: [];
            foreach ($rows as $row) {
                $markdownStatusById[$row['document_id']] = $row['markdown_status'];
            }

            $analysisRows = $this->crud->executeCustomQuery(
                "SELECT document_id, COUNT(*) AS analyses_count,
                        GROUP_CONCAT(DISTINCT CONCAT(provider, '/', model) ORDER BY provider, model SEPARATOR ', ') AS analyses_models
                 FROM company_document_analyses
                 WHERE document_id IN ($placeholders) AND status = 'success'
                 GROUP BY document_id",
                $ids
            ) ?: [];
            foreach ($analysisRows as $row) {
                $analysesById[$row['document_id']] = [
                    'count' => (int) $row['analyses_count'],
                    'models' => $row['analyses_models'] ? explode(', ', $row['analyses_models']) : [],
                ];
            }
        }

        $data = array_map(function ($d) use ($markdownStatusById, $analysesById) {
            return [
                'id' => (int) $d['id'],
                'company_id' => (int) $d['company_id'],
                'title' => $d['title'],
                'original_filename' => $d['original_filename'],
                'file_size' => $d['file_size'] !== null ? (int) $d['file_size'] : null,
                'uploaded_at' => $d['uploaded_at'],
                'text_extracted' => (bool) $d['text_extracted'],
                'extraction_method' => $d['extraction_method'],
                'extraction_error' => $d['extraction_error'],
                'markdown_status' => $markdownStatusById[$d['id']] ?? null,
                'analyses_count' => $analysesById[$d['id']]['count'] ?? 0,
                'analyzed_models' => $analysesById[$d['id']]['models'] ?? [],
            ];
        }, $documents);

        return [
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ];
    }

    /**
     * Détail d'un document, avec le texte extrait — prêt à être envoyé à un
     * modèle d'IA pour analyse.
     */
    private function getDocument($input) {
        $documentId = (int) ($input['id'] ?? 0);
        if (!$documentId) {
            throw new Exception("ID du document requis");
        }

        $document = $this->crud->findById('company_documents', $documentId);
        if (!$document) {
            throw new Exception("Document non trouvé");
        }

        $company = $this->crud->findById('companies', $document['company_id']);
        $content = $this->crud->find('company_document_contents', ['document_id' => $documentId]);

        return [
            'success' => true,
            'data' => [
                'id' => (int) $document['id'],
                'company' => [
                    'id' => $company['id'] ?? null,
                    'symbol' => $company['symbol'] ?? null,
                    'name' => $company['name'] ?? null,
                ],
                'title' => $document['title'],
                'original_filename' => $document['original_filename'],
                'file_size' => $document['file_size'] !== null ? (int) $document['file_size'] : null,
                'uploaded_at' => $document['uploaded_at'],
                'text_extracted' => (bool) $document['text_extracted'],
                'extraction_method' => $document['extraction_method'],
                'extraction_error' => $document['extraction_error'],
                'extracted_text' => $content[0]['extracted_text'] ?? null,
                'char_count' => $content[0]['char_count'] ?? null,
                'formatted_markdown' => $content[0]['formatted_markdown'] ?? null,
                'markdown_status' => $content[0]['markdown_status'] ?? null,
                'markdown_error' => $content[0]['markdown_error'] ?? null,
            ]
        ];
    }

    /**
     * Upload d'un nouveau document (PDF) + titre pour une entreprise —
     * extraction de texte immédiate (synchrone, contrairement au formatage
     * markdown qui se détache en arrière-plan) puisqu'elle est rapide
     * (pdftotext), pas besoin du pattern détaché ici.
     */
    private function uploadDocument($input) {
        $companyId = (int) ($_POST['company_id'] ?? $input['company_id'] ?? 0);
        $title = trim($_POST['title'] ?? $input['title'] ?? '');

        if (!$companyId) {
            throw new Exception("ID de l'entreprise requis");
        }
        if (!$title) {
            throw new Exception("Titre requis");
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Fichier PDF requis");
        }

        $originalName = $_FILES['file']['name'];
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new Exception("Le fichier doit être un PDF");
        }

        $company = $this->crud->findById('companies', $companyId);
        if (!$company) {
            throw new Exception("Entreprise non trouvée");
        }

        $companyDir = DOCUMENTS_STORAGE_DIR . '/' . $company['symbol'];
        if (!is_dir($companyDir)) {
            mkdir($companyDir, 0755, true);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $localPath = $companyDir . '/' . time() . '_' . $safeName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $localPath)) {
            throw new Exception("Échec de l'enregistrement du fichier envoyé");
        }

        $documentId = $this->crud->persist('company_documents', [
            'company_id' => $companyId,
            'title' => $title,
            'original_filename' => $originalName,
            'local_path' => $localPath,
            'file_size' => filesize($localPath),
            'file_hash' => hash_file('sha256', $localPath),
            'uploaded_at' => date('Y-m-d H:i:s'),
        ]);

        $status = $this->extractAndPersist($documentId, $localPath);

        return ['success' => true, 'data' => $this->documentStatusPayload($documentId, $status)];
    }

    /**
     * Lance la restructuration markdown d'un document en arrière-plan — même
     * pattern détaché que api_reports.php/api_bulletins.php (une génération
     * complète peut prendre plusieurs minutes, largement au-delà du timeout
     * FastCGI de MAMP).
     */
    private function formatMarkdown($input) {
        $documentId = (int) ($input['id'] ?? 0);
        if (!$documentId) {
            throw new Exception("ID du document requis");
        }

        $document = $this->crud->findById('company_documents', $documentId);
        if (!$document) {
            throw new Exception("Document non trouvé");
        }
        if (empty($document['text_extracted'])) {
            throw new Exception("Le texte de ce document n'a pas encore été extrait");
        }

        $content = $this->crud->find('company_document_contents', ['document_id' => $documentId]);
        $currentStatus = $content[0]['markdown_status'] ?? null;
        $currentUpdatedAt = $content[0]['markdown_updated_at'] ?? null;

        $isRecentlyProcessing = $currentStatus === 'processing'
            && $currentUpdatedAt
            && (time() - strtotime($currentUpdatedAt)) < 600;

        if ($isRecentlyProcessing) {
            return ['success' => true, 'data' => ['id' => $documentId, 'status' => 'processing']];
        }

        $this->crud->merge('company_document_contents', [
            'markdown_status' => 'processing',
            'markdown_error' => null,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['document_id' => $documentId]);

        $provider = $input['provider'] ?? 'gemini';
        $scriptPath = __DIR__ . '/scripts/format_company_document_markdown.php';
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logPath = $logDir . '/format_company_document_' . $documentId . '.log';

        $phpBin = PHP_BINARY;
        if (php_sapi_name() !== 'cli') {
            $cliCandidate = dirname($phpBin) . '/php';
            if (is_executable($cliCandidate)) {
                $phpBin = $cliCandidate;
            }
        }

        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' ' . $documentId
            . ' ' . escapeshellarg('--provider=' . $provider);
        $detached = 'sh -c ' . escapeshellarg($cmd) . ' > ' . escapeshellarg($logPath) . ' 2>&1 &';
        exec($detached);

        return ['success' => true, 'data' => ['id' => $documentId, 'status' => 'processing']];
    }

    /**
     * Supprime un document (ligne DB + fichier local) — retrait manuel d'une
     * ressource ajoutée manuellement, pas de corbeille.
     */
    private function deleteDocument($input) {
        $documentId = (int) ($input['id'] ?? 0);
        if (!$documentId) {
            throw new Exception("ID du document requis");
        }

        $document = $this->crud->findById('company_documents', $documentId);
        if (!$document) {
            throw new Exception("Document non trouvé");
        }

        if (!empty($document['local_path']) && is_file($document['local_path'])) {
            @unlink($document['local_path']);
        }

        $this->crud->remove('company_documents', ['id' => $documentId]);

        return ['success' => true, 'data' => ['id' => $documentId]];
    }

    /**
     * Extrait le texte d'un PDF déjà téléchargé et persiste le résultat
     * (contenu + statut) — mirror de ReportsAPI::extractAndPersist().
     */
    private function extractAndPersist($documentId, $localPath) {
        $extractor = new PdfTextExtractor();
        $extraction = $extractor->extract($localPath);

        if ($extraction['success']) {
            $this->crud->persist('company_document_contents', [
                'document_id' => $documentId,
                'extracted_text' => $extraction['text'],
                'char_count' => strlen($extraction['text']),
            ]);

            $this->crud->merge('company_documents', [
                'text_extracted' => 1,
                'extraction_method' => $extraction['method'],
                'extraction_error' => null,
            ], ['id' => $documentId]);

            return 'success';
        }

        $this->crud->merge('company_documents', ['extraction_error' => $extraction['error']], ['id' => $documentId]);
        return 'failed';
    }

    private function documentStatusPayload($documentId, $status) {
        $document = $this->crud->findById('company_documents', $documentId);

        return [
            'id' => (int) $document['id'],
            'company_id' => (int) $document['company_id'],
            'title' => $document['title'],
            'status' => $status,
            'text_extracted' => (bool) $document['text_extracted'],
            'extraction_method' => $document['extraction_method'],
            'extraction_error' => $document['extraction_error'],
        ];
    }

    /**
     * Sert le PDF d'un document pour consultation directe (nouvel onglet).
     */
    private function downloadDocument($input) {
        $documentId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        if (!$documentId) {
            http_response_code(400);
            echo "ID du document requis";
            exit;
        }

        $document = $this->crud->findById('company_documents', $documentId);
        if (!$document || empty($document['local_path']) || !is_file($document['local_path'])) {
            http_response_code(404);
            echo "Fichier introuvable";
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($document['local_path']) . '"');
        header('Content-Length: ' . filesize($document['local_path']));
        readfile($document['local_path']);
        exit;
    }
}

// Exécution
$api = new CompanyDocumentsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
