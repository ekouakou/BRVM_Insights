<?php
/**
 * API de vérification de version de l'application "Parent Responsable"
 * (com.groupegain.parents_responsable) — consultée par l'app au démarrage
 * pour savoir si une mise à jour est disponible/obligatoire.
 *
 * Endpoint public (pas d'authentification) : consultée par l'app avant
 * connexion. Source de données : version.json (même dossier), à éditer
 * manuellement à chaque publication sur les stores.
 *
 * Usage:
 *   GET api_version.php                 -> toutes les plateformes
 *   GET api_version.php?platform=ios    -> uniquement la plateforme demandée
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dataFile = __DIR__ . '/version.json';
$versions = json_decode(file_get_contents($dataFile), true) ?: [];

$platform = isset($_GET['platform']) ? strtolower(trim($_GET['platform'])) : null;

if ($platform === null) {
    echo json_encode(['success' => true, 'data' => $versions], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

foreach ($versions as $entry) {
    if (($entry['platform'] ?? null) === $platform) {
        echo json_encode(['success' => true, 'data' => $entry], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

http_response_code(404);
echo json_encode(['success' => false, 'message' => "Plateforme inconnue: $platform"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
