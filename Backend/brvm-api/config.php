<?php
/**
 * Fichier de configuration principal
 * config.php
 */

// Polyfills pour fonctions PHP 8.0+ utilisées dans le code (str_contains,
// str_starts_with, str_ends_with) — certains hébergements mutualisés
// tournent encore en PHP 7.4 (EOL, voir scripts/check_hosting_requirements.php)
// alors que le développement se fait en PHP 8.x. Sans ça, la moindre
// utilisation de ces fonctions provoque une erreur fatale (500 sans message,
// display_errors étant désactivé en production) sur ces hébergements — voir
// class/CompanySlugMatcher.php pour un cas réel rencontré en production.
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// Charge les variables d'environnement depuis .env (s'il existe) — DOIT
// s'exécuter avant toute lecture de getenv() plus bas (ENVIRONMENT,
// DB_CONFIG...), sinon un .env ne peut renseigner que ce qui est lu à la
// demande (getenv() dans AnthropicClient/GeminiClient, lu après ce fichier)
// mais pas ce qui est figé ici via define(). Utile en particulier sur un
// hébergement mutualisé (cPanel) où on ne peut pas définir de vraies
// variables d'environnement serveur, seulement déposer un fichier .env à
// côté de ce script.
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Ignorer les commentaires
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (!array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
}

// Définir l'environnement (development, staging, production) — surchargeable
// par la variable d'environnement APP_ENV (voir Dockerfile/docker-compose.yml),
// sinon 'development' par défaut pour ne rien changer au fonctionnement MAMP.
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development');

// Configuration de la base de données — chaque valeur est surchargeable par
// variable d'environnement (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD),
// avec un repli sur les valeurs historiques (installation MAMP locale) quand
// ces variables ne sont pas définies. Permet à la même image Docker de
// pointer vers n'importe quelle base sans reconstruire l'image.
define('DB_CONFIG', [
    'development' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'dbname' => getenv('DB_NAME') ?: 'brvm_trading_app',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: 'root',
        'charset' => 'utf8mb4',
        'port' => (int) (getenv('DB_PORT') ?: 3306)
    ],
    'production' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'dbname' => getenv('DB_NAME') ?: 'brvm_trading_app',
        'username' => getenv('DB_USER') ?: 'votre_user_prod',
        'password' => getenv('DB_PASSWORD') ?: 'votre_password_prod',
        'charset' => 'utf8mb4',
        'port' => (int) (getenv('DB_PORT') ?: 3306)
    ]
]);

// Configuration API
define('API_CONFIG', [
    'version' => '1.0.0',
    'rate_limit' => [
        'enabled' => true,
        'max_requests_per_minute' => 100,
        'max_requests_per_hour' => 1000
    ],
    'cors' => [
        'allowed_origins' => ENVIRONMENT === 'development' ? ['*'] : ['https://votre-domaine.com'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With']
    ]
]);

// Configuration du scraping
define('SCRAPING_CONFIG', [
    'brvm_url' => 'https://www.brvm.org/fr/cours-actions/0',
    'timeout' => 30,
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
    'retry_attempts' => 3,
    'retry_delay' => 5 // secondes
]);

// Configuration du marché
define('MARKET_CONFIG', [
    'timezone' => 'Africa/Abidjan',
    'trading_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    'market_open_time' => '08:30',
    'market_close_time' => '16:00',
    // Non lu par api_brvm_sync.php ni cron_sync_brvm.php pour ce paramètre :
    // ils lisent exclusivement la table system_config (voir
    // BRVMSyncAPI::getSystemConfig()) — gardé ici pour cohérence/référence
    // uniquement. La vraie source de vérité pour "prochaine synchro" affiché
    // dans le panneau d'admin est system_config.sync_interval_minutes.
    'sync_interval_minutes' => 20,
    'data_retention_days' => 730 // 2 ans
]);

// Configuration des logs
define('LOG_CONFIG', [
    'enabled' => true,
    'level' => ENVIRONMENT === 'development' ? 'DEBUG' : 'ERROR',
    'path' => __DIR__ . '/logs/',
    'max_file_size' => 10485760, // 10 MB
    'max_files' => 10
]);

// Configuration du cache
define('CACHE_CONFIG', [
    'enabled' => true,
    'driver' => 'file', // file, redis, memcached
    'ttl' => 300, // 5 minutes par défaut
    'path' => __DIR__ . '/cache/'
]);

// Configuration de sécurité
define('SECURITY_CONFIG', [
    'enable_https_only' => ENVIRONMENT === 'production',
    'enable_api_key' => false, // Mettre à true en production
    'api_key_header' => 'X-API-Key',
    'allowed_ips' => [], // Vide = tous autorisés
    'enable_request_signing' => false
]);

// Chemins des fichiers
define('PATHS', [
    'root' => __DIR__,
    'logs' => __DIR__ . '/logs/',
    'cache' => __DIR__ . '/cache/',
    'uploads' => __DIR__ . '/uploads/',
    'temp' => __DIR__ . '/temp/'
]);

// Configuration email (pour les notifications)
define('EMAIL_CONFIG', [
    'enabled' => false,
    'driver' => 'smtp', // smtp, sendmail, mail
    'smtp_host' => 'smtp.example.com',
    'smtp_port' => 587,
    'smtp_username' => 'votre_email@example.com',
    'smtp_password' => 'votre_password',
    'smtp_encryption' => 'tls', // tls, ssl
    'from_email' => 'noreply@example.com',
    'from_name' => 'BRVM Trading App'
]);

// Configuration notifications
define('NOTIFICATION_CONFIG', [
    'enabled' => false,
    'channels' => ['email', 'webhook'],
    'alert_email' => 'admin@example.com',
    'webhook_url' => '',
    'alert_on_sync_failure' => true,
    'alert_on_data_anomaly' => true
]);

// Fonctions utilitaires
function getConfig($key) {
    $configs = [
        'db' => DB_CONFIG[ENVIRONMENT],
        'api' => API_CONFIG,
        'scraping' => SCRAPING_CONFIG,
        'market' => MARKET_CONFIG,
        'log' => LOG_CONFIG,
        'cache' => CACHE_CONFIG,
        'security' => SECURITY_CONFIG,
        'email' => EMAIL_CONFIG,
        'notification' => NOTIFICATION_CONFIG,
        'paths' => PATHS
    ];
    
    return $configs[$key] ?? null;
}

function isProduction() {
    return ENVIRONMENT === 'production';
}

function isDevelopment() {
    return ENVIRONMENT === 'development';
}

// Créer les dossiers nécessaires s'ils n'existent pas
$requiredDirs = [
    PATHS['logs'],
    PATHS['cache'],
    PATHS['uploads'],
    PATHS['temp']
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Gestion des erreurs selon l'environnement
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', PATHS['logs'] . 'php_errors.log');
}

// Fuseau horaire
date_default_timezone_set(MARKET_CONFIG['timezone']);

// Configuration session (si nécessaire)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (ENVIRONMENT === 'production') {
    ini_set('session.cookie_secure', 1);
}