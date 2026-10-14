<?php
/**
 * ShopSphere - Shopping Cart Management Service
 *
 * Handles persistent shopping carts for authenticated and guest users,
 * real-time server-side price calculations, stock checks, line items, and coupons.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CouponService.php';

class CartService
{
    /**
     * Database connection instance.
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Coupon service instance.
     *
     * @var CouponService
     */
    protected CouponService $couponService;

    /**
     * Constructor accepts optional PDO and CouponService instances.
     *
     * @param PDO|null $pdo Optional PDO instance.
     * @param CouponService|null $couponService Optional CouponService instance.
     */
    public function __construct(?PDO $pdo = null, ?CouponService $couponService = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->couponService = $couponService ?? new CouponService($this->pdo);
    }

    /**
     * Retrieve or initialize an active cart for an authenticated user or guest session.
     *
     * @param int|null $userId Authenticated user primary key ID.
     * @param string|null $sessionId Guest session token.
     * @return array<string, mixed> Active cart database record.
     */
    public function getCart(?int $userId = null, ?string $sessionId = null): array
    {
        if ($userId === null && empty($sessionId)) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $sessionId = session_id();
        }

        // 1. Look for user cart if userId provided
        if ($userId !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM carts WHERE user_id = :user_id LIMIT 1');
            $stmt->execute([':user_id' => $userId]);
            $cart = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cart) {
                // If there's an existing guest cart for this session, merge items into user cart
                if (!empty($sessionId)) {
                    $this->mergeSessionCart((int) $cart['id'], $sessionId);
                }
                return $cart;
            }

            // If user has no cart but session cart exists, claim session cart for user
            if (!empty($sessionId)) {
                $claimStmt = $this->pdo->prepare('SELECT * FROM carts WHERE session_id = :session_id AND user_id IS NULL LIMIT 1');
                $claimStmt->execute([':session_id' => $sessionId]);
                $sessionCart = $claimStmt->fetch(PDO::FETCH_ASSOC);

                if ($sessionCart) {
                    $updateStmt = $this->pdo->prepare('UPDATE carts SET user_id = :user_id, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                    $updateStmt->execute([
                        ':user_id' => $userId,
                        ':id'      => $sessionCart['id'],
                    ]);
                    $sessionCart['user_id'] = $userId;
                    return $sessionCart;
                }
            }

            // Create new cart for user
            $insertStmt = $this->pdo->prepare('INSERT INTO carts (user_id, session_id, created_at, updated_at) VALUES (:user_id, :session_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $insertStmt->execute([
                ':user_id'    => $userId,
                ':session_id' => $sessionId,
            ]);
            $cartId = (int) $this->pdo->lastInsertId();

            return [
                'id'          => $cartId,
                'user_id'     => $userId,
                'session_id'  => $sessionId,
                'coupon_code' => null,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
        }

        // 2. Guest user: lookup by sessionId
        $stmt = $this->pdo->prepare('SELECT * FROM carts WHERE session_id = :session_id AND user_id IS NULL LIMIT 1');
        $stmt->execute([':session_id' => $sessionId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cart) {
            return $cart;
        }

        $insertStmt = $this->pdo->prepare('INSERT INTO carts (user_id, session_id, created_at, updated_at) VALUES (NULL, :session_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $insertStmt->execute([':session_id' => $sessionId]);
        $cartId = (int) $this->pdo->lastInsertId();

        return [
            'id'          => $cartId,
            'user_id'     => null,
            'session_id'  => $sessionId,
            'coupon_code' => null,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Merge items from a guest session cart into a registered user's cart.
     *
     * @param int $targetCartId Authenticated user cart ID.
     * @param string $sessionId Guest session token.
     * @return void
     */
    protected function mergeSessionCart(int $targetCartId, string $sessionId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM carts WHERE session_id = :session_id AND id != :target_id AND user_id IS NULL LIMIT 1');
        $stmt->execute([
            ':session_id' => $sessionId,
            ':target_id'  => $targetCartId,
        ]);
        $guestCartId = $stmt->fetchColumn();

        if (!$guestCartId) {
            return;
        }

        $guestItemsStmt = $this->pdo->prepare('SELECT product_id, variant_id, quantity FROM cart_items WHERE cart_id = :cart_id');
        $guestItemsStmt->execute([':cart_id' => $guestCartId]);
        $guestItems = $guestItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($guestItems as $item) {
            try {
                $this->addItem(
                    $targetCartId,
                    (int) $item['product_id'],
                    $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                    (int) $item['quantity']
                );
            } catch (Throwable) {
                // Skip invalid or out-of-stock items during merge
            }
        }

        // Clean up guest cart
        $delCart = $this->pdo->prepare('DELETE FROM carts WHERE id = :id');
        $delCart->execute([':id' => $guestCartId]);
    }

    /**
     * Fetch all line items within a cart with product, variant, image, and price details.
     *
     * @param int $cartId Target cart primary key ID.
     * @return array<int, array<string, mixed>> List of detailed cart items.
     */
    public function getCartItems(int $cartId): array
    {
        $sql = "SELECT 
            ci.id AS item_id,
            ci.cart_id,
            ci.product_id,
            ci.variant_id,
            ci.quantity,
            p.name AS product_name,
            p.slug AS product_slug,
            p.price AS base_price,
            p.compare_price,
            p.status AS product_status,
            p.stock_quantity AS product_stock,
            p.seller_id,
            p.category_id,
            sp.store_name,
            sp.store_slug,
            pv.name AS variant_name,
            pv.value AS variant_value,
            pv.price_adjustment,
            pv.stock_quantity AS variant_stock,
            pv.is_active AS variant_active,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        LEFT JOIN product_variants pv ON pv.id = ci.variant_id
        WHERE ci.cart_id = :cart_id
        ORDER BY ci.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':cart_id' => $cartId]);
        $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rawItems as $item) {
            $basePrice       = (float) $item['base_price'];
            $priceAdjustment = (float) ($item['price_adjustment'] ?? 0.00);
            $unitPrice       = max(0.00, $basePrice + $priceAdjustment);
            $qty             = (int) $item['quantity'];
            $lineTotal       = round($unitPrice * $qty, 2);

            $availableStock  = $item['variant_id'] !== null ? (int) $item['variant_stock'] : (int) $item['product_stock'];
            $isAvailable     = ($item['product_status'] === 'approved') &&
                               ($item['variant_id'] === null || (bool) $item['variant_active']) &&
                               ($availableStock >= $qty);

            $item['id']              = (int) $item['item_id'];
            $item['name']            = $item['product_name'];
            $item['slug']            = $item['product_slug'];
            $item['image_path']      = $item['primary_image'];
            $item['stock_quantity']  = $availableStock;
            $item['unit_price']      = $unitPrice;
            $item['line_total']      = $lineTotal;
            $item['available_stock'] = $availableStock;
            $item['is_available']    = $isAvailable;

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Add an item to the cart or increment quantity if already present.
     *
     * @param int $cartId Cart primary key ID.
     * @param int $productId Product primary key ID.
     * @param int|null $variantId Optional product variant ID.
     * @param int $quantity Desired quantity (minimum 1).
     * @return array{success: bool, item_id?: int, message: string}
     * @throws InvalidArgumentException On invalid parameters.
     */
    public function addItem(int $cartId, int $productId, ?int $variantId = null, int $quantity = 1): array
    {
        $quantity = max(1, $quantity);

        // Fetch product
        $pStmt = $this->pdo->prepare('SELECT id, status, stock_quantity FROM products WHERE id = :id LIMIT 1');
        $pStmt->execute([':id' => $productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || $product['status'] !== 'approved') {
            return ['success' => false, 'message' => 'This product is currently unavailable.'];
        }

        $availableStock = (int) $product['stock_quantity'];

        // Validate variant if provided
        if ($variantId !== null) {
            $vStmt = $this->pdo->prepare('SELECT id, is_active, stock_quantity FROM product_variants WHERE id = :id AND product_id = :product_id LIMIT 1');
            $vStmt->execute([
                ':id'         => $variantId,
                ':product_id' => $productId,
            ]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);

            if (!$variant || !(bool) $variant['is_active']) {
                return ['success' => false, 'message' => 'The selected product variant is unavailable.'];
            }

            $availableStock = (int) $variant['stock_quantity'];
        }

        // Check if item already exists in cart
        if ($variantId !== null) {
            $checkSql = 'SELECT id, quantity FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id AND variant_id = :variant_id LIMIT 1';
            $checkParams = [
                ':cart_id'    => $cartId,
                ':product_id' => $productId,
                ':variant_id' => $variantId,
            ];
        } else {
            $checkSql = 'SELECT id, quantity FROM cart_items WHERE cart_id = :cart_id AND product_id = :product_id AND variant_id IS NULL LIMIT 1';
            $checkParams = [
                ':cart_id'    => $cartId,
                ':product_id' => $productId,
            ];
        }

        $checkStmt = $this->pdo->prepare($checkSql);
        $checkStmt->execute($checkParams);
        $existingItem = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingItem) {
            $newQuantity = (int) $existingItem['quantity'] + $quantity;

            if ($newQuantity > $availableStock) {
                return [
                    'success' => false,
                    'message' => "Only {$availableStock} unit(s) available in stock.",
                ];
            }

            $updateSql = 'UPDATE cart_items SET quantity = :quantity, updated_at = UTC_TIMESTAMP() WHERE id = :id';
            $updateStmt = $this->pdo->prepare($updateSql);
            $updateStmt->execute([
                ':quantity' => $newQuantity,
                ':id'       => $existingItem['id'],
            ]);

            return [
                'success' => true,
                'item_id' => (int) $existingItem['id'],
                'message' => 'Cart updated successfully.',
            ];
        }

        // New item check
        if ($quantity > $availableStock) {
            return [
                'success' => false,
                'message' => "Only {$availableStock} unit(s) available in stock.",
            ];
        }

        $insertSql = 'INSERT INTO cart_items (
            cart_id, product_id, variant_id, quantity, created_at, updated_at
        ) VALUES (
            :cart_id, :product_id, :variant_id, :quantity, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )';

        $insertStmt = $this->pdo->prepare($insertSql);
        $insertStmt->execute([
            ':cart_id'    => $cartId,
            ':product_id' => $productId,
            ':variant_id' => $variantId,
            ':quantity'   => $quantity,
        ]);

        $itemId = (int) $this->pdo->lastInsertId();

        return [
            'success' => true,
            'item_id' => $itemId,
            'message' => 'Item added to cart.',
        ];
    }

    /**
     * Update an item's quantity in the cart with stock verification.
     *
     * @param int $itemId Cart item primary key ID.
     * @param int $cartId Cart primary key ID.
     * @param int $quantity Desired new quantity (<= 0 deletes the line item).
     * @return array{success: bool, message: string}
     */
    public function updateItemQuantity(int $itemId, int $cartId, int $quantity): array
    {
        if ($quantity <= 0) {
            $this->removeItem($itemId, $cartId);
            return ['success' => true, 'message' => 'Item removed from cart.'];
        }

        // Fetch current item details with stock
        $stmt = $this->pdo->prepare('SELECT 
            ci.*, 
            p.stock_quantity AS product_stock,
            pv.stock_quantity AS variant_stock
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        LEFT JOIN product_variants pv ON pv.id = ci.variant_id
        WHERE ci.id = :id AND ci.cart_id = :cart_id
        LIMIT 1');

        $stmt->execute([':id' => $itemId, ':cart_id' => $cartId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return ['success' => false, 'message' => 'Item not found in cart.'];
        }

        $availableStock = $item['variant_id'] !== null ? (int) $item['variant_stock'] : (int) $item['product_stock'];

        if ($quantity > $availableStock) {
            return [
                'success' => false,
                'message' => "Only {$availableStock} unit(s) available in stock.",
            ];
        }

        $updateStmt = $this->pdo->prepare('UPDATE cart_items SET quantity = :quantity, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $updateStmt->execute([
            ':quantity' => $quantity,
            ':id'       => $itemId,
        ]);

        return ['success' => true, 'message' => 'Quantity updated successfully.'];
    }

    /**
     * Remove a line item from the cart.
     *
     * @param int $itemId Cart item ID.
     * @param int $cartId Cart ID.
     * @return bool True if item was removed.
     */
    public function removeItem(int $itemId, int $cartId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart_id');
        $stmt->execute([':id' => $itemId, ':cart_id' => $cartId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Retrieve the total item count (sum of quantities) for an active user or session cart.
     *
     * @param int|null $userId User primary key ID.
     * @param string|null $sessionId Guest session ID.
     * @return int Total units in cart.
     */
    public function getCartCount(?int $userId = null, ?string $sessionId = null): int
    {
        $cart = $this->getCart($userId, $sessionId);
        $cartId = (int) $cart['id'];

        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = :cart_id');
        $stmt->execute([':cart_id' => $cartId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Calculate cart subtotal, discount, and grand total using strictly database-persisted pricing.
     *
     * @param int $cartId Cart primary key ID.
     * @param int|null $userId Optional user ID for per-user coupon checks.
     * @return array{
     *   cart_id: int,
     *   subtotal: float,
     *   discount_amount: float,
     *   total: float,
     *   coupon_code: string|null,
     *   coupon_valid: bool,
     *   coupon_error: string|null,
     *   items_count: int
     * }
     */
    public function getCartTotal(int $cartId, ?int $userId = null): array
    {
        $items = $this->getCartItems($cartId);

        $subtotal = 0.00;
        $itemsCount = 0;

        foreach ($items as $item) {
            $subtotal += (float) $item['line_total'];
            $itemsCount += (int) $item['quantity'];
        }

        $subtotal = round($subtotal, 2);

        // Fetch cart to check for applied coupon code
        $cartStmt = $this->pdo->prepare('SELECT user_id, coupon_code FROM carts WHERE id = :id LIMIT 1');
        $cartStmt->execute([':id' => $cartId]);
        $cartData = $cartStmt->fetch(PDO::FETCH_ASSOC);

        $couponCode = !empty($cartData['coupon_code']) ? (string) $cartData['coupon_code'] : null;
        $evalUserId = $userId ?? ($cartData ? (int) $cartData['user_id'] : null);

        $discountAmount = 0.00;
        $couponValid    = false;
        $couponError    = null;

        if ($couponCode !== null && $subtotal > 0.00) {
            $couponResult = $this->couponService->validateCoupon($couponCode, $evalUserId, $subtotal, $items);
            if ($couponResult['valid']) {
                $discountAmount = (float) $couponResult['discount'];
                $couponValid = true;
            } else {
                $couponError = $couponResult['error'] ?? 'Coupon is not applicable.';
                // Auto-clear invalid coupon
                $this->removeCoupon($cartId);
                $couponCode = null;
            }
        }

        $discountAmount = round(min($subtotal, $discountAmount), 2);
        $grandTotal     = round(max(0.00, $subtotal - $discountAmount), 2);

        return [
            'cart_id'         => $cartId,
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'discount'        => $discountAmount,
            'total'           => $grandTotal,
            'coupon_code'     => $couponCode,
            'coupon_valid'    => $couponValid,
            'coupon_error'    => $couponError,
            'items_count'     => $itemsCount,
        ];
    }

    /**
     * Completely empty a cart.
     *
     * @param int $cartId Cart primary key ID.
     * @return void
     */
    public function clearCart(int $cartId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cart_id');
        $stmt->execute([':cart_id' => $cartId]);

        $resetCoupon = $this->pdo->prepare('UPDATE carts SET coupon_code = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $resetCoupon->execute([':id' => $cartId]);
    }

    /**
     * Validate and apply a promotional coupon to the shopping cart.
     *
     * @param int $cartId Cart primary key ID.
     * @param string $couponCode Voucher code string.
     * @param int|null $userId Optional user ID for per-user limits.
     * @return array{success: bool, discount: float, message: string}
     */
    public function applyCoupon(int $cartId, string $couponCode, ?int $userId = null): array
    {
        $cleanCode = strtoupper(trim($couponCode));
        if ($cleanCode === '') {
            return ['success' => false, 'discount' => 0.00, 'message' => 'Please provide a coupon code.'];
        }

        $items = $this->getCartItems($cartId);
        if (empty($items)) {
            return ['success' => false, 'discount' => 0.00, 'message' => 'Your cart is empty.'];
        }

        $subtotal = 0.00;
        foreach ($items as $item) {
            $subtotal += (float) $item['line_total'];
        }

        // Get user ID from cart if not passed
        if ($userId === null) {
            $uStmt = $this->pdo->prepare('SELECT user_id FROM carts WHERE id = :id LIMIT 1');
            $uStmt->execute([':id' => $cartId]);
            $uCol = $uStmt->fetchColumn();
            if ($uCol) {
                $userId = (int) $uCol;
            }
        }

        $val = $this->couponService->validateCoupon($cleanCode, $userId, $subtotal, $items);

        if (!$val['valid']) {
            return [
                'success'  => false,
                'discount' => 0.00,
                'message'  => $val['error'] ?? 'Coupon could not be applied.',
            ];
        }

        $stmt = $this->pdo->prepare('UPDATE carts SET coupon_code = :code, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        $stmt->execute([
            ':code' => $cleanCode,
            ':id'   => $cartId,
        ]);

        return [
            'success'  => true,
            'discount' => (float) $val['discount'],
            'message'  => sprintf('Coupon applied! You saved Rs. %s.', number_format((float) $val['discount'], 2)),
        ];
    }

    /**
     * Remove the active coupon from the cart.
     *
     * @param int $cartId Cart primary key ID.
     * @return bool True if coupon removed.
     */
    public function removeCoupon(int $cartId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE carts SET coupon_code = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        return $stmt->execute([':id' => $cartId]);
    }

    /**
     * Comprehensive validation check prior to commencing order creation and payment.
     *
     * @param int $cartId Cart primary key ID.
     * @return array{
     *   valid: bool,
     *   errors: array<int, string>,
     *   items: array<int, array<string, mixed>>,
     *   subtotal: float,
     *   totals: array<string, mixed>
     * }
     */
    public function validateCartForCheckout(int $cartId): array
    {
        $items = $this->getCartItems($cartId);
        $errors = [];

        if (empty($items)) {
            $errors[] = 'Your shopping cart is currently empty.';
            return [
                'valid'    => false,
                'errors'   => $errors,
                'items'    => [],
                'subtotal' => 0.00,
                'totals'   => $this->getCartTotal($cartId),
            ];
        }

        $subtotal = 0.00;

        foreach ($items as $item) {
            $name = htmlspecialchars((string) $item['product_name'], ENT_QUOTES, 'UTF-8');
            $qty = (int) $item['quantity'];

            if ($item['product_status'] !== 'approved') {
                $errors[] = "Product '{$name}' is no longer available for purchase.";
            }

            if ($item['variant_id'] !== null && empty($item['variant_active'])) {
                $errors[] = "The selected variant for '{$name}' is currently inactive.";
            }

            $avail = (int) $item['available_stock'];
            if ($qty > $avail) {
                if ($avail <= 0) {
                    $errors[] = "'{$name}' is currently out of stock.";
                } else {
                    $errors[] = "Only {$avail} unit(s) of '{$name}' remaining in stock (you have {$qty} in cart).";
                }
            }

            $subtotal += (float) $item['line_total'];
        }

        $totals = $this->getCartTotal($cartId);

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'items'    => $items,
            'subtotal' => round($subtotal, 2),
            'totals'   => $totals,
        ];
    }
}
