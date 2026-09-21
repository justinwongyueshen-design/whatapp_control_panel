<?php
/**
 * Authentication and Authorization Subsystem
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

class Auth {
    /**
     * Authenticate web user credentials
     */
    public static function login(string $username, string $password): array {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => trim($username)]);
        $user = $stmt->fetch();

        if (!$user) {
            audit_log('login_failed', "User '$username' not found");
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        if ($user['status'] !== 'active') {
            audit_log('login_failed', "Inactive user '$username' attempted login", $user['id']);
            return ['success' => false, 'message' => 'Account is inactive. Please contact the administrator.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            audit_log('login_failed', "Incorrect password for user '$username'", $user['id']);
            return ['success' => false, 'message' => 'Invalid username or password'];
        }

        // Regenerate session ID to prevent session fixation attacks
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in_at'] = time();

        // Update last login timestamp
        $updateStmt = $db->prepare("UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);

        audit_log('login_success', "User '{$user['username']}' logged in successfully", $user['id']);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Terminate web session
     */
    public static function logout(): void {
        if (!empty($_SESSION['user_id'])) {
            audit_log('logout', "User '{$_SESSION['username']}' logged out", $_SESSION['user_id']);
        }

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Check if user is logged in
     */
    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Get current logged-in user array
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }
        return [
            'id'        => $_SESSION['user_id'],
            'username'  => $_SESSION['username'],
            'full_name' => $_SESSION['full_name'],
            'role'      => $_SESSION['role']
        ];
    }

    /**
     * Require active login or redirect
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                exit;
            }
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }

    /**
     * Require specific role (e.g. 'admin')
     */
    public static function requireRole(string $requiredRole): void {
        self::requireLogin();
        if (($_SESSION['role'] ?? '') !== $requiredRole) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access denied: insufficient permissions']);
                exit;
            }
            die('Access denied: You do not have permission to access this resource.');
        }
    }

    /**
     * Authenticate worker via HTTP Authorization Bearer token
     *
     * @return array Worker record
     */
    public static function authenticateWorker(): array {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Missing or malformed Bearer authorization token']);
            exit;
        }

        $token = trim($matches[1]);
        $db = get_db();

        // Query active workers
        $stmt = $db->query("SELECT * FROM workers WHERE status != 'disabled'");
        $workers = $stmt->fetchAll();

        $authenticatedWorker = null;
        foreach ($workers as $worker) {
            if (password_verify($token, $worker['token_hash'])) {
                $authenticatedWorker = $worker;
                break;
            }
        }

        if (!$authenticatedWorker) {
            audit_log('worker_auth_failed', "Invalid worker token presented");
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid or revoked worker token']);
            exit;
        }

        return $authenticatedWorker;
    }
}
