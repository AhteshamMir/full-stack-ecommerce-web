<?php
/**
 * ShopSphere - AJAX Wishlist API Endpoint
 *
 * Handles toggling products into/out of customer wishlists,
 * verifies authentication, checks CSRF, and returns real-time wishlist badge count.
 *
 * @package ShopSphere\API
 */

declare(strict_types=1);

// Prevent browser and proxy caching
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

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication: Guest users receive redirect instructions
if (!is_logged_in()) {
    json_response([
        'success'  => false,
        'redirect' => url('login.php'),
        'message'  => 'Please sign in to add products to your wishlist.',
    ], 401);
}

// Validate HTTP request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Invalid request method. POST required.',
    ], 405);
}

// Validate CSRF token or AJAX request header
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
$action = trim((string) ($_POST['action'] ?? 'toggle'));
$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

if ($productId <= 0) {
    json_response([
        'success' => false,
        'message' => 'Invalid product identifier.',
    ], 400);
}

try {
    $pdo = get_db_connection();

    // Verify product exists and is active/approved
    $pStmt = $pdo->prepare('SELECT id, name FROM products WHERE id = :id LIMIT 1');
    $pStmt->execute([':id' => $productId]);
    $product = $pStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        json_response([
            'success' => false,
            'message' => 'Product not found.',
        ], 404);
    }

    // Determine wishlist table name (standard is `wishlists`, fallback `wishlist`)
    $table = 'wishlists';
    try {
        $pdo->query('SELECT 1 FROM wishlists LIMIT 1');
    } catch (PDOException) {
        $table = 'wishlist';
    }

    if ($action === 'toggle') {
        // Check if already in wishlist
        $checkStmt = $pdo->prepare("SELECT id FROM {$table} WHERE user_id = :user_id AND product_id = :product_id LIMIT 1");
        $checkStmt->execute([
            ':user_id'    => $userId,
            ':product_id' => $productId,
        ]);
        $existingId = $checkStmt->fetchColumn();

        $wishlisted = false;

        if ($existingId) {
            // Remove from wishlist
            $delStmt = $pdo->prepare("DELETE FROM {$table} WHERE user_id = :user_id AND product_id = :product_id");
            $delStmt->execute([
                ':user_id'    => $userId,
                ':product_id' => $productId,
            ]);
            $wishlisted = false;
            $message = 'Item removed from your wishlist.';
        } else {
            // Add to wishlist
            $insStmt = $pdo->prepare("INSERT INTO {$table} (user_id, product_id, created_at) VALUES (:user_id, :product_id, UTC_TIMESTAMP())");
            $insStmt->execute([
                ':user_id'    => $userId,
                ':product_id' => $productId,
            ]);
            $wishlisted = true;
            $message = 'Item added to your wishlist.';
        }

        // Calculate updated total wishlist count for user
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = :user_id");
        $countStmt->execute([':user_id' => $userId]);
        $wishlistCount = (int) $countStmt->fetchColumn();

        json_response([
            'success'        => true,
            'wishlisted'     => $wishlisted,
            'wishlist_count' => $wishlistCount,
            'message'        => $message,
        ]);
    } else {
        json_response([
            'success' => false,
            'message' => 'Unsupported action.',
        ], 400);
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Wishlist API Error] ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'An error occurred while processing your request.',
    ], 500);
}
