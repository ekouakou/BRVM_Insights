<?php
/**
 * Garde d'authentification pour le panneau d'administration
 * (Frontend/admin-web). Tokens opaques (voir admin_sessions), pas de JWT —
 * cohérent avec le reste du projet (aucune dépendance externe).
 *
 * Le token voyage dans le header "X-Auth-Token", pas "Authorization" : ce
 * projet tourne sous mod_fastcgi, qui ne transmet pas toujours l'en-tête
 * Authorization à PHP selon la configuration Apache — un header custom
 * évite ce piège classique.
 */
class AuthGuard {
    private const SESSION_DURATION_DAYS = 7;

    /**
     * Bloque la requête (401 + arrêt du script) si le token est absent,
     * inconnu, ou expiré. À appeler juste après les headers CORS dans
     * chaque api_*.php protégé.
     */
    public static function requireAuth(): void {
        $token = self::extractToken();

        if (!$token) {
            self::reject("Authentification requise (header X-Auth-Token manquant)");
        }

        $crud = new DynamiqueCrud();
        $rows = $crud->executeCustomQuery(
            "SELECT id FROM admin_sessions WHERE token = ? AND expires_at > NOW() LIMIT 1",
            [$token]
        );

        if (empty($rows)) {
            self::reject("Session invalide ou expirée, reconnecte-toi");
        }
    }

    /**
     * Identifiant de l'admin_user propriétaire du token courant — utilisé
     * par les fonctionnalités multi-tenant (ex: "Mon Équipe BRVM", voir
     * TODO_PORTFOLIO_TEAM.md) pour scoper les lectures/écritures à
     * l'utilisateur courant. Duplique volontairement la petite extraction
     * de header d'extractToken() (privée) plutôt que d'en changer la
     * visibilité — requireAuth() ne doit pas bouger de comportement.
     * Retourne null si absent (ne devrait pas arriver après un
     * requireAuth() réussi, mais on reste défensif).
     */
    public static function getCurrentUserId(): ?int {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Auth-Token') === 0) {
                $token = trim($value) ?: null;
                break;
            }
        }
        if (!$token && !empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
        }
        if (!$token && !empty($_GET['token'])) {
            $token = trim($_GET['token']);
        }
        if (!$token) {
            return null;
        }

        $crud = new DynamiqueCrud();
        $rows = $crud->executeCustomQuery(
            "SELECT user_id FROM admin_sessions WHERE token = ? AND expires_at > NOW() LIMIT 1",
            [$token]
        );

        return !empty($rows) ? (int) $rows[0]['user_id'] : null;
    }

    /**
     * Crée une session pour un utilisateur et retourne le token généré.
     */
    public static function createSession(DynamiqueCrud $crud, int $userId): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::SESSION_DURATION_DAYS . ' days'));

        $crud->persist('admin_sessions', [
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    private static function extractToken(): ?string {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'X-Auth-Token') === 0) {
                return trim($value) ?: null;
            }
        }
        if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return trim($_SERVER['HTTP_X_AUTH_TOKEN']);
        }
        // Repli sur un paramètre GET ?token=... : nécessaire pour les liens
        // ouverts directement par le navigateur (ex: consultation d'un PDF
        // dans un nouvel onglet), où l'on ne peut pas poser de header custom.
        return !empty($_GET['token']) ? trim($_GET['token']) : null;
    }

    private static function reject(string $message): void {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
