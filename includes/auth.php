<?php
/**
 * ShopSphere - Authentication and Authorization System
 *
 * Handles user session management, access control checks, role permissions,
 * and current user retrieval with per-request caching.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

// Ensure dependencies are loaded
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';

if (!function_exists('is_logged_in')) {
    /**
     * Check if the current client is authenticated.
     *
     * @return bool True if logged in, false otherwise.
     */
    function is_logged_in(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('get_current_user_id')) {
    /**
     * Get the authenticated user's ID from the session.
     *
     * @return int|null User ID if authenticated, null otherwise.
     */
    function get_current_user_id(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}

if (!function_exists('get_current_user_role')) {
    /**
     * Get the authenticated user's role from the session.
     *
     * @return string|null Role string (e.g. 'admin', 'seller', 'customer'), or null if guest.
     */
    function get_current_user_role(): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : null;
    }
}

if (!function_exists('current_user')) {
    /**
     * Retrieve the full user record from the database, cached per-request.
     *
     * @param bool $fresh If true, bypasses the request cache and reloads from DB.
     * @return array|null User record associative array or null if not found/guest.
     */
    function current_user(bool $fresh = false): ?array
    {
        static $cachedUser = null;
        static $cachedUserId = null;

        $userId = get_current_user_id();
        if ($userId === null) {
            $cachedUser = null;
            $cachedUserId = null;
            return null;
        }

        if (!$fresh && $cachedUser !== null && $cachedUserId === $userId) {
            return $cachedUser;
        }

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch();

            if ($user) {
                // Remove password hash from cached memory for safety
                unset($user['password'], $user['password_hash']);
                $cachedUser = $user;
                $cachedUserId = $userId;
                return $cachedUser;
            }
        } catch (PDOException $e) {
            error_log('[ShopSphere Auth] Failed to fetch current user: ' . $e->getMessage());
        }

        $cachedUser = null;
        $cachedUserId = null;
        return null;
    }

    /**
     * Alias for current_user().
     */
    function get_auth_user(bool $fresh = false): ?array
    {
        return current_user($fresh);
    }
}

if (!function_exists('require_login')) {
    /**
     * Enforce authentication on a route. Redirects guests to the login page.
     *
     * @param string|null $redirect Optional explicit redirect URL.
     * @return void
     */
    function require_login(?string $redirect = null): void
    {
        if (is_logged_in()) {
            return;
        }

        // Save intended destination for post-login redirect
        if (!empty($_SERVER['REQUEST_URI'])) {
            $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        if ($isAjax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Authentication required.',
                'code'    => 401,
            ]);
            exit;
        }

        $loginUrl = $redirect ?? (defined('APP_URL') ? APP_URL . '/login.php' : '/login.php');
        header('Location: ' . $loginUrl);
        exit;
    }
}

if (!function_exists('require_role')) {
    /**
     * Enforce role-based access control. Aborts with 403 Forbidden if user lacks role.
     *
     * @param string|array $role Required role or array of allowed roles.
     * @return void
     */
    function require_role(string|array $role): void
    {
        require_login();

        $allowedRoles = is_array($role) ? $role : [$role];
        $currentRole = get_current_user_role();

        if ($currentRole !== null && in_array($currentRole, $allowedRoles, true)) {
            return;
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        http_response_code(403);

        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Forbidden: You do not have permission to access this resource.',
                'code'    => 403,
            ]);
            exit;
        }

        die("<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <title>403 Forbidden - Access Denied</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; padding: 2.5rem; border-radius: 0.75rem; max-width: 500px; text-align: center; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); }
        h1 { color: #f43f5e; font-size: 1.75rem; margin-top: 0; }
        p { color: #94a3b8; line-height: 1.6; }
        a { display: inline-block; margin-top: 1.5rem; padding: 0.75rem 1.5rem; background: #6366f1; color: #fff; text-decoration: none; border-radius: 0.5rem; font-weight: 500; }
        a:hover { background: #4f46e5; }
    </style>
</head>
<body>
    <div class=\"card\">
        <h1>403 Forbidden</h1>
        <p>Access Denied. You do not possess the necessary privileges to view or perform actions on this resource.</p>
        <a href=\"javascript:history.back()\">Return to Previous Page</a>
    </div>
</body>
</html>");
    }
}

if (!function_exists('login_user')) {
    /**
     * Authenticate and initialize a session for a user, regenerating session ID.
     *
     * @param array $user User database record.
     * @return void
     */
    function login_user(array $user): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID to prevent session fixation attacks
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id']    = (int) ($user['id'] ?? 0);
        $_SESSION['user_role']  = (string) ($user['role'] ?? 'customer');
        $_SESSION['user_email'] = (string) ($user['email'] ?? '');
        $_SESSION['user_name']  = (string) ($user['full_name'] ?? $user['name'] ?? $user['first_name'] ?? '');
        $_SESSION['logged_in_at'] = time();

        // Flush in-memory user cache
        current_user(true);
    }
}

if (!function_exists('logout_user')) {
    /**
     * Safely destroy the current user's session and clean up cookies.
     *
     * @return void
     */
    function logout_user(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Unset all session variables
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Destroy session storage
        session_destroy();
    }
}

if (!function_exists('is_admin')) {
    /**
     * Check if the authenticated user is an administrator.
     *
     * @return bool True if admin, false otherwise.
     */
    function is_admin(): bool
    {
        return get_current_user_role() === 'admin';
    }
}

if (!function_exists('is_seller')) {
    /**
     * Check if the authenticated user is a vendor/seller.
     *
     * @return bool True if seller/vendor, false otherwise.
     */
    function is_seller(): bool
    {
        $role = get_current_user_role();
        return $role === 'seller' || $role === 'vendor';
    }
}

if (!function_exists('is_customer')) {
    /**
     * Check if the authenticated user is a regular customer.
     *
     * @return bool True if customer, false otherwise.
     */
    function is_customer(): bool
    {
        return get_current_user_role() === 'customer';
    }
}
