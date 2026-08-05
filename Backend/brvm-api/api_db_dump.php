<?php
/**
 * Export ("dump") complet de la base de données — schéma + données de
 * toutes les tables et vues, en pur PHP/PDO (pas de dépendance au binaire
 * `mysqldump`, absent sur certains hébergements). Pensé pour un usage
 * ponctuel : exporter la prod pour continuer le développement en local,
 * puis réimporter facilement (voir RESET_DATABASE.md).
 *
 * Endpoint: api_db_dump.php
 *   GET ?action=download&token=...   Télécharge le dump compressé (.sql.gz),
 *                                     streamé directement (pas de fichier
 *                                     temporaire sur le serveur).
 *
 * Trois précautions notables (déjà rencontrées sur ce projet) :
 *   - Requêtes non bufferisées (MYSQL_ATTR_USE_BUFFERED_QUERY=false) : évite
 *     de charger des tables volumineuses (ex: intraday_quotes, des milliers
 *     de lignes) entièrement en mémoire avant de commencer à écrire.
 *   - flush() régulier : sur un hébergement mutualisé ou MAMP, un silence de
 *     ~30s côté serveur déclenche un timeout (fastcgi idle timeout ou
 *     max_execution_time — vécu avec l'analyse groupée de rapports). Écrire
 *     et vider le buffer de sortie en continu évite de déclencher ce
 *     garde-fou même si le dump total prend plus de 30s.
 *   - Compression gzip à la volée (deflate_init/deflate_add, zlib) : le SQL
 *     généré est très répétitif (mêmes INSERT INTO/colonnes des milliers de
 *     fois), donc très compressible — réduit nettement la taille du fichier
 *     téléchargé. Réimport : décompresser d'abord (`gunzip` ou 7-Zip/The
 *     Unarchiver), voir RESET_DATABASE.md.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'class/DbConnect.php';
require_once 'class/DynamiqueCrud.php';
require_once 'class/AuthGuard.php';
AuthGuard::requireAuth();

// Repli sur set_time_limit(0) en plus du flush régulier : couvre le cas où
// c'est max_execution_time (limite cumulée, pas d'inactivité) qui coupe,
// que le flush ne peut pas éviter à lui seul.
set_time_limit(0);
ini_set('zlib.output_compression', 'Off');
while (ob_get_level() > 0) {
    ob_end_clean();
}

class DbDumpAPI {
    private PDO $pdo;
    private const BATCH_MAX_ROWS = 100;
    private const BATCH_MAX_BYTES = 2 * 1024 * 1024; // 2 Mo — tables à colonnes LONGTEXT (rapports/bulletins)

    // Compression gzip à la volée (format .sql.gz) : le SQL est très
    // répétitif (mêmes noms de colonnes/INSERT INTO répétés des milliers de
    // fois), donc très compressible — réduit nettement la taille du fichier
    // téléchargé, important vu la volumétrie de certaines tables (rapports,
    // bulletins). deflate_init/deflate_add (zlib, PHP 7.0+) permettent de
    // produire un flux gzip valide au fil de l'eau, sans tout garder en
    // mémoire — cohérent avec le reste de cette classe (écriture batch par
    // batch pour éviter les timeouts sur un dump volumineux).
    private $deflateContext;
    private string $pendingOutput = '';

    public function __construct() {
        $dbConfig = getConfig('db');
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
    }

    public function handleRequest(): void {
        $action = $_GET['action'] ?? '';

        if ($action === 'download') {
            $this->downloadDump();
            return;
        }

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "Action non reconnue: $action"], JSON_UNESCAPED_UNICODE);
    }

    private function downloadDump(): void {
        $dbConfig = getConfig('db');
        $dbName = $dbConfig['dbname'];
        $filename = "dump_{$dbName}_" . date('Y-m-d_His') . '.sql.gz';

        $this->deflateContext = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Accel-Buffering: no'); // évite un éventuel reverse proxy qui bufferiserait tout avant d'envoyer

        $this->write("-- Dump de '$dbName' généré par api_db_dump.php le " . date('Y-m-d H:i:s') . "\n");
        $this->write("-- Réimport : voir RESET_DATABASE.md (ce fichier peut être importé tel quel,\n");
        $this->write("-- il gère lui-même la suppression préalable des tables/vues existantes)\n\n");
        $this->write("SET NAMES utf8mb4;\n");
        $this->write("SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $this->listTables();
        $views = $this->listViews();

        foreach ($views as $view) {
            $this->write("DROP VIEW IF EXISTS `$view`;\n");
        }
        foreach ($tables as $table) {
            $this->write("DROP TABLE IF EXISTS `$table`;\n");
        }
        $this->write("\n");

        foreach ($tables as $table) {
            $this->dumpTable($table);
        }
        foreach ($views as $view) {
            $this->dumpView($view);
        }

        $this->write("\nSET FOREIGN_KEY_CHECKS = 1;\n");
        $this->finish();
        exit;
    }

    /** @return string[] */
    private function listTables(): array {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $names = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $names[] = $row[0];
        }
        return $names;
    }

    /** @return string[] */
    private function listViews(): array {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        $names = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $names[] = $row[0];
        }
        return $names;
    }

    private function dumpTable(string $table): void {
        $this->write("-- ----------------------------\n-- Table : `$table`\n-- ----------------------------\n");

        $createRow = $this->pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $this->write($createRow['Create Table'] . ";\n\n");
        $this->flush();

        $columnsStmt = $this->pdo->query("SHOW COLUMNS FROM `$table`");
        $columns = [];
        while ($col = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $col['Field'];
        }
        $columnList = '`' . implode('`, `', $columns) . '`';
        $insertPrefix = "INSERT INTO `$table` ($columnList) VALUES\n";

        // Non bufferisé : on itère ligne par ligne sans jamais charger toute
        // la table en mémoire (voir constructeur).
        $stmt = $this->pdo->query("SELECT * FROM `$table`");

        $batch = [];
        $batchBytes = 0;
        $rowCount = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rowCount++;
            $values = array_map(fn($v) => $v === null ? 'NULL' : $this->pdo->quote($v), array_values($row));
            $tuple = '(' . implode(', ', $values) . ')';
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);

            if (count($batch) >= self::BATCH_MAX_ROWS || $batchBytes >= self::BATCH_MAX_BYTES) {
                $this->write($insertPrefix . implode(",\n", $batch) . ";\n");
                $this->flush();
                $batch = [];
                $batchBytes = 0;
            }
        }
        $stmt->closeCursor();

        if (!empty($batch)) {
            $this->write($insertPrefix . implode(",\n", $batch) . ";\n");
        }

        if ($rowCount === 0) {
            $this->write("-- (table vide)\n");
        }
        $this->write("\n");
        $this->flush();
    }

    private function dumpView(string $view): void {
        $this->write("-- ----------------------------\n-- Vue : `$view`\n-- ----------------------------\n");
        $createRow = $this->pdo->query("SHOW CREATE VIEW `$view`")->fetch(PDO::FETCH_ASSOC);

        // Retire la clause DEFINER=`user`@`host` : liée au compte MySQL qui a
        // créé la vue sur CETTE base précise, elle peut faire échouer
        // l'import sur un autre serveur/compte (ex: privilège SUPER requis
        // pour définir un DEFINER différent de l'utilisateur courant,
        // fréquemment absent sur un hébergement mutualisé). Sans clause
        // explicite, MySQL utilise l'utilisateur courant à la création —
        // comportement portable, équivalent pour l'usage de ce projet.
        $createSql = preg_replace('/\bDEFINER=`[^`]*`@`[^`]*`\s*/', '', $createRow['Create View']);

        $this->write($createSql . ";\n\n");
        $this->flush();
    }

    private function write(string $s): void {
        $this->pendingOutput .= $s;
    }

    /**
     * Compresse le SQL accumulé depuis le dernier flush() et l'envoie —
     * ZLIB_SYNC_FLUSH vide le compresseur sans clore le flux gzip (le
     * fichier reste valide à la toute fin seulement, voir finish()).
     */
    private function flush(): void {
        if ($this->pendingOutput !== '') {
            echo deflate_add($this->deflateContext, $this->pendingOutput, ZLIB_SYNC_FLUSH);
            $this->pendingOutput = '';
        }
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /** Clôt proprement le flux gzip (CRC32 + taille finale) — à appeler une seule fois, en tout dernier. */
    private function finish(): void {
        echo deflate_add($this->deflateContext, $this->pendingOutput, ZLIB_FINISH);
        $this->pendingOutput = '';
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}

// Exécution
$api = new DbDumpAPI();
$api->handleRequest();
