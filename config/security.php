<?php
/**
 * ShopSphere - Security Configuration & Session Initialization
 *
 * Configures hardened session parameters, applies security HTTP headers,
 * and initializes the session safely.
 *
 * @package ShopSphere\Config
 */

declare(strict_types=1);

// Ensure app configuration and env loader are loaded
require_once __DIR__ . '/app.php';

// Configure session lifetime (minutes from env converted to seconds)
$sessionLifetimeMinutes = (int) shopsphere_env('SESSION_LIFETIME', 120);
$sessionLifetimeSeconds = max(300, $sessionLifetimeMinutes * 60);

// Determine if HTTPS is active or required
$envSecure = (bool) shopsphere_env('SESSION_SECURE', false);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$cookieSecure = $envSecure || $isHttps;

// Apply session security settings only if session has not started yet
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
    ini_set('session.gc_maxlifetime', (string) $sessionLifetimeSeconds);

    session_set_cookie_params([
        'lifetime' => $sessionLifetimeSeconds,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $cookieSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

// Send hardened security headers to prevent common web attacks
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    
    // Content-Security-Policy supporting Bootstrap, Google Fonts, jQuery, and images
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'self';");

    if ($cookieSecure) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
