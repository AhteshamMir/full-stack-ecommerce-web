<?php
/**
 * ShopSphere - AJAX Cart API Endpoint
 *
 * Handles asynchronous shopping cart mutations:
 * - Adding items (with optional variants)
 * - Updating quantities with live stock checks
 * - Removing individual line items
 * - Applying and removing coupon codes
 * - Clearing the active cart
 *
 * Returns standardized JSON payloads consumed by assets/js/main.js
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
require_once dirname(__DIR__) . '/classes/CouponService.php';
require_once dirname(__DIR__) . '/classes/CartService.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
        'message' => 'Invalid or expired security token. Please refresh the page.',
    ], 403);
}

// Initialize Cart Service
try {
    $db = Database::getInstance();
    $cartService = new CartService($db);
    $userId = get_current_user_id();
    $sessionId = session_id();

    // Retrieve active cart
    $cart = $cartService->getCart($userId, $sessionId);
    $cartId = (int) $cart['id'];

    $action = trim((string) ($_POST['action'] ?? ''));

    switch ($action) {
        case 'add':
            $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
            $variantId = !empty($_POST['variant_id']) ? (int) $_POST['variant_id'] : null;
            $quantity  = isset($_POST['quantity']) ? max(1, (int) $_POST['quantity']) : 1;

            if ($productId <= 0) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid product identifier.',
                ], 400);
            }

            $result = $cartService->addItem($cartId, $productId, $variantId, $quantity);
            $cartCount = $cartService->getCartCount($userId, $sessionId);

            if ($result['success']) {
                $totals = $cartService->getCartTotal($cartId, $userId);
                json_response([
                    'success'    => true,
                    'message'    => $result['message'],
                    'cart_count' => $cartCount,
                    'item_id'    => $result['item_id'] ?? null,
                    'subtotal'   => format_price($totals['subtotal']),
                    'total'      => format_price($totals['total']),
                ]);
            } else {
                json_response([
                    'success'    => false,
                    'message'    => $result['message'],
                    'cart_count' => $cartCount,
                ], 400);
            }
            break;

        case 'update':
            $itemId   = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
            $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1;

            if ($itemId <= 0) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid line item identifier.',
                ], 400);
            }

            $updateResult = $cartService->updateItemQuantity($itemId, $cartId, $quantity);
            $cartCount = $cartService->getCartCount($userId, $sessionId);
            $totals = $cartService->getCartTotal($cartId, $userId);

            if ($updateResult['success']) {
                // Calculate line total for the updated item
                $itemTotal = 'Rs. 0.00';
                $items = $cartService->getCartItems($cartId);
                foreach ($items as $it) {
                    if ((int) $it['item_id'] === $itemId) {
                        $itemTotal = format_price($it['line_total']);
                        break;
                    }
                }

                $shippingFormatted = 'Rs. 0.00';

                json_response([
                    'success'      => true,
                    'message'      => $updateResult['message'],
                    'cart_count'   => $cartCount,
                    'item_total'   => $itemTotal,
                    'subtotal'     => format_price($totals['subtotal']),
                    'discount'     => format_price($totals['discount_amount']),
                    'discount_raw' => (float) $totals['discount_amount'],
                    'shipping'     => $shippingFormatted,
                    'total'        => format_price($totals['total']),
                ]);
            } else {
                json_response([
                    'success'    => false,
                    'message'    => $updateResult['message'],
                    'cart_count' => $cartCount,
                ], 400);
            }
            break;

        case 'remove':
            $itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

            if ($itemId <= 0) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid line item identifier.',
                ], 400);
            }

            $removed = $cartService->removeItem($itemId, $cartId);
            $cartCount = $cartService->getCartCount($userId, $sessionId);
            $totals = $cartService->getCartTotal($cartId, $userId);

            if ($removed) {
                json_response([
                    'success'      => true,
                    'message'      => 'Item removed from cart.',
                    'cart_count'   => $cartCount,
                    'subtotal'     => format_price($totals['subtotal']),
                    'discount'     => format_price($totals['discount_amount']),
                    'discount_raw' => (float) $totals['discount_amount'],
                    'shipping'     => 'Rs. 0.00',
                    'total'        => format_price($totals['total']),
                ]);
            } else {
                json_response([
                    'success' => false,
                    'message' => 'Item could not be found or removed.',
                ], 404);
            }
            break;

        case 'apply_coupon':
            $couponCode = trim((string) ($_POST['coupon_code'] ?? ''));

            if ($couponCode === '') {
                json_response([
                    'success' => false,
                    'message' => 'Please enter a valid coupon code.',
                ], 400);
            }

            $couponResult = $cartService->applyCoupon($cartId, $couponCode, $userId);
            $totals = $cartService->getCartTotal($cartId, $userId);

            if ($couponResult['success']) {
                json_response([
                    'success'      => true,
                    'message'      => $couponResult['message'],
                    'subtotal'     => format_price($totals['subtotal']),
                    'discount'     => format_price($totals['discount_amount']),
                    'discount_raw' => (float) $totals['discount_amount'],
                    'shipping'     => 'Rs. 0.00',
                    'total'        => format_price($totals['total']),
                    'redirect'     => false,
                ]);
            } else {
                json_response([
                    'success' => false,
                    'message' => $couponResult['message'],
                ], 400);
            }
            break;

        case 'remove_coupon':
            $cartService->removeCoupon($cartId);
            $totals = $cartService->getCartTotal($cartId, $userId);

            json_response([
                'success'      => true,
                'message'      => 'Coupon has been removed.',
                'subtotal'     => format_price($totals['subtotal']),
                'discount'     => format_price(0),
                'discount_raw' => 0.0,
                'shipping'     => 'Rs. 0.00',
                'total'        => format_price($totals['total']),
            ]);
            break;

        case 'clear':
            $cartService->clearCart($cartId);
            json_response([
                'success'      => true,
                'message'      => 'Cart has been cleared.',
                'cart_count'   => 0,
                'subtotal'     => format_price(0),
                'discount'     => format_price(0),
                'discount_raw' => 0.0,
                'shipping'     => 'Rs. 0.00',
                'total'        => format_price(0),
            ]);
            break;

        default:
            json_response([
                'success' => false,
                'message' => 'Unrecognized action specified.',
            ], 400);
            break;
    }
} catch (Throwable $e) {
    error_log('[API Cart Exception] ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'An internal error occurred while processing your cart request.',
        'error'   => $e->getMessage(),
    ], 500);
}
