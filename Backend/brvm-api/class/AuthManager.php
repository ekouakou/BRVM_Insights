<?php
/**
 * Gestionnaire d'authentification complet avec gestion des devices et sessions
 * 
 * @author Quiz App Team
 * @version 2.0
 */

class AuthManager extends DynamiqueCrud {
    
    private const SESSION_LIFETIME = 86400; // 24 heures
    private const REMEMBER_LIFETIME = 2592000; // 30 jours
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minutes
    
    /**
     * Utilisateur actuellement connecté
     */
    private $currentUser = null;
    
    /**
     * Constructeur
     */
    public function __construct() {
        parent::__construct();
        $this->initializeSession();
    }
    
    /**
     * Initialise la session
     */
    private function initializeSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Nettoyer les sessions expirées périodiquement
        if (rand(1, 100) === 1) {
            $this->cleanExpiredSessions();
        }
    }
    
    /**
     * Inscription d'un nouvel utilisateur
     */
    public function register($userData, $deviceData = null) {
        try {
            // Vérifier si l'utilisateur existe déjà
            $existingUser = $this->checkExistingUser($userData['email'], $userData['username']);
            if ($existingUser['exists']) {
                return [
                    'success' => false,
                    'message' => $existingUser['message'],
                    'field' => $existingUser['field']
                ];
            }
            
            // Insérer l'utilisateur
            $userId = $this->persist('users', $userData);
            if (!$userId) {
                return ['success' => false, 'message' => 'Erreur lors de la création du compte'];
            }
            
            // Enregistrer le device si fourni
            $deviceDbId = null;
            if ($deviceData && isset($deviceData['device_id'])) {
                $deviceDbId = $this->registerDevice($deviceData['device_id'], $deviceData);
                if ($deviceDbId) {
                    $this->linkUserToDevice($userId, $deviceDbId, true);
                }
            }
            
            // Log de la tentative réussie
            $this->logLoginAttempt($userData['email'], true, 'registration_success');
            
            return [
                'success' => true,
                'message' => 'Compte créé avec succès',
                'user_id' => $userId,
                'device_id' => $deviceDbId,
                'userDataSend' => $userData,
                '$userId'=>$userId
            ];
            
        } catch (Exception $e) {
            error_log("Erreur lors de l'inscription: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne lors de la création du compte'];
        }
    }
    
    /**
     * Vérifie si un utilisateur existe déjà
     */
    private function checkExistingUser($email, $username) {
        $existingEmail = $this->find('users', ['email' => $email]);
        if (!empty($existingEmail)) {
            return [
                'exists' => true,
                'message' => 'Cette adresse email est déjà utilisée',
                'field' => 'email'
            ];
        }
        
        $existingUsername = $this->find('users', ['username' => $username]);
        if (!empty($existingUsername)) {
            return [
                'exists' => true,
                'message' => 'Ce nom d\'utilisateur est déjà pris',
                'field' => 'username'
            ];
        }
        
        return ['exists' => false];
    }
    
    /**
     * Vérification des données utilisateur (pour l'inscription)
     */
    public function verifyUserData($userData) {
        $errors = [];
        
        // Vérifier l'email
        if (isset($userData['email'])) {
            $existingEmail = $this->find('users', ['email' => $userData['email']]);
            if (!empty($existingEmail)) {
                $errors['email'] = 'Cette adresse email est déjà utilisée';
            }
        }
        
        // Vérifier le téléphone
        if (isset($userData['user_phone'])) {
            $existingPhone = $this->find('users', ['user_phone' => $userData['user_phone']]);
            if (!empty($existingPhone)) {
                $errors['user_phone'] = 'Ce numéro de téléphone est déjà utilisé';
            }
        }
        
        // Vérifier le nom d'utilisateur si fourni
        if (isset($userData['username'])) {
            $existingUsername = $this->find('users', ['username' => $userData['username']]);
            if (!empty($existingUsername)) {
                $errors['username'] = 'Ce nom d\'utilisateur est déjà pris';
            }
        }
        
        return [
            'success' => empty($errors),
            'errors' => $errors,
            'message' => empty($errors) ? 'Données disponibles' : 'Certaines données sont déjà utilisées'
        ];
    }
    
    /**
     * Connexion classique
     */
    public function login($identifier, $password, $rememberMe = false, $deviceData = null) {
        try {
            // Vérifier les tentatives de connexion
            if ($this->isAccountLocked($identifier)) {
                return [
                    'success' => false,
                    'message' => 'Compte temporairement verrouillé suite à trop de tentatives de connexion',
                    'locked' => true
                ];
            }
            
            // Chercher l'utilisateur par email ou username
            $user = $this->findUserByIdentifier($identifier);
            if (!$user) {
                $this->logLoginAttempt($identifier, false, 'user_not_found');
                return ['success' => false, 'message' => 'Identifiants incorrects'];
            }
            
            // Vérifier le mot de passe
            if (!password_verify($password, $user['password'])) {
                $this->logLoginAttempt($identifier, false, 'wrong_password');
                return ['success' => false, 'message' => 'Identifiants incorrects'];
            }
            
            // Vérifier le statut du compte
            if ($user['status'] !== 'active') {
                $this->logLoginAttempt($identifier, false, 'account_inactive');
                return ['success' => false, 'message' => 'Compte inactif ou suspendu'];
            }
            
            // Créer la session
            $sessionResult = $this->createUserSession($user['id'], $deviceData, $rememberMe);
            if (!$sessionResult['success']) {
                return $sessionResult;
            }
            
            // Mettre à jour les informations de connexion
            $this->updateLastLogin($user['id']);
            $this->logLoginAttempt($identifier, true, 'login_success');
            
            // Nettoyer les données utilisateur avant de les retourner
            $userData = $this->sanitizeUserData($user);
            
            return [
                'success' => true,
                'message' => 'Connexion réussie',
                'user' => $userData,
                'session_token' => $sessionResult['session_token'],
                'device_id' => $sessionResult['device_id'] ?? null
            ];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la connexion: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne lors de la connexion'];
        }
    }
    
    /**
     * Connexion avec device uniquement
     */
    public function loginWithDeviceOnly($deviceId, $password, $rememberMe = false) {
        try {
            if (!$deviceId) {
                return ['success' => false, 'message' => 'ID du device requis'];
            }
            
            // Trouver le device
            $device = $this->find('devices', ['device_id' => $deviceId]);
            if (empty($device)) {
                return ['success' => false, 'message' => 'Device non reconnu'];
            }
            
            // Trouver les utilisateurs liés à ce device
            $userDevices = $this->find('user_devices', [
                'device_id' => $device[0]['id'],
                'is_active' => 1
            ]);
            
            if (empty($userDevices)) {
                return ['success' => false, 'message' => 'Aucun utilisateur associé à ce device'];
            }
            
            // Si plusieurs utilisateurs, prendre le principal ou le dernier connecté
            $targetUserDevice = null;
            foreach ($userDevices as $ud) {
                if ($ud['is_primary'] == 1 || $targetUserDevice === null) {
                    $targetUserDevice = $ud;
                }
                if ($ud['last_login'] && (!$targetUserDevice['last_login'] || $ud['last_login'] > $targetUserDevice['last_login'])) {
                    $targetUserDevice = $ud;
                }
            }
            
            // Récupérer l'utilisateur complet
            $user = $this->find('users', ['id' => $targetUserDevice['user_id']]);
            if (empty($user)) {
                return ['success' => false, 'message' => 'Utilisateur non trouvé'];
            }
            $user = $user[0];
            
            // Vérifier le mot de passe
            if (!password_verify($password, $user['password'])) {
                $this->logLoginAttempt($deviceId, false, 'wrong_password');
                return ['success' => false, 'message' => 'Mot de passe incorrect'];
            }
            
            // Vérifier le statut du compte
            if ($user['status'] !== 'active') {
                $this->logLoginAttempt($deviceId, false, 'account_inactive');
                return ['success' => false, 'message' => 'Compte inactif ou suspendu'];
            }
            
            // Créer la session
            $sessionResult = $this->createUserSession($user['id'], ['device_id' => $deviceId], $rememberMe);
            if (!$sessionResult['success']) {
                return $sessionResult;
            }
            
            // Mettre à jour les informations de connexion
            $this->updateLastLogin($user['id']);
            $this->logLoginAttempt($deviceId, true, 'device_login_success');
            
            $userData = $this->sanitizeUserData($user);
            
            return [
                'success' => true,
                'message' => 'Connexion avec device réussie',
                'user' => $userData,
                'session_token' => $sessionResult['session_token']
            ];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la connexion avec device: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne lors de la connexion'];
        }
    }
    
    /**
     * Déconnexion
     */
    public function logout($deviceId = null) {
        try {
            if (isset($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
                
                // Désactiver les sessions
                if ($deviceId) {
                    $device = $this->find('devices', ['device_id' => $deviceId]);
                    if (!empty($device)) {
                        $this->merge('user_sessions', 
                            ['is_active' => 0], 
                            ['user_id' => $userId, 'device_id' => $device[0]['id']]
                        );
                    }
                } else {
                    // Désactiver toutes les sessions de l'utilisateur
                    $this->merge('user_sessions', 
                        ['is_active' => 0], 
                        ['user_id' => $userId]
                    );
                }
            }
            
            // Détruire la session PHP
            session_destroy();
            session_start(); // Redémarrer une nouvelle session vide
            
            $this->currentUser = null;
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erreur lors de la déconnexion: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifie si l'utilisateur est connecté
     */
    public function isLoggedIn() {
        if ($this->currentUser !== null) {
            return true;
        }
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }
        
        // Vérifier que la session existe en base
        $session = $this->find('user_sessions', [
            'user_id' => $_SESSION['user_id'],
            'session_token' => $_SESSION['session_token'],
            'is_active' => 1
        ]);
        
        if (empty($session)) {
            $this->logout();
            return false;
        }
        
        $session = $session[0];
        
        // Vérifier l'expiration
        if ($session['expires_at'] && strtotime($session['expires_at']) < time()) {
            $this->logout();
            return false;
        }
        
        // Mettre à jour la dernière activité
        $this->merge('user_sessions', 
            ['last_activity' => date('Y-m-d H:i:s')], 
            ['id' => $session['id']]
        );
        
        return true;
    }
    
    /**
     * Vérifie si l'utilisateur est admin
     */
    public function isAdmin() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $user = $this->getCurrentUser();
        return $user && $user['is_admin'] == 1;
    }
    
    /**
     * Récupère l'utilisateur actuellement connecté
     */
    public function getCurrentUser() {
        if ($this->currentUser !== null) {
            return $this->currentUser;
        }
        
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $user = $this->find('users', ['id' => $_SESSION['user_id']]);
        if (!empty($user)) {
            $this->currentUser = $this->sanitizeUserData($user[0]);
            return $this->currentUser;
        }
        
        return null;
    }
    
    /**
     * Récupère l'ID de l'utilisateur actuellement connecté
     */
    public function getCurrentUserId() {
        $user = $this->getCurrentUser();
        return $user ? $user['id'] : null;
    }
    
    /**
     * Changement de mot de passe
     */
    public function changePassword($deviceId, $newPassword, $confirmPassword) {
        try {
            if (!$this->isLoggedIn()) {
                return ['success' => false, 'message' => 'Utilisateur non connecté'];
            }
            
            if ($newPassword !== $confirmPassword) {
                return ['success' => false, 'message' => 'Les mots de passe ne correspondent pas'];
            }
            
            if (strlen($newPassword) < 4) {
                return ['success' => false, 'message' => 'Le mot de passe doit contenir au moins 4 caractères'];
            }
            
            $userId = $this->getCurrentUserId();
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $success = $this->merge('users', 
                ['password' => $hashedPassword], 
                ['id' => $userId]
            );
            
            if ($success) {
                // Déconnecter tous les autres devices sauf celui-ci
                if ($deviceId) {
                    $device = $this->find('devices', ['device_id' => $deviceId]);
                    if (!empty($device)) {
                        $this->executeQuery(
                            "UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND device_id != ?",
                            [$userId, $device[0]['id']]
                        );
                    }
                }
                
                return ['success' => true, 'message' => 'Mot de passe modifié avec succès'];
            }
            
            return ['success' => false, 'message' => 'Erreur lors de la modification du mot de passe'];
            
        } catch (Exception $e) {
            error_log("Erreur lors du changement de mot de passe: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne'];
        }
    }
    
    /**
     * Enregistre ou met à jour un device
     */
    public function registerDevice($deviceId, $deviceInfo = null) {
        try {
            // Vérifier si le device existe déjà
            $existingDevice = $this->find('devices', ['device_id' => $deviceId]);
            
            if (!empty($existingDevice)) {
                // Mettre à jour les informations du device
                $updateData = ['last_seen' => date('Y-m-d H:i:s')];
                
                if ($deviceInfo) {
                    if (isset($deviceInfo['device_platform'])) $updateData['device_platform'] = $deviceInfo['device_platform'];
                    if (isset($deviceInfo['device_model'])) $updateData['device_model'] = $deviceInfo['device_model'];
                    if (isset($deviceInfo['device_os_version'])) $updateData['device_os_version'] = $deviceInfo['device_os_version'];
                    if (isset($deviceInfo['device_brand'])) $updateData['device_brand'] = $deviceInfo['device_brand'];
                    if (isset($deviceInfo['device_name'])) $updateData['device_name'] = $deviceInfo['device_name'];
                    if (isset($deviceInfo['user_agent'])) $updateData['user_agent'] = $deviceInfo['user_agent'];
                    if (isset($deviceInfo['device_info'])) $updateData['device_info'] = json_encode($deviceInfo['device_info']);
                }
                
                $this->merge('devices', $updateData, ['id' => $existingDevice[0]['id']]);
                return $existingDevice[0]['id'];
            }
            
            // Créer un nouveau device
            $deviceData = [
                'device_id' => $deviceId,
                'device_platform' => $deviceInfo['device_platform'] ?? null,
                'device_model' => $deviceInfo['device_model'] ?? null,
                'device_os_version' => $deviceInfo['device_os_version'] ?? null,
                'device_brand' => $deviceInfo['device_brand'] ?? null,
                'device_name' => $deviceInfo['device_name'] ?? null,
                'user_agent' => $deviceInfo['user_agent'] ?? null,
                'device_info' => $deviceInfo ? json_encode($deviceInfo) : null,
                'is_active' => 1
            ];
            
            return $this->persist('devices', $deviceData);
            
        } catch (Exception $e) {
            error_log("Erreur lors de l'enregistrement du device: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Lie un utilisateur à un device
     */
    public function linkUserToDevice($userId, $deviceDbId, $isPrimary = false) {
        try {
            // Vérifier si la liaison existe déjà
            $existingLink = $this->find('user_devices', [
                'user_id' => $userId,
                'device_id' => $deviceDbId
            ]);
            
            if (!empty($existingLink)) {
                // Mettre à jour la liaison existante
                $this->merge('user_devices', 
                    ['is_active' => 1, 'is_primary' => $isPrimary ? 1 : 0], 
                    ['id' => $existingLink[0]['id']]
                );
                return true;
            }
            
            // Si c'est le device principal, désactiver les autres devices principaux
            if ($isPrimary) {
                $this->merge('user_devices', 
                    ['is_primary' => 0], 
                    ['user_id' => $userId]
                );
            }
            
            // Créer la nouvelle liaison
            $linkData = [
                'user_id' => $userId,
                'device_id' => $deviceDbId,
                'is_primary' => $isPrimary ? 1 : 0,
                'is_active' => 1,
                'login_count' => 0
            ];
            
            return $this->persist('user_devices', $linkData) !== false;
            
        } catch (Exception $e) {
            error_log("Erreur lors de la liaison utilisateur-device: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Vérifie si un device existe en base
     */
    public function checkDeviceInDataBase($deviceData) {
        try {
            $device = $this->find('devices', ['device_id' => $deviceData['device_id']]);
            
            if (empty($device)) {
                return [
                    'success' => true,
                    'exists' => false,
                    'message' => 'Device non trouvé'
                ];
            }
            
            // Récupérer les utilisateurs liés
            $users = $this->getUsersForDevice($deviceData['device_id']);
            
            return [
                'success' => true,
                'exists' => true,
                'device' => $device[0],
                'users' => $users,
                'message' => 'Device trouvé avec ' . count($users) . ' utilisateur(s) lié(s)'
            ];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du device: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne'];
        }
    }
    
    /**
     * Récupère les utilisateurs liés à un device
     */
    public function getUsersForDevice($deviceId) {
        try {
            $device = $this->find('devices', ['device_id' => $deviceId]);
            if (empty($device)) {
                return [];
            }
            
            $query = "
                SELECT u.id, u.username, u.email, u.first_name, u.last_name, 
                       ud.is_primary, ud.last_login, ud.login_count
                FROM user_devices ud
                INNER JOIN users u ON ud.user_id = u.id
                WHERE ud.device_id = ? AND ud.is_active = 1 AND u.status = 'active'
                ORDER BY ud.is_primary DESC, ud.last_login DESC
            ";
            
            $result = $this->executeQuery($query, [$device[0]['id']]);
            return $result ? $result : [];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la récupération des utilisateurs du device: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Récupère les devices d'un utilisateur
     */
    public function getDevicesForUser($userId) {
        try {
            $query = "
                SELECT d.device_id, d.device_platform, d.device_model, d.device_brand, 
                       d.device_name, d.last_seen, ud.is_primary, ud.last_login, ud.login_count
                FROM user_devices ud
                INNER JOIN devices d ON ud.device_id = d.id
                WHERE ud.user_id = ? AND ud.is_active = 1
                ORDER BY ud.is_primary DESC, ud.last_login DESC
            ";
            
            $result = $this->executeQuery($query, [$userId]);
            return $result ? $result : [];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la récupération des devices de l'utilisateur: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Crée une session utilisateur
     */
    private function createUserSession($userId, $deviceData = null, $rememberMe = false) {
        try {
            $deviceDbId = null;
            
            // Gérer le device si fourni
            if ($deviceData && isset($deviceData['device_id'])) {
                $deviceDbId = $this->registerDevice($deviceData['device_id'], $deviceData);
                if ($deviceDbId) {
                    $this->linkUserToDevice($userId, $deviceDbId);
                }
            }
            
            // Générer un token de session unique
            $sessionToken = bin2hex(random_bytes(32));
            
            // Calculer la date d'expiration
            $expiresAt = null;
            if ($rememberMe) {
                $expiresAt = date('Y-m-d H:i:s', time() + self::REMEMBER_LIFETIME);
            } else {
                $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);
            }
            
            // Créer la session en base
            $sessionData = [
                'user_id' => $userId,
                'device_id' => $deviceDbId,
                'session_token' => $sessionToken,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'is_active' => 1,
                'remember_me' => $rememberMe ? 1 : 0,
                'expires_at' => $expiresAt
            ];
            
            $sessionId = $this->persist('user_sessions', $sessionData);
            if (!$sessionId) {
                return ['success' => false, 'message' => 'Erreur lors de la création de la session===>' . $sessionId];
            }
            
            // Sauvegarder dans la session PHP
            $_SESSION['user_id'] = $userId;
            $_SESSION['session_token'] = $sessionToken;
            $_SESSION['session_id'] = $sessionId;
            
            return [
                'success' => true,
                'session_token' => $sessionToken,
                'device_id' => $deviceDbId
            ];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la création de la session: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne lors de la création de la session'];
        }
    }
    
    /**
     * Définit une session active
     */
    public function setActiveSession($userId, $deviceDbId, $sessionToken = null) {
        try {
            if (!$sessionToken) {
                $sessionToken = bin2hex(random_bytes(32));
            }
            
            // Désactiver les autres sessions pour ce device
            $this->merge('user_sessions', 
                ['is_active' => 0], 
                ['user_id' => $userId, 'device_id' => $deviceDbId]
            );
            
            // Créer la nouvelle session
            $sessionData = [
                'user_id' => $userId,
                'device_id' => $deviceDbId,
                'session_token' => $sessionToken,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'is_active' => 1,
                'remember_me' => 0,
                'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME)
            ];
            
            $sessionId = $this->persist('user_sessions', $sessionData);
            
            // Sauvegarder dans la session PHP
            $_SESSION['user_id'] = $userId;
            $_SESSION['session_token'] = $sessionToken;
            $_SESSION['session_id'] = $sessionId;
            
            return $sessionId !== false;
            
        } catch (Exception $e) {
            error_log("Erreur lors de la définition de la session active: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Nettoie les sessions expirées
     */
    private function cleanExpiredSessions() {
        try {
            $this->executeQuery("CALL CleanExpiredSessions()");
        } catch (Exception $e) {
            // Fallback si la procédure stockée n'existe pas
            $this->executeQuery(
                "DELETE FROM user_sessions WHERE expires_at IS NOT NULL AND expires_at < NOW()"
            );
            $this->executeQuery(
                "DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
        }
    }
    
    /**
     * Log des tentatives de connexion
     */
    private function logLoginAttempt($identifier, $success, $reason = null) {
        try {
            $this->persist('login_attempts', [
                'identifier' => $identifier,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'success' => $success ? 1 : 0,
                'failure_reason' => $reason
            ]);
        } catch (Exception $e) {
            error_log("Erreur lors du log de tentative de connexion: " . $e->getMessage());
        }
    }
    
    /**
     * Vérifie si un compte est verrouillé
     */
    private function isAccountLocked($identifier) {
        try {
            $stmt = $this->executeQuery(
                "SELECT COUNT(*) as count FROM login_attempts 
                WHERE identifier = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                [$identifier, self::LOCKOUT_TIME]
            );
            
            if ($stmt && $stmt instanceof PDOStatement) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result && $result['count'] >= self::MAX_LOGIN_ATTEMPTS;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Erreur lors de la vérification du verrouillage: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Trouve un utilisateur par identifiant (email ou username)
     */
    private function findUserByIdentifier($identifier) {
        $user = $this->find('users', ['email' => $identifier]);
        if (empty($user)) {
            $user = $this->find('users', ['username' => $identifier]);
        }
        return empty($user) ? null : $user[0];
    }
    
    /**
     * Met à jour la dernière connexion
     */
    private function updateLastLogin($userId) {
        $this->merge('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $userId]);
    }
    
    /**
     * Nettoie les données utilisateur (supprime le mot de passe, etc.)
     */
    private function sanitizeUserData($user) {
        unset($user['password']);
        return $user;
    }
    
    // ============================================================================
    // MÉTHODES POUR L'ADMINISTRATION
    // ============================================================================
    
    /**
     * Récupère tous les utilisateurs (pagination)
     */
    public function getAllUsers($page = 1, $perPage = 10) {
        try {
            $offset = ($page - 1) * $perPage;
            
            $users = $this->executeQuery(
                "SELECT id, username, email, first_name, last_name, status, is_admin, 
                        registration_date, last_login, profile_completed, onboarding_completed
                 FROM users 
                 ORDER BY registration_date DESC 
                 LIMIT ? OFFSET ?",
                [$perPage, $offset]
            );
            
            $total = $this->count('users');
            
            return [
                'success' => true,
                'data' => $users,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage)
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Erreur lors de la récupération des utilisateurs: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur interne'];
        }
    }
    
    /**
     * Change le statut d'un utilisateur
     */
    public function changeUserStatus($userId, $status) {
        try {
            $allowedStatuses = ['active', 'inactive', 'suspended'];
            if (!in_array($status, $allowedStatuses)) {
                return false;
            }
            
            return $this->merge('users', ['status' => $status], ['id' => $userId]) !== false;
            
        } catch (Exception $e) {
            error_log("Erreur lors du changement de statut: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Supprime un utilisateur
     */
    public function deleteUser($userId) {
        try {
            return $this->remove('users', ['id' => $userId]) !== false;
        } catch (Exception $e) {
            error_log("Erreur lors de la suppression de l'utilisateur: " . $e->getMessage());
            return false;
        }
    }
}