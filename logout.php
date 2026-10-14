<?php
/**
 * ShopSphere - User Logout Handler
 *
 * Securely terminates the current authenticated session, clears sensitive cookies,
 * flashes a logout notification, and redirects to the sign-in page.
 *
 * @package ShopSphere\Auth
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Check if user was logged in to determine role for tailored redirect if needed
$wasAdmin = is_admin();
$wasSeller = is_seller();

// Destroy session and purge cookies securely
logout_user();

// Start fresh guest session for one-time flash notification
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_regenerate_id(true);

flash('success', 'You have been logged out.');

if ($wasAdmin) {
    redirect(url('admin/login.php'));
} elseif ($wasSeller) {
    redirect(url('seller/login.php'));
} else {
    redirect(url('login.php'));
}
