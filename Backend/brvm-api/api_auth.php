<?php
/**
 * API d'authentification du panneau d'administration
 * Endpoint: api_auth.php
 *
 * Volontairement le seul endpoint NON protégé par AuthGuard — c'est lui qui
 * délivre le token. Aucun endpoint d'inscription : un compte se crée
 * uniquement via scripts/create_admin_user.php (en terminal).
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

class AuthAPI {
    private $crud;

    public function __construct() {
        $this->crud = new DynamiqueCrud();
    }

    public function handleRequest() {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'login':
                    return $this->login($input);

                case 'logout':
                    return $this->logout($input);

                default:
                    throw new Exception("Action non reconnue: $action");
            }
        } catch (Exception $e) {
            http_response_code(400);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function login($input) {
        $username = trim($input['username'] ?? '');
        $password = (string) ($input['password'] ?? '');

        if (!$username || !$password) {
            throw new Exception("Identifiant et mot de passe requis");
        }

        $users = $this->crud->find('admin_users', ['username' => $username]);

        if (empty($users) || !password_verify($password, $users[0]['password_hash'])) {
            http_response_code(401);
            return ['success' => false, 'message' => "Identifiants invalides"];
        }

        $token = AuthGuard::createSession($this->crud, $users[0]['id']);

        return [
            'success' => true,
            'data' => [
                'token' => $token,
                'username' => $users[0]['username'],
            ]
        ];
    }

    private function logout($input) {
        $token = $input['token'] ?? null;

        if ($token) {
            $this->crud->remove('admin_sessions', ['token' => $token]);
        }

        return ['success' => true];
    }
}

// Exécution
$api = new AuthAPI();
$response = $api->handleRequest();
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
