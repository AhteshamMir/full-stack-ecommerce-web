<?php
/**
 * ShopSphere - AJAX Notifications API Endpoint
 *
 * Handles reading single or all in-app notifications for authenticated users,
 * returning updated unread count badges.
 *
 * @package ShopSphere\API
 */

declare(strict_types=1);

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

// Load prerequisites
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/classes/Database.php';
require_once dirname(__DIR__) . '/classes/NotificationService.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: Enforce authenticated user session
if (!is_logged_in()) {
    json_response([
        'success' => false,
        'message' => 'Authentication required.',
    ], 401);
}

// Security: Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Invalid request method. POST required.',
    ], 405);
}

// Security: Validate CSRF or AJAX header
$submittedToken = $_POST['csrf_token']
    ?? $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? $_SERVER['HTTP_X_XSRF_TOKEN']
    ?? null;

$isAjaxHeader = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

$sessionToken = $_SESSION['csrf_token'] ?? '';
$isValidCsrf = !empty($submittedToken) && !empty($sessionToken) && hash_equals($sessionToken, (string) $submittedToken);

if (!$isAjaxHeader && !$isValidCsrf) {
    json_response([
        'success' => false,
        'message' => 'Security validation failed. Please refresh the page.',
    ], 403);
}

$userId = (int) get_current_user_id();
$action = trim((string) ($_POST['action'] ?? ''));

try {
    $notificationService = new NotificationService();

    if ($action === 'mark_read') {
        $notificationId = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;
        if ($notificationId <= 0) {
            json_response([
                'success' => false,
                'message' => 'Invalid notification identifier.',
            ], 400);
        }

        $notificationService->markAsRead($notificationId, $userId);
        $unreadCount = $notificationService->getUnreadCount($userId);

        json_response([
            'success'      => true,
            'unread_count' => $unreadCount,
        ]);
    } elseif ($action === 'mark_all_read') {
        $notificationService->markAllAsRead($userId);

        json_response([
            'success'      => true,
            'unread_count' => 0,
        ]);
    } else {
        json_response([
            'success' => false,
            'message' => 'Unknown action.',
        ], 400);
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Notifications API Error] ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'An error occurred while updating notifications.',
    ], 500);
}
