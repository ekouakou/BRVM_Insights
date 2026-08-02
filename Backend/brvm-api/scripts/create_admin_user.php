<?php
/**
 * Crée (ou réinitialise le mot de passe d')un compte administrateur pour le
 * panneau d'admin (Frontend/admin-web). Volontairement CLI uniquement — pas
 * d'endpoint d'inscription public dans api_auth.php.
 *
 * Usage:
 *   php scripts/create_admin_user.php --username=admin --password=motdepasse
 *
 * Si le compte existe déjà, son mot de passe est mis à jour.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../class/DbConnect.php';
require_once __DIR__ . '/../class/DynamiqueCrud.php';

$options = getopt('', ['username:', 'password:']);

$username = trim($options['username'] ?? '');
$password = (string) ($options['password'] ?? '');

if (!$username || !$password) {
    echo "Usage: php scripts/create_admin_user.php --username=admin --password=motdepasse\n";
    exit(1);
}

if (strlen($password) < 8) {
    echo "Le mot de passe doit faire au moins 8 caractères.\n";
    exit(1);
}

$crud = new DynamiqueCrud();
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$existing = $crud->find('admin_users', ['username' => $username]);

if (!empty($existing)) {
    $crud->merge('admin_users', ['password_hash' => $passwordHash], ['id' => $existing[0]['id']]);
    echo "Mot de passe mis à jour pour '$username'.\n";
} else {
    $crud->persist('admin_users', ['username' => $username, 'password_hash' => $passwordHash]);
    echo "Compte '$username' créé.\n";
}
