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
