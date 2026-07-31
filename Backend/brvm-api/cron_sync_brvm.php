<?php
/**
 * Script CRON pour synchronisation automatique BRVM
 * Fichier: cron_sync_brvm.php
 * 
 * Configuration crontab recommandée:
 *
 * Pendant les heures de marché (8:30 - 16:00), toutes les 5 minutes du lundi au vendredi:
 * (astérisque)/5 8-16 (astérisque) (astérisque) 1-5 /usr/bin/php /path/to/cron_sync_brvm.php >> /path/to/logs/sync.log 2>&1
 *
 * Alternative, toutes les 10 minutes:
 * (astérisque)/10 8-16 (astérisque) (astérisque) 1-5 /usr/bin/php /path/to/cron_sync_brvm.php >> /path/to/logs/sync.log 2>&1
 */

// Configuration
define('SCRIPT_START_TIME', microtime(true));
define('LOG_FILE', __DIR__ . '/logs/sync_cron.log');
define('LOCK_FILE', __DIR__ . '/locks/sync.lock');
define('MAX_EXECUTION_TIME', 300); // 5 minutes

// Créer les répertoires si nécessaire
@mkdir(dirname(LOG_FILE), 0755, true);
@mkdir(dirname(LOCK_FILE), 0755, true);

// Classes nécessaires
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/DbConnect.php';
require_once __DIR__ . '/class/DynamiqueCrud.php';
require_once __DIR__ . '/class/BRVMScraperFixed.php';
require_once __DIR__ . '/class/BRVMSyncService.php';
require_once __DIR__ . '/class/TechnicalIndicatorsCalculator.php';

class BRVMCronSync {
    private $crud;
    private $scraper;
    private $syncService;
    private $indicatorsCalculator;
    private $config;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
        $this->scraper = new BRVMScraperFixed();
        $this->syncService = new BRVMSyncService($this->crud, $this->scraper);
        $this->indicatorsCalculator = new TechnicalIndicatorsCalculator($this->crud);
        $this->loadConfig();
    }

    /**
     * Charge la configuration système
     */
    private function loadConfig() {
        $configs = $this->crud->findAll('system_config');
        $this->config = [];
        
        foreach ($configs as $config) {
            $this->config[$config['config_key']] = $config['config_value'];
        }
    }

    /**
     * Vérifie si une synchronisation peut être lancée maintenant.
     *
     * NB: volontairement plus permissif qu'un simple "marché ouvert" (08:30-16:00).
     * Ce script peut être déclenché une seule fois par jour, à une heure variable
     * (ex: à l'ouverture de session sur une machine allumée seulement le soir) :
     * on autorise donc toute heure à partir de l'ouverture du marché jusqu'à la
     * fin de la journée, pour ne pas rater la synchro si la machine n'est pas
     * disponible pile pendant les heures de bourse.
     *
     * On garde en revanche le blocage les jours non ouvrés (week-end) : sans ça,
     * une synchro lancée un samedi enregistrerait les derniers chiffres connus
     * (ceux de vendredi) sous la date du samedi, ce qui corromprait l'historique.
     */
    private function isMarketOpen() {
        $timezone = new DateTimeZone($this->config['timezone'] ?? 'Africa/Abidjan');
        $now = new DateTime('now', $timezone);

        $currentTime = $now->format('H:i');
        $currentDay = strtolower($now->format('l'));

        $tradingDays = explode(',', $this->config['trading_days'] ?? 'monday,tuesday,wednesday,thursday,friday');

        $marketOpen = $this->config['market_open_time'] ?? '08:30';

        $isOpenTime = ($currentTime >= $marketOpen);
        $isTradingDay = in_array($currentDay, $tradingDays);

        return $isOpenTime && $isTradingDay;
    }

    /**
     * Vérifie si une synchronisation est déjà en cours
     */
    private function isLocked() {
        if (file_exists(LOCK_FILE)) {
            $lockTime = filemtime(LOCK_FILE);
            // Si le lock a plus de 10 minutes, on considère qu'il est obsolète
            if (time() - $lockTime > 600) {
                $this->log("Lock obsolète détecté, suppression");
                unlink(LOCK_FILE);
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Crée un fichier lock
     */
    private function createLock() {
        file_put_contents(LOCK_FILE, getmypid());
    }

    /**
     * Supprime le fichier lock
     */
    private function removeLock() {
        if (file_exists(LOCK_FILE)) {
            unlink(LOCK_FILE);
        }
    }

    /**
     * Logging
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp][$level] $message" . PHP_EOL;
        
        // Log dans le fichier
        file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
        
        // Afficher aussi dans la console
        echo $logMessage;
    }

    /**
     * Enregistre un log de synchronisation à partir de statistiques de sync
     */
    private function logSyncResult($syncType, $stats, $errorMessage = null) {
        $status = $errorMessage ? 'failed' : (($stats['failed'] ?? 0) > 0 ? 'partial' : 'success');

        $logId = $this->crud->persist('sync_logs', [
            'sync_type' => $syncType,
            'sync_status' => $status,
            'records_processed' => $stats['processed'] ?? 0,
            'records_inserted' => $stats['inserted'] ?? 0,
            'records_updated' => $stats['updated'] ?? 0,
            'records_failed' => $stats['failed'] ?? 0,
            'error_message' => $errorMessage ?? (!empty($stats['errors']) ? json_encode($stats['errors']) : null),
            'started_at' => date('Y-m-d H:i:s'),
            'completed_at' => date('Y-m-d H:i:s')
        ]);

        return $logId;
    }

    /**
     * Exécute la synchronisation
     */
    public function run() {
        $this->log("========== DÉBUT SYNCHRONISATION CRON ==========");

        try {
            // Vérifier si une synchro est déjà en cours
            if ($this->isLocked()) {
                $this->log("Une synchronisation est déjà en cours, abandon", "WARNING");
                return false;
            }

            // Vérifier si le marché est ouvert
            if (!$this->isMarketOpen()) {
                $this->log("Marché fermé, pas de synchronisation nécessaire", "INFO");
                return false;
            }

            // Créer le lock
            $this->createLock();
            $this->log("Lock créé");

            $overallSuccess = true;

            // --- Cotations + entreprises ---
            try {
                $this->log("Synchronisation des cotations...");
                $quoteStats = $this->syncService->syncQuotes();
                $this->logSyncResult('quotes', $quoteStats);
                $this->log("Cotations: Traités={$quoteStats['processed']}, Insérés={$quoteStats['inserted']}, Mis à jour={$quoteStats['updated']}, Échoués={$quoteStats['failed']}");

                // Recalcule et persiste les indicateurs techniques pour les entreprises mises à jour
                $today = date('Y-m-d');
                foreach ($quoteStats['touched_company_ids'] as $companyId) {
                    $this->indicatorsCalculator->computeAndPersist($companyId, $today);
                }
                $this->log("Indicateurs techniques recalculés pour " . count($quoteStats['touched_company_ids']) . " entreprise(s)");
            } catch (Exception $e) {
                $overallSuccess = false;
                $this->logSyncResult('quotes', [], $e->getMessage());
                $this->log("ERREUR synchronisation cotations: " . $e->getMessage(), "ERROR");
            }

            // --- Indices ---
            try {
                $this->log("Synchronisation des indices...");
                $indexStats = $this->syncService->syncIndices();
                $this->logSyncResult('indices', $indexStats);
                $this->log("Indices: Traités={$indexStats['processed']}, Insérés={$indexStats['inserted']}, Mis à jour={$indexStats['updated']}, Échoués={$indexStats['failed']}");
            } catch (Exception $e) {
                $overallSuccess = false;
                $this->logSyncResult('indices', [], $e->getMessage());
                $this->log("ERREUR synchronisation indices: " . $e->getMessage(), "ERROR");
            }

            $executionTime = round(microtime(true) - SCRIPT_START_TIME, 2);
            $this->log("Temps d'exécution total: {$executionTime}s");

            // Nettoyer les anciennes données si configuré
            $this->cleanOldData();

            $this->log($overallSuccess
                ? "========== SYNCHRONISATION TERMINÉE AVEC SUCCÈS =========="
                : "========== SYNCHRONISATION TERMINÉE AVEC DES ERREURS ==========");

            return $overallSuccess;

        } catch (Exception $e) {
            $this->log("ERREUR: " . $e->getMessage(), "ERROR");
            $this->log("Trace: " . $e->getTraceAsString(), "ERROR");
            return false;

        } finally {
            // Toujours supprimer le lock
            $this->removeLock();
            $this->log("Lock supprimé");
        }
    }

    /**
     * Nettoie les anciennes données
     */
    private function cleanOldData() {
        $retentionDays = (int)($this->config['data_retention_days'] ?? 730);
        
        if ($retentionDays <= 0) {
            return; // Pas de nettoyage
        }

        $cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));
        
        $this->log("Nettoyage des données antérieures à $cutoffDate");

        // Supprimer les anciennes cotations
        $sql = "DELETE FROM stock_quotes WHERE trading_date < ?";
        $result = $this->crud->executeCustomQuery($sql, [$cutoffDate]);
        
        $this->log("Anciennes cotations supprimées");

        // Nettoyer les logs de synchro de plus de 90 jours
        $logCutoff = date('Y-m-d', strtotime('-90 days'));
        $sql = "DELETE FROM sync_logs WHERE started_at < ?";
        $this->crud->executeCustomQuery($sql, [$logCutoff]);
        
        $this->log("Anciens logs supprimés");
    }
}

// Exécution du script
$sync = new BRVMCronSync();
$success = $sync->run();

exit($success ? 0 : 1);