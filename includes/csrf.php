<?php
/**
 * ShopSphere - CSRF Protection Utilities
 *
 * Provides Cross-Site Request Forgery (CSRF) protection mechanisms,
 * token generation, hidden form field generation, token validation, and request checking.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

// Ensure security configuration and session are loaded
require_once dirname(__DIR__) . '/config/security.php';

if (!function_exists('generate_csrf_token')) {
    /**
     * Generate or retrieve the existing CSRF token stored in the session.
     *
     * @param bool $forceNew Whether to force generation of a new token.
     * @return string The 64-character hexadecimal CSRF token.
     */
    function generate_csrf_token(bool $forceNew = false): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($forceNew || empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate HTML hidden input field containing the current CSRF token.
     *
     * @return string HTML hidden input tag.
     */
    function csrf_field(): string
    {
        $token = generate_csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('validate_csrf_token')) {
    /**
     * Validate a submitted CSRF token against the session token.
     * Token is maintained stably across the session unless explicitly rotated
     * to prevent race conditions and broken workflows with concurrent requests or tabs.
     *
     * @param string|null $token The token submitted in the request.
     * @param bool $rotate Whether to rotate the token upon successful validation (default: false).
     * @return bool True if valid, false otherwise.
     */
    function validate_csrf_token(?string $token, bool $rotate = false): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            return false;
        }

        $isValid = hash_equals($_SESSION['csrf_token'], $token);

        if ($isValid && $rotate) {
            generate_csrf_token(true);
        }

        return $isValid;
    }
}

if (!function_exists('verify_csrf_token')) {
    /**
     * Alias for validate_csrf_token.
     *
     * @param string|null $token The token submitted in the request.
     * @param bool $rotate Whether to rotate the token upon match.
     * @return bool True if valid, false otherwise.
     */
    function verify_csrf_token(?string $token, bool $rotate = false): bool
    {
        return validate_csrf_token($token, $rotate);
    }
}

if (!function_exists('check_csrf')) {
    /**
     * Automatically verify CSRF token for state-changing HTTP requests.
     * Terminates execution with 403 Forbidden if validation fails.
     *
     * @return void
     */
    function check_csrf(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Only enforce CSRF for state-changing HTTP methods
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        // Check POST parameter or HTTP request header (standard for AJAX)
        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? null;

        if (is_string($token) && validate_csrf_token($token)) {
            return;
        }

        // Determine if request is AJAX or expects JSON
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        http_response_code(403);

        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid or expired CSRF token. Please refresh the page and try again.',
                'code'    => 403,
            ]);
            exit;
        }

        die("<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <title>403 Forbidden - CSRF Validation Failed</title>
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
        <p>Your session or CSRF security token has expired or is invalid. Please go back, refresh the page, and try again.</p>
        <a href=\"javascript:history.back()\">Go Back</a>
    </div>
</body>
</html>");
    }
}
