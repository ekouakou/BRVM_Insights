<?php
/**
 * Met à jour le schéma de la base de données — utilisable en local comme sur
 * l'hébergement en ligne (cPanel : terminal SSH si dispo, ou "Cron Jobs" /
 * "Terminal" du panneau pour lancer `php scripts/migrate.php` une fois).
 *
 * Se connecte avec les identifiants de config.php (donc de .env — DB_HOST,
 * DB_NAME, DB_USER, DB_PASSWORD, APP_ENV=production sur l'hébergement en
 * ligne) : jamais de nom de base ni d'identifiants en dur ici.
 *
 * Comportement :
 *   - Base vide (aucune table `companies`) : exécute BD.sql en entier
 *     (schéma complet + données de référence), puis marque toutes les
 *     migrations connues comme déjà appliquées (BD.sql est tenu à jour avec
 *     leur contenu, les rejouer serait redondant/en erreur sur des colonnes
 *     déjà existantes).
 *   - Base déjà provisionnée : applique uniquement les fichiers de
 *     migrations/*.sql pas encore appliqués (suivi dans la table
 *     schema_migrations), dans l'ordre du nom de fichier. Relançable sans
 *     risque : une migration déjà appliquée est sautée.
 *
 * Usage:
 *   php scripts/migrate.php            Applique ce qui manque
 *   php scripts/migrate.php --status   Affiche ce qui serait fait, sans rien exécuter
 */

require_once __DIR__ . '/../config.php';

const MIGRATIONS_DIR = __DIR__ . '/../migrations';
const BD_SQL_FILE = __DIR__ . '/../BD.sql';

function out($message) {
    echo $message . PHP_EOL;
}

/**
 * Découpe un script SQL en instructions individuelles exécutables une par
 * une via PDO::exec(). Gère les blocs `DELIMITER //` ... `DELIMITER ;`
 * (procédures stockées) comme le ferait le client `mysql` : tout ce qui se
 * trouve entre les deux devient UNE seule instruction, même si son corps
 * contient des points-virgules internes.
 */
function splitSqlStatements($sql) {
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    foreach (explode("\n", $sql) as $line) {
        $trimmedLine = trim($line);

        if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmedLine, $m)) {
            $pending = trim($buffer);
            if ($pending !== '') {
                $statements[] = rtrim($pending, $delimiter);
            }
            $buffer = '';
            $delimiter = $m[1];
            continue;
        }

        if ($trimmedLine === '' || str_starts_with($trimmedLine, '--')) {
            continue;
        }

        $buffer .= $line . "\n";

        $bufferTrimmed = rtrim($buffer);
        if (str_ends_with($bufferTrimmed, $delimiter)) {
            $stmt = trim(substr($bufferTrimmed, 0, -strlen($delimiter)));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }

    $pending = trim($buffer);
    if ($pending !== '') {
        $statements[] = rtrim($pending, $delimiter);
    }

    return array_values(array_filter($statements, fn($s) => trim($s) !== ''));
}

/**
 * Retire les instructions CREATE DATABASE / USE (de BD.sql ET des fichiers
 * migrations/*.sql, certains en contiennent) : sur un hébergement mutualisé,
 * la base existe déjà sous un nom imposé par l'hébergeur (souvent préfixé,
 * ex: "monlogin_brvm_trading_app"). Un USE vers un autre nom changerait la
 * base ciblée par la CONNEXION PDO en cours pour le reste du script (la
 * frappe a été observée en test : une migration exécutant "USE
 * brvm_trading_app" a fait basculer les instructions suivantes vers cette
 * base au lieu de celle réellement configurée) — on se connecte directement
 * sur la base de DB_CONFIG (voir le DSN plus bas) et on neutralise tout
 * CREATE DATABASE/USE résiduel plutôt que de les laisser s'exécuter.
 */
function stripDatabaseStatements(array $statements) {
    return array_values(array_filter($statements, function ($stmt) {
        return !preg_match('/^\s*(CREATE DATABASE|USE)\b/i', $stmt);
    }));
}

function ensureMigrationsTable(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function appliedMigrations(PDO $pdo) {
    $rows = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    return array_flip($rows);
}

function markApplied(PDO $pdo, $migrationName) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO schema_migrations (migration) VALUES (?)");
    $stmt->execute([$migrationName]);
}

function runStatements(PDO $pdo, array $statements, $label) {
    foreach ($statements as $i => $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            out("  ✗ Échec sur l'instruction " . ($i + 1) . "/" . count($statements) . " de $label:");
            out("    " . $e->getMessage());
            out("    Instruction: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 200) . "...");
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------------------

$statusOnly = in_array('--status', $argv, true);

$dbConfig = getConfig('db');
out("=== Migration de la base de données ===");
out("Environnement : " . ENVIRONMENT);
out("Hôte : {$dbConfig['host']}:{$dbConfig['port']} — Base : {$dbConfig['dbname']}");
out("");

try {
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    out("✗ Connexion impossible : " . $e->getMessage());
    exit(1);
}

// La base est-elle vierge (jamais initialisée) ?
$tableExists = $pdo->query("SHOW TABLES LIKE 'companies'")->rowCount() > 0;

if (!$tableExists) {
    out("Base vide détectée (table 'companies' absente).");

    if (!is_file(BD_SQL_FILE)) {
        out("✗ BD.sql introuvable (" . BD_SQL_FILE . "), impossible d'initialiser.");
        exit(1);
    }

    $statements = stripDatabaseStatements(splitSqlStatements(file_get_contents(BD_SQL_FILE)));
    out("→ " . count($statements) . " instruction(s) à exécuter depuis BD.sql.");

    if ($statusOnly) {
        out("(--status : rien exécuté)");
        exit(0);
    }

    if (!runStatements($pdo, $statements, 'BD.sql')) {
        out("✗ Initialisation interrompue.");
        exit(1);
    }

    out("✓ Schéma initialisé depuis BD.sql.");

    // BD.sql contient déjà tout ce que les migrations appliqueraient : on les
    // marque comme faites pour ne pas les rejouer (elles échoueraient sur des
    // colonnes/tables déjà existantes).
    ensureMigrationsTable($pdo);
    foreach (glob(MIGRATIONS_DIR . '/*.sql') as $file) {
        markApplied($pdo, basename($file));
        out("  (migration " . basename($file) . " déjà incluse dans BD.sql, marquée comme appliquée)");
    }

    out("");
    out("=== Terminé : base initialisée avec succès ===");
    exit(0);
}

// --- Base déjà provisionnée : appliquer les migrations manquantes ---
out("Base déjà provisionnée. Vérification des migrations...");
ensureMigrationsTable($pdo);
$applied = appliedMigrations($pdo);

$migrationFiles = glob(MIGRATIONS_DIR . '/*.sql');
sort($migrationFiles, SORT_NATURAL);

$pending = array_filter($migrationFiles, fn($f) => !isset($applied[basename($f)]));

if (empty($pending)) {
    out("✓ Rien à faire — toutes les migrations sont déjà appliquées (" . count($applied) . ").");
    exit(0);
}

out("→ " . count($pending) . " migration(s) en attente : " . implode(', ', array_map('basename', $pending)));

if ($statusOnly) {
    out("(--status : rien exécuté)");
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    out("--- Application de $name ---");

    $statements = stripDatabaseStatements(splitSqlStatements(file_get_contents($file)));

    if (!runStatements($pdo, $statements, $name)) {
        out("✗ Migration interrompue à $name. Corrige le problème puis relance (les migrations déjà appliquées ne seront pas rejouées).");
        exit(1);
    }

    markApplied($pdo, $name);
    out("✓ $name appliquée.");
}

out("");
out("=== Terminé : base à jour ===");
