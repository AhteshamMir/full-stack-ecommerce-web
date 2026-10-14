<?php
/**
 * ShopSphere - Product Review Submission Handler
 *
 * Validates review input, enforces verified buyer rules via ReviewService,
 * records customer rating, synchronizes product & seller score aggregates,
 * and flashes status messages before returning to the product detail view.
 *
 * @package ShopSphere\Actions
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/classes/Database.php';
require_once dirname(__DIR__) . '/classes/ReviewService.php';

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('shop.php'));
}

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Verify user authentication
if (!is_logged_in()) {
    flash('error', 'Please sign in to submit a review.');
    redirect(url('login.php'));
}

$userId = (int) get_current_user_id();

// 2. Validate CSRF token
$csrfToken = $_POST['csrf_token'] ?? null;
if (!is_string($csrfToken) || !validate_csrf_token($csrfToken)) {
    flash('error', 'Your session or security token has expired. Please refresh and try again.');
    $referrer = $_SERVER['HTTP_REFERER'] ?? url('shop.php');
    redirect($referrer);
}

// 3. Validate product
$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
if ($productId <= 0) {
    flash('error', 'Invalid product selected.');
    redirect(url('shop.php'));
}

$pdo = get_db_connection();
$pStmt = $pdo->prepare('SELECT id, name, slug FROM products WHERE id = :id LIMIT 1');
$pStmt->execute([':id' => $productId]);
$product = $pStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    flash('error', 'Product not found.');
    redirect(url('shop.php'));
}

$productUrl = url('product.php?slug=' . urlencode((string) $product['slug']));

// 4. Validate rating (1 to 5)
$rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
if ($rating < 1 || $rating > 5) {
    flash('error', 'Please select a rating between 1 and 5 stars.');
    redirect($productUrl);
}

// 5. Validate title and comment
$title = sanitize_string($_POST['title'] ?? '');
if ($title === '') {
    flash('error', 'Please enter a review headline or title.');
    redirect($productUrl);
}

if (mb_strlen($title, 'UTF-8') > 200) {
    flash('error', 'Review headline must not exceed 200 characters.');
    redirect($productUrl);
}

$comment = sanitize_string($_POST['comment'] ?? '');
if ($comment === '') {
    flash('error', 'Please share your thoughts in the review comment.');
    redirect($productUrl);
}

if (mb_strlen($comment, 'UTF-8') < 5) {
    flash('error', 'Review comment must be at least 5 characters long.');
    redirect($productUrl);
}

// 6. Submit review via ReviewService
try {
    $reviewService = new ReviewService($pdo);
    $reviewId = $reviewService->createReview(
        $userId,
        $productId,
        null, // automatically matches delivered order_item_id
        $rating,
        $title,
        $comment
    );

    flash('success', 'Thank you! Your review has been submitted successfully.');
} catch (Throwable $e) {
    error_log('[ShopSphere Review Error] ' . $e->getMessage());
    flash('error', $e->getMessage());
}

redirect($productUrl . '#reviews-section');
