<?php
/**
 * API de consultation des Bulletins Officiels de la Cote (BOC)
 * Endpoint: api_bulletins.php
 *
 * Mirror de api_reports.php mais pour les bulletins de marché quotidiens
 * (pas de dimension "entreprise" ici — un bulletin couvre toute la séance).
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
require_once 'class/BRVMBulletinsScraper.php';
require_once 'class/PdfTextExtractor.php';
AuthGuard::requireAuth();

define('BULLETINS_STORAGE_DIR', __DIR__ . '/storage/bulletins');

class BulletinsAPI {
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
                case 'list':
                    return $this->listBulletins($input);

                case 'get':
                    return $this->getBulletin($input);

                case 'stats':
                    return $this->getStats($input);

                case 'discover_new':
                    return $this->discoverNewBulletins($input);

                case 'lookup_by_date':
                    return $this->lookupByDate($input);

                case 'process':
                    return $this->processBulletin($input);

                case 'format_markdown':
                    return $this->formatMarkdown($input);

                case 'upload_replacement':
                    return $this->uploadReplacement($input);

                case 'upload_markdown':
                    return $this->uploadMarkdown($input);

                case 'download':
                    $this->downloadBulletin($input); // termine par exit(), ne renvoie jamais de JSON
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
     * Liste des bulletins (métadonnées uniquement, sans le texte complet)
     */
    private function listBulletins($input) {
        $where = [];
        $sql = "SELECT mb.*, mbc.markdown_status
                FROM market_bulletins mb
                LEFT JOIN market_bulletin_contents mbc ON mbc.bulletin_id = mb.id";
        $params = [];

        if (!empty($input['start_date'])) {
            $where[] = "publish_date >= ?";
            $params[] = $input['start_date'];
        }
        if (!empty($input['end_date'])) {
            $where[] = "publish_date <= ?";
            $params[] = $input['end_date'];
        }
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY publish_date DESC, mb.id DESC";

        $bulletins = $this->crud->executeCustomQuery($sql, $params) ?: [];

        $analysesByBulletinId = [];
        if (!empty($bulletins)) {
            $bulletinIds = array_column($bulletins, 'id');
            $placeholders = implode(',', array_fill(0, count($bulletinIds), '?'));
            $analysisRows = $this->crud->executeCustomQuery(
                "SELECT bulletin_id, COUNT(*) AS analyses_count,
                        GROUP_CONCAT(DISTINCT CONCAT(provider, '/', model) ORDER BY provider, model SEPARATOR ', ') AS analyses_models
                 FROM market_bulletin_analyses
                 WHERE bulletin_id IN ($placeholders) AND status = 'success'
                 GROUP BY bulletin_id",
                $bulletinIds
            ) ?: [];
            foreach ($analysisRows as $row) {
                $analysesByBulletinId[$row['bulletin_id']] = [
                    'count' => (int) $row['analyses_count'],
                    'models' => $row['analyses_models'] ? explode(', ', $row['analyses_models']) : [],
                ];
            }
        }

        $data = array_map(function ($b) use ($analysesByBulletinId) {
            return [
                'id' => (int) $b['id'],
                'publish_date' => $b['publish_date'],
                'title' => $b['title'],
                'file_url' => $b['file_url'],
                'file_size' => $b['file_size'] !== null ? (int) $b['file_size'] : null,
                'text_extracted' => (bool) $b['text_extracted'],
                'extraction_method' => $b['extraction_method'],
                'extraction_error' => $b['extraction_error'],
                'markdown_status' => $b['markdown_status'],
                'analyses_count' => $analysesByBulletinId[$b['id']]['count'] ?? 0,
                'analyzed_models' => $analysesByBulletinId[$b['id']]['models'] ?? [],
            ];
        }, $bulletins);

        return [
            'success' => true,
            'data' => $data,
            'count' => count($data),
        ];
    }

    /**
     * Détail d'un bulletin, avec le texte extrait (si disponible) — prêt à
     * être envoyé à un modèle d'IA pour analyse.
     */
    private function getBulletin($input) {
        $bulletinId = (int) ($input['id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("ID du bulletin requis");
        }

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);

        return [
            'success' => true,
            'data' => [
                'id' => (int) $bulletin['id'],
                'publish_date' => $bulletin['publish_date'],
                'title' => $bulletin['title'],
                'file_url' => $bulletin['file_url'],
                'file_size' => $bulletin['file_size'] !== null ? (int) $bulletin['file_size'] : null,
                'text_extracted' => (bool) $bulletin['text_extracted'],
                'extraction_method' => $bulletin['extraction_method'],
                'extraction_error' => $bulletin['extraction_error'],
                'extracted_text' => $content[0]['extracted_text'] ?? null,
                'char_count' => $content[0]['char_count'] ?? null,
                'formatted_markdown' => $content[0]['formatted_markdown'] ?? null,
                'markdown_status' => $content[0]['markdown_status'] ?? null,
                'markdown_error' => $content[0]['markdown_error'] ?? null,
            ]
        ];
    }

    /**
     * Statistiques globales de collecte.
     */
    private function getStats($input) {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(downloaded_at IS NOT NULL) AS downloaded,
                SUM(text_extracted = 1) AS extracted,
                SUM(extraction_error IS NOT NULL AND (text_extracted IS NULL OR text_extracted = 0)) AS errors,
                MIN(publish_date) AS oldest_bulletin,
                MAX(publish_date) AS newest_bulletin
            FROM market_bulletins
        ";

        $row = $this->crud->executeCustomQuery($sql)[0] ?? null;
        $total = (int) ($row['total'] ?? 0);
        $extracted = (int) ($row['extracted'] ?? 0);

        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'downloaded' => (int) ($row['downloaded'] ?? 0),
                'extracted' => $extracted,
                'errors' => (int) ($row['errors'] ?? 0),
                'pending' => $total - $extracted,
                'oldest_bulletin' => $row['oldest_bulletin'] ?? null,
                'newest_bulletin' => $row['newest_bulletin'] ?? null,
            ]
        ];
    }

    /**
     * Traite un bulletin individuellement (téléchargement si besoin, puis
     * extraction de texte) — mirror de ReportsAPI::processReport().
     */
    private function processBulletin($input) {
        $bulletinId = (int) ($input['id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("ID du bulletin requis");
        }

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé");
        }

        if (!empty($bulletin['text_extracted'])) {
            return ['success' => true, 'data' => $this->bulletinStatusPayload($bulletinId, 'success')];
        }

        if (empty($bulletin['local_path']) || !is_file($bulletin['local_path'])) {
            if (!is_dir(BULLETINS_STORAGE_DIR)) {
                mkdir(BULLETINS_STORAGE_DIR, 0755, true);
            }

            $scraper = new BRVMBulletinsScraper();
            $filename = basename(parse_url($bulletin['file_url'], PHP_URL_PATH));
            $localPath = BULLETINS_STORAGE_DIR . '/' . $filename;
            $ok = $scraper->downloadFile($bulletin['file_url'], $localPath);

            if (!$ok || !is_file($localPath)) {
                $this->crud->merge('market_bulletins', ['extraction_error' => 'Échec du téléchargement'], ['id' => $bulletinId]);
                return ['success' => true, 'data' => $this->bulletinStatusPayload($bulletinId, 'failed')];
            }

            $this->crud->merge('market_bulletins', [
                'local_path' => $localPath,
                'file_size' => filesize($localPath),
                'file_hash' => hash_file('sha256', $localPath),
                'downloaded_at' => date('Y-m-d H:i:s'),
            ], ['id' => $bulletinId]);
            $bulletin['local_path'] = $localPath;
        }

        $status = $this->extractAndPersist($bulletinId, $bulletin['local_path']);
        return ['success' => true, 'data' => $this->bulletinStatusPayload($bulletinId, $status)];
    }

    /**
     * Lance la restructuration markdown d'un bulletin en arrière-plan (voir
     * class/BulletinMarkdownFormatterService.php) : une génération complète
     * prend couramment ~2 minutes, bien au-delà du timeout FastCGI de MAMP
     * (30s), donc cette action ne fait qu'enregistrer 'processing' et
     * détacher un processus PHP séparé — elle ne renvoie jamais le résultat
     * elle-même. Le frontend récupère le résultat via l'action 'get' en
     * l'interrogeant périodiquement (markdown_status).
     *
     * IMPORTANT : ne pas utiliser `nohup` pour détacher — testé et confirmé
     * en échec sous cet environnement Apache/mod_fastcgi (« can't detach
     * from console »). Rediriger toutes les sorties de la commande englobante
     * suffit à obtenir un vrai détachement (la requête HTTP retourne
     * immédiatement sans attendre la fin du script).
     */
    private function formatMarkdown($input) {
        $bulletinId = (int) ($input['id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("ID du bulletin requis");
        }

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé");
        }
        if (empty($bulletin['text_extracted'])) {
            throw new Exception("Le texte de ce bulletin n'a pas encore été extrait");
        }

        $content = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        $currentStatus = $content[0]['markdown_status'] ?? null;
        $currentUpdatedAt = $content[0]['markdown_updated_at'] ?? null;

        // Un statut 'processing' n'est considéré "toujours en cours" que s'il a
        // été posé récemment : si le script d'arrière-plan a planté avant
        // d'atteindre son propre gestionnaire d'erreurs (ex: erreur fatale PHP
        // hors du try/catch), le statut reste bloqué à 'processing' pour
        // toujours sans ce garde-fou, empêchant toute nouvelle tentative.
        $isRecentlyProcessing = $currentStatus === 'processing'
            && $currentUpdatedAt
            && (time() - strtotime($currentUpdatedAt)) < 600;

        if ($isRecentlyProcessing) {
            // Déjà en cours : pas de nouveau spawn (évite les double-clics)
            return ['success' => true, 'data' => ['id' => $bulletinId, 'status' => 'processing']];
        }

        $this->crud->merge('market_bulletin_contents', [
            'markdown_status' => 'processing',
            'markdown_error' => null,
            'markdown_updated_at' => date('Y-m-d H:i:s'),
        ], ['bulletin_id' => $bulletinId]);

        $provider = $input['provider'] ?? 'gemini';
        $scriptPath = __DIR__ . '/scripts/format_bulletin_markdown.php';
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logPath = $logDir . '/format_bulletin_' . $bulletinId . '.log';

        // PHP_BINARY pointe vers le binaire du SAPI courant : sous Apache/mod_fastcgi
        // c'est php-cgi (pas utilisable pour un script CLI classique — STDERR/STDIN
        // n'y sont pas définis), et il peut même être VIDE (constaté le
        // 11/08/2026 : la commande détachée tentait alors d'exécuter le script
        // .php directement → « Permission denied » et statut bloqué à
        // 'processing') — d'où le repli explicite sur le binaire CLI le plus
        // récent de MAMP.
        $phpBin = PHP_BINARY;
        if (php_sapi_name() !== 'cli') {
            $cliCandidate = $phpBin !== '' ? dirname($phpBin) . '/php' : '';
            if ($cliCandidate !== '' && is_executable($cliCandidate)) {
                $phpBin = $cliCandidate;
            } else {
                $mampBinaries = glob('/Applications/MAMP/bin/php/php*/bin/php') ?: [];
                if (!empty($mampBinaries)) {
                    sort($mampBinaries);
                    $phpBin = end($mampBinaries);
                }
            }
        }
        if ($phpBin === '') {
            $phpBin = 'php';
        }

        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($scriptPath) . ' ' . $bulletinId
            . ' ' . escapeshellarg('--provider=' . $provider);
        $detached = 'sh -c ' . escapeshellarg($cmd) . ' > ' . escapeshellarg($logPath) . ' 2>&1 &';
        exec($detached);

        return ['success' => true, 'data' => ['id' => $bulletinId, 'status' => 'processing']];
    }

    /**
     * Remplace le PDF d'un bulletin par un fichier envoyé manuellement (cas
     * où le téléchargement/l'extraction automatique échoue de façon
     * persistante) puis retente l'extraction dessus. Arrive en
     * multipart/form-data (pas JSON) à cause de l'upload de fichier.
     */
    private function uploadReplacement($input) {
        $bulletinId = (int) ($_POST['id'] ?? $input['id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("ID du bulletin requis");
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Fichier PDF requis");
        }
        $originalName = $_FILES['file']['name'];
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new Exception("Le fichier doit être un PDF");
        }

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé");
        }

        if (!is_dir(BULLETINS_STORAGE_DIR)) {
            mkdir(BULLETINS_STORAGE_DIR, 0755, true);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $localPath = BULLETINS_STORAGE_DIR . '/replacement_' . $bulletinId . '_' . time() . '_' . $safeName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $localPath)) {
            throw new Exception("Échec de l'enregistrement du fichier envoyé");
        }

        $this->crud->merge('market_bulletins', [
            'local_path' => $localPath,
            'file_size' => filesize($localPath),
            'file_hash' => hash_file('sha256', $localPath),
            'downloaded_at' => date('Y-m-d H:i:s'),
            'text_extracted' => null,
            'extraction_error' => null,
        ], ['id' => $bulletinId]);

        // Le PDF change : le markdown déjà formaté (et tout ce qui en a été
        // extrait) portait sur l'ANCIEN fichier — voir
        // invalidateDerivedExtractions(). Le markdown lui-même doit être
        // reformaté depuis le nouveau texte, pas seulement ses extractions.
        $this->crud->merge('market_bulletin_contents', [
            'formatted_markdown' => null,
            'markdown_status' => null,
            'markdown_error' => null,
            'markdown_provider' => null,
            'markdown_model' => null,
            'markdown_updated_at' => null,
        ], ['bulletin_id' => $bulletinId]);
        $this->invalidateDerivedExtractions($bulletinId);

        $status = $this->extractAndPersist($bulletinId, $localPath);
        return ['success' => true, 'data' => $this->bulletinStatusPayload($bulletinId, $status)];
    }

    /**
     * Remplace le markdown formaté d'un bulletin par un fichier .md envoyé
     * manuellement (relecture/correction humaine, ou reformatage fait
     * ailleurs) — pas de nouvel appel IA. Arrive en multipart/form-data.
     */
    private function uploadMarkdown($input) {
        $bulletinId = (int) ($_POST['id'] ?? $input['id'] ?? 0);
        if (!$bulletinId) {
            throw new Exception("ID du bulletin requis");
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Fichier markdown requis");
        }

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            throw new Exception("Bulletin non trouvé");
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
        // avait échoué, aucune ligne market_bulletin_contents n'existe encore
        // pour ce bulletin — sans ce garde-fou, l'import silencieux ne faisait
        // rien (0 ligne affectée) tout en répondant "success".
        $existingContent = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
        if (!empty($existingContent)) {
            $this->crud->merge('market_bulletin_contents', $contentData, ['bulletin_id' => $bulletinId]);
        } else {
            $this->crud->persist('market_bulletin_contents', array_merge(['bulletin_id' => $bulletinId], $contentData));
        }

        // Un markdown importé manuellement EST du texte exploitable : sans ça,
        // text_extracted resterait à 0 et masquerait le bouton "Analyser" côté
        // admin (voir Bulletins.tsx) malgré un contenu utilisable.
        $this->crud->merge('market_bulletins', [
            'text_extracted' => 1,
            'extraction_error' => null,
        ], ['id' => $bulletinId]);

        // Le markdown vient de changer : toute extraction déjà faite dessus
        // (PER/rendement, opérations sur titres) porte sur l'ANCIEN texte —
        // sans invalidation, une correction resterait invisible (voir
        // invalidateDerivedExtractions()).
        $this->invalidateDerivedExtractions($bulletinId);

        return ['success' => true, 'data' => ['id' => $bulletinId, 'status' => 'success']];
    }

    /**
     * Invalide les extractions dérivées du markdown d'un bulletin — PER et
     * rendement officiels par valeur (BulletinStockMetricsService) et
     * opérations sur titres (BulletinCorporateActionsService) — devenues
     * obsolètes après une correction manuelle du markdown ou le remplacement
     * du PDF source. Ces services réutilisent leur cache dès que leur statut
     * vaut 'success', sans savoir que le texte sous-jacent a changé entre
     * temps ; sans cet appel, une correction resterait invisible dans
     * l'application (PER, dividendes, etc. continueraient d'afficher les
     * valeurs extraites de l'ancien texte). Supprime aussi les lignes déjà
     * extraites, pas seulement leur statut, pour ne pas laisser de données
     * périmées affichées comme à jour en attendant une réextraction — le
     * bulletin réapparaît alors dans les listes "en attente" de
     * CorporateActions.tsx jusqu'à ce qu'il soit réextrait.
     */
    private function invalidateDerivedExtractions($bulletinId) {
        $this->crud->merge('market_bulletin_contents', [
            'corporate_actions_status' => null,
            'corporate_actions_error' => null,
            'stock_metrics_status' => null,
            'stock_metrics_error' => null,
        ], ['bulletin_id' => $bulletinId]);
        // DynamiqueCrud::remove() ajoute un LIMIT 1 systématique (pensé pour
        // supprimer UNE ligne identifiée) : sur plusieurs dizaines de lignes
        // par bulletin, il n'en supprimerait qu'une seule — d'où un DELETE
        // direct, sans limite, ici (même correctif que dans
        // BulletinStockMetricsService/BulletinCorporateActionsService).
        $this->crud->executeCustomQuery("DELETE FROM market_bulletin_corporate_actions WHERE bulletin_id = ?", [$bulletinId]);
        $this->crud->executeCustomQuery("DELETE FROM bulletin_stock_metrics WHERE bulletin_id = ?", [$bulletinId]);
    }

    /**
     * Extrait le texte d'un PDF déjà téléchargé et persiste le résultat
     * (contenu + statut). Partagé par processBulletin() et uploadReplacement().
     */
    private function extractAndPersist($bulletinId, $localPath) {
        $extractor = new PdfTextExtractor();
        $extraction = $extractor->extract($localPath);

        if ($extraction['success']) {
            $contentData = [
                'bulletin_id' => $bulletinId,
                'extracted_text' => $extraction['text'],
                'char_count' => strlen($extraction['text']),
            ];
            $existingContent = $this->crud->find('market_bulletin_contents', ['bulletin_id' => $bulletinId]);
            if (!empty($existingContent)) {
                $this->crud->merge('market_bulletin_contents', $contentData, ['bulletin_id' => $bulletinId]);
            } else {
                $this->crud->persist('market_bulletin_contents', $contentData);
            }

            $this->crud->merge('market_bulletins', [
                'text_extracted' => 1,
                'extraction_method' => $extraction['method'],
                'extraction_error' => null,
            ], ['id' => $bulletinId]);

            return 'success';
        }

        $this->crud->merge('market_bulletins', ['extraction_error' => $extraction['error']], ['id' => $bulletinId]);
        return 'failed';
    }

    /**
     * Relit l'état à jour d'un bulletin (après process/upload_replacement)
     * pour le renvoyer au frontend.
     */
    private function bulletinStatusPayload($bulletinId, $status) {
        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);

        return [
            'id' => (int) $bulletin['id'],
            'publish_date' => $bulletin['publish_date'],
            'title' => $bulletin['title'],
            'status' => $status,
            'text_extracted' => (bool) $bulletin['text_extracted'],
            'extraction_method' => $bulletin['extraction_method'],
            'extraction_error' => $bulletin['extraction_error'],
        ];
    }

    /**
     * Sert le PDF d'un bulletin pour consultation directe (ouverture dans un
     * nouvel onglet) : le fichier local téléchargé s'il existe, sinon un
     * renvoi vers l'URL source brvm.org. Termine la requête elle-même (pas
     * de JSON) — appelé en GET depuis un lien, pas via callApi().
     */
    private function downloadBulletin($input) {
        $bulletinId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        if (!$bulletinId) {
            http_response_code(400);
            echo "ID du bulletin requis";
            exit;
        }

        $bulletin = $this->crud->findById('market_bulletins', $bulletinId);
        if (!$bulletin) {
            http_response_code(404);
            echo "Bulletin non trouvé";
            exit;
        }

        if (!empty($bulletin['local_path']) && is_file($bulletin['local_path'])) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($bulletin['local_path']) . '"');
            header('Content-Length: ' . filesize($bulletin['local_path']));
            readfile($bulletin['local_path']);
            exit;
        }

        if (!empty($bulletin['file_url'])) {
            header('Location: ' . $bulletin['file_url']);
            exit;
        }

        http_response_code(404);
        echo "Fichier introuvable (ni copie locale, ni URL source)";
        exit;
    }

    /**
     * Interroge brvm.org pour la liste actuelle des bulletins publiés et
     * ajoute en base ceux qui ne sont pas encore connus (métadonnées
     * seulement — pas de téléchargement/extraction ici, cf. bouton
     * "Traiter" par bulletin). La page ne liste que les ~10-11 derniers
     * jours de bourse (voir class/BRVMBulletinsScraper.php).
     */
    private function discoverNewBulletins($input) {
        $scraper = new BRVMBulletinsScraper();
        $bulletins = $scraper->scrapeBulletinsList();

        if ($bulletins === false) {
            throw new Exception("Échec de la récupération de la page bulletins sur brvm.org (site indisponible ?)");
        }

        $newBulletins = [];
        foreach ($bulletins as $bulletin) {
            $existing = $this->crud->find('market_bulletins', ['file_url' => $bulletin['file_url']]);
            if (!empty($existing)) {
                continue;
            }

            $publishDate = BRVMBulletinsScraper::inferPublishDate($bulletin['file_url']);
            if (!$publishDate) {
                continue; // date non déductible du nom de fichier, on ignore plutôt que de deviner
            }

            $bulletinId = $this->crud->persist('market_bulletins', [
                'publish_date' => $publishDate,
                'title' => $bulletin['title'],
                'file_url' => $bulletin['file_url'],
            ]);

            $newBulletins[] = [
                'id' => (int) $bulletinId,
                'title' => $bulletin['title'],
                'publish_date' => $publishDate,
            ];
        }

        return [
            'success' => true,
            'data' => [
                'total_on_site' => count($bulletins),
                'new_count' => count($newBulletins),
                'new_bulletins' => $newBulletins,
            ],
        ];
    }

    /**
     * Cherche le bulletin d'une date précise (hors de la fenêtre des ~10-11
     * derniers jours listée sur la page principale). Teste directement l'URL
     * boc_YYYYMMDD_2.pdf (et quelques suffixes de repli) plutôt que de
     * scraper une page — brvm.org ne propose pas de navigation par date.
     * Fiable seulement depuis ~fin 2021 (schéma d'URL différent avant, voir
     * class/BRVMBulletinsScraper.php).
     */
    private function lookupByDate($input) {
        $date = $input['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception("Date requise au format YYYY-MM-DD");
        }

        $existing = $this->crud->find('market_bulletins', ['publish_date' => $date]);
        if (!empty($existing)) {
            return [
                'success' => true,
                'data' => [
                    'found' => true,
                    'already_known' => true,
                    'bulletin' => [
                        'id' => (int) $existing[0]['id'],
                        'title' => $existing[0]['title'],
                        'publish_date' => $existing[0]['publish_date'],
                    ],
                ],
            ];
        }

        $scraper = new BRVMBulletinsScraper();
        $fileUrl = $scraper->findBulletinUrlForDate($date);

        if (!$fileUrl) {
            return [
                'success' => true,
                'data' => [
                    'found' => false,
                    'already_known' => false,
                    'bulletin' => null,
                ],
            ];
        }

        $title = BRVMBulletinsScraper::buildTitleForDate($date);
        $bulletinId = $this->crud->persist('market_bulletins', [
            'publish_date' => $date,
            'title' => $title,
            'file_url' => $fileUrl,
        ]);

        return [
            'success' => true,
            'data' => [
                'found' => true,
                'already_known' => false,
                'bulletin' => [
                    'id' => (int) $bulletinId,
                    'title' => $title,
                    'publish_date' => $date,
                ],
            ],
        ];
    }
}

// Exécution
$api = new BulletinsAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
