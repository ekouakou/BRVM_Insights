<?php
if (!defined('DB_CONFIG')) {
    require_once __DIR__ . '/../config.php';
}

class DbConnect {
    protected $pdo;
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset;
    private $port;

    public function __construct() {
        $dbConfig = getConfig('db');
        $this->host = $dbConfig['host'];
        $this->dbname = $dbConfig['dbname'];
        $this->username = $dbConfig['username'];
        $this->password = $dbConfig['password'];
        $this->charset = $dbConfig['charset'];
        $this->port = $dbConfig['port'];

        $this->connect();
    }

    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset={$this->charset}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}",
            ];
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            // En production, loguer l'erreur au lieu de l'afficher
            throw new PDOException("Erreur de connexion à la base de données: " . $e->getMessage());
        }
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}
?>