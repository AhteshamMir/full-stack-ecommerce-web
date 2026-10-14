<?php
/**
 * ShopSphere - Transactional Order Processor
 *
 * Implements atomic end-to-end multi-vendor checkout processing:
 * - Session & CSRF validation
 * - Dynamic shipping address resolution (saved address or newly entered)
 * - Pessimistic product & variant locking (SELECT FOR UPDATE)
 * - Strict real-time stock validation
 * - Dynamic coupon discount resolution & redemption tracking
 * - Unique order number generation via generate_order_number()
 * - Master order creation with address JSON snapshots
 * - Vendor sub-order slicing with hierarchical commission calculation
 * - Immutable order_items price & earning snapshots
 * - Atomic stock decrements with inventory_movements audit trail (type='sale')
 * - Gateway payment transaction recording (Simulated Card / COD)
 * - Lifecycle status logging in order_status_history
 * - Automated in-app notifications for participating vendors and customer
 * - Shopping cart flushing and redirect to order confirmation
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
require_once dirname(__DIR__) . '/classes/CartService.php';
require_once dirname(__DIR__) . '/classes/CouponService.php';
require_once dirname(__DIR__) . '/classes/CommissionService.php';
require_once dirname(__DIR__) . '/classes/NotificationService.php';

// Enforce POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url('checkout.php'));
}

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Enforce authenticated user session
if (!is_logged_in()) {
    flash('error', 'Please log in to complete your checkout.');
    redirect(url('login.php'));
}

$userId = (int) get_current_user_id();

// 2. Validate CSRF token
$csrfToken = $_POST['csrf_token'] ?? null;
if (!is_string($csrfToken) || !validate_csrf_token($csrfToken)) {
    flash('error', 'Your session or security token has expired. Please try placing your order again.');
    redirect(url('checkout.php'));
}

$pdo = get_db_connection();

// 3. Idempotency verification to prevent duplicate charge / order placement
$idempotencyKey = sanitize_string($_POST['idempotency_key'] ?? '');
if ($idempotencyKey !== '') {
    $idempStmt = $pdo->prepare('SELECT order_number FROM orders WHERE idempotency_key = ? AND user_id = ? LIMIT 1');
    $idempStmt->execute([
        $idempotencyKey,
        $userId,
    ]);
    $existingOrderNumber = $idempStmt->fetchColumn();

    if ($existingOrderNumber) {
        redirect(url('order-success.php?order_number=' . urlencode((string) $existingOrderNumber)));
    }
}

// 4. Validate Address: Either existing address ownership or create new address record
$addressIdInput = trim((string) ($_POST['address_id'] ?? ''));
$addressId = null;

if ($addressIdInput === 'new' || empty($addressIdInput)) {
    // New address input fields
    $fullName     = sanitize_string($_POST['new_full_name'] ?? '');
    $phone        = sanitize_string($_POST['new_phone'] ?? '');
    $addressLine1 = sanitize_string($_POST['new_address_line1'] ?? '');
    $addressLine2 = sanitize_string($_POST['new_address_line2'] ?? '');
    $city         = sanitize_string($_POST['new_city'] ?? '');
    $state        = sanitize_string($_POST['new_state'] ?? '');
    $postalCode   = sanitize_string($_POST['new_postal_code'] ?? '');
    $country      = sanitize_string($_POST['new_country'] ?? 'Pakistan');
    $label        = sanitize_string($_POST['new_label'] ?? 'Home');

    if ($label === '') {
        $label = 'Home';
    }
    if ($country === '') {
        $country = 'Pakistan';
    }

    if ($fullName === '' || $phone === '' || $addressLine1 === '' || $city === '' || $state === '' || $postalCode === '') {
        flash('error', 'Please fill in all required shipping address fields.');
        redirect(url('checkout.php'));
    }

    if (!validate_phone($phone)) {
        flash('error', 'Please provide a valid shipping contact phone number.');
        redirect(url('checkout.php'));
    }

    // Check if user has any existing addresses
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM addresses WHERE user_id = ?');
    $countStmt->execute([$userId]);
    $isFirst = ((int) $countStmt->fetchColumn()) === 0;
    $saveAsDefault = $isFirst || !empty($_POST['save_as_default']);

    if ($saveAsDefault) {
        $clearDef = $pdo->prepare('UPDATE addresses SET is_default = 0 WHERE user_id = ?');
        $clearDef->execute([$userId]);
    }

    $insAddrSql = 'INSERT INTO addresses (
        user_id, label, full_name, phone, address_line1, address_line2,
        city, state, postal_code, country, is_default, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )';

    $insAddrStmt = $pdo->prepare($insAddrSql);
    $insAddrStmt->execute([
        $userId,
        $label,
        $fullName,
        $phone,
        $addressLine1,
        $addressLine2 !== '' ? $addressLine2 : null,
        $city,
        $state,
        $postalCode,
        $country,
        $saveAsDefault ? 1 : 0,
    ]);

    $addressId = (int) $pdo->lastInsertId();
} else {
    $addressId = (int) $addressIdInput;
    if ($addressId <= 0) {
        flash('error', 'Please select a valid shipping address.');
        redirect(url('checkout.php'));
    }

    // Enforce address ownership
    $chkAddr = $pdo->prepare('SELECT id FROM addresses WHERE id = ? AND user_id = ? LIMIT 1');
    $chkAddr->execute([
        $addressId,
        $userId,
    ]);
    if (!$chkAddr->fetch()) {
        flash('error', 'The selected shipping address could not be found in your account.');
        redirect(url('checkout.php'));
    }
}

// 5. Validate payment method
$paymentMethod = strtolower(trim((string) ($_POST['payment_method'] ?? 'cod')));
if (!in_array($paymentMethod, ['cod', 'card'], true)) {
    flash('error', 'Please choose a valid payment method (Cash on Delivery or Debit/Credit Card).');
    redirect(url('checkout.php'));
}

$orderNotes = sanitize_string($_POST['notes'] ?? '');

// Initialize helpers - use the same PDO connection to avoid parameter binding issues
$couponService = new CouponService($pdo);
$commissionService = new CommissionService($pdo);
$notificationService = new NotificationService($pdo);

// 6. Execute Transactional Checkout Process
$pdo->beginTransaction();

try {
    // Fetch and lock active cart for user
    $cartStmt = $pdo->prepare('SELECT * FROM carts WHERE user_id = ? LIMIT 1 FOR UPDATE');
    $cartStmt->execute([$userId]);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);

    if (!$cart) {
        throw new RuntimeException('Shopping cart not found.');
    }

    $cartId = (int) $cart['id'];

    // Fetch cart line items
    $itemsStmt = $pdo->prepare('SELECT * FROM cart_items WHERE cart_id = ?');
    $itemsStmt->execute([$cartId]);
    $cartItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($cartItems)) {
        throw new RuntimeException('Your shopping cart is empty.');
    }

    // Step a & b: Lock product rows (SELECT FOR UPDATE) and validate stock >= requested
    $validatedItems = [];
    $subtotal = 0.00;

    foreach ($cartItems as $cItem) {
        $productId = (int) $cItem['product_id'];
        $variantId = $cItem['variant_id'] !== null ? (int) $cItem['variant_id'] : null;
        $qty       = (int) $cItem['quantity'];

        if ($qty <= 0) {
            continue;
        }

        // a. Lock product row
        $pStmt = $pdo->prepare('SELECT id, seller_id, category_id, name, slug, price, sku, status, stock_quantity FROM products WHERE id = ? FOR UPDATE');
        $pStmt->execute([$productId]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || $product['status'] !== 'approved') {
            $pName = $product['name'] ?? 'Selected item';
            throw new RuntimeException("Product '{$pName}' is currently unavailable.");
        }

        $availableStock = (int) $product['stock_quantity'];
        $unitPrice = (float) $product['price'];
        $sku = $product['sku'];
        $variantInfo = null;

        if ($variantId !== null) {
            // Lock variant row
            $vStmt = $pdo->prepare('SELECT id, name, value, sku_suffix, price_adjustment, stock_quantity, is_active FROM product_variants WHERE id = ? AND product_id = ? FOR UPDATE');
            $vStmt->execute([
                $variantId,
                $productId,
            ]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);

            if (!$variant || !(bool) $variant['is_active']) {
                throw new RuntimeException("The selected variant for '{$product['name']}' is no longer available.");
            }

            $availableStock = (int) $variant['stock_quantity'];
            $priceAdj = (float) $variant['price_adjustment'];
            $unitPrice = max(0.00, $unitPrice + $priceAdj);
            $variantInfo = "{$variant['name']}: {$variant['value']}";
            if (!empty($variant['sku_suffix'])) {
                $sku = $sku ? "{$sku}-{$variant['sku_suffix']}" : $variant['sku_suffix'];
            }
        }

        // b. Validate stock >= requested
        if ($qty > $availableStock) {
            throw new RuntimeException("Insufficient stock for '{$product['name']}'. Only {$availableStock} available (requested: {$qty}).");
        }

        $lineTotal = round($unitPrice * $qty, 2);
        $subtotal += $lineTotal;

        $validatedItems[] = [
            'product_id'   => $productId,
            'variant_id'   => $variantId,
            'seller_id'    => (int) $product['seller_id'],
            'category_id'  => (int) $product['category_id'],
            'product_name' => (string) $product['name'],
            'variant_info' => $variantInfo,
            'sku'          => $sku,
            'quantity'     => $qty,
            'unit_price'   => $unitPrice,
            'total_price'  => $lineTotal,
        ];
    }

    $subtotal = round($subtotal, 2);

    // Step c: Calculate totals with coupon
    $couponId = null;
    $couponCode = !empty($cart['coupon_code']) ? (string) $cart['coupon_code'] : null;
    $couponDiscount = 0.00;

    if ($couponCode !== null) {
        $couponCheck = $couponService->validateCoupon($couponCode, $userId, $subtotal, $validatedItems);
        if ($couponCheck['valid'] && !empty($couponCheck['coupon'])) {
            $couponId = (int) $couponCheck['coupon']['id'];
            $couponDiscount = round((float) $couponCheck['discount'], 2);
        } else {
            // Disregard expired or non-applicable coupon
            $couponCode = null;
        }
    }

    $shippingAmount = 0.00;
    $taxAmount = 0.00;
    $totalAmount = round(max(0.00, ($subtotal - $couponDiscount) + $shippingAmount + $taxAmount), 2);

    // Snapshot shipping address from addresses table
    $addrFetch = $pdo->prepare('SELECT * FROM addresses WHERE id = ? AND user_id = ? LIMIT 1');
    $addrFetch->execute([$addressId, $userId]);
    $addressData = $addrFetch->fetch(PDO::FETCH_ASSOC);

    if (!$addressData) {
        throw new RuntimeException('Shipping address not found.');
    }

    $addressSnapshot = json_encode([
        'label'         => $addressData['label'],
        'full_name'     => $addressData['full_name'],
        'phone'         => $addressData['phone'],
        'address_line1' => $addressData['address_line1'],
        'address_line2' => $addressData['address_line2'],
        'city'          => $addressData['city'],
        'state'         => $addressData['state'],
        'postal_code'   => $addressData['postal_code'],
        'country'       => $addressData['country'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Step d: Generate order_number via generate_order_number()
    $orderNumber = generate_order_number();

    // Step e: Insert orders record
    $paymentStatus = ($paymentMethod === 'card') ? 'paid' : 'pending';
    $orderStatus   = 'pending';
    $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $insOrderSql = 'INSERT INTO orders (
        order_number, user_id, address_id, shipping_address, billing_address,
        subtotal, discount_amount, shipping_amount, tax_amount, total_amount,
        coupon_id, coupon_code, coupon_discount, payment_method, payment_status,
        order_status, notes, ip_address, idempotency_key, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )';

    $insOrderStmt = $pdo->prepare($insOrderSql);
    $insOrderStmt->execute([
        $orderNumber,
        $userId,
        $addressId,
        $addressSnapshot,
        $addressSnapshot,
        $subtotal,
        $couponDiscount,
        $shippingAmount,
        $taxAmount,
        $totalAmount,
        $couponId,
        $couponCode,
        $couponDiscount,
        $paymentMethod,
        $paymentStatus,
        $orderStatus,
        $orderNotes !== '' ? $orderNotes : null,
        $ipAddress,
        $idempotencyKey !== '' ? $idempotencyKey : null,
    ]);

    $orderId = (int) $pdo->lastInsertId();

    // Step f: Group items by seller, create seller_orders with commission calculation
    $sellerGrouped = [];
    foreach ($validatedItems as $vItem) {
        $sId = $vItem['seller_id'];
        if (!isset($sellerGrouped[$sId])) {
            $sellerGrouped[$sId] = [
                'items'    => [],
                'subtotal' => 0.00,
            ];
        }
        $sellerGrouped[$sId]['items'][] = $vItem;
        $sellerGrouped[$sId]['subtotal'] += $vItem['total_price'];
    }

    $sellerOrderMap = [];

    $insSellerOrderSql = 'INSERT INTO seller_orders (
        order_id, seller_id, subtotal, discount_amount, shipping_amount,
        commission_amount, seller_earning, status, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )';
    $insSellerOrderStmt = $pdo->prepare($insSellerOrderSql);

    foreach ($sellerGrouped as $sellerId => $group) {
        $sellerSubtotal = round($group['subtotal'], 2);

        // Pro-rate coupon discount across sellers
        $sellerDiscount = 0.00;
        if ($subtotal > 0.00 && $couponDiscount > 0.00) {
            $sellerDiscount = round(($sellerSubtotal / $subtotal) * $couponDiscount, 2);
        }

        $sellerNetBasis = max(0.00, $sellerSubtotal - $sellerDiscount);

        // Calculate commission
        $commissionRate   = $commissionService->getCommissionRate($sellerId);
        $commissionAmount = $commissionService->calculateCommission($sellerNetBasis, $commissionRate);
        $sellerEarning    = round(max(0.00, $sellerNetBasis - $commissionAmount), 2);

        $insSellerOrderStmt->execute([
            $orderId,
            $sellerId,
            $sellerSubtotal,
            $sellerDiscount,
            0.00,
            $commissionAmount,
            $sellerEarning,
        ]);

        $sellerOrderId = (int) $pdo->lastInsertId();
        $sellerOrderMap[$sellerId] = [
            'id'              => $sellerOrderId,
            'subtotal'        => $sellerSubtotal,
            'discount_amount' => $sellerDiscount,
        ];
    }

    // Step g: Create order_items with price/commission snapshots
    $insOrderItemSql = 'INSERT INTO order_items (
        order_id, seller_order_id, product_id, variant_id, seller_id,
        product_name, variant_info, sku, quantity, unit_price, total_price,
        discount_amount, commission_rate, commission_amount, seller_earning,
        status, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )';
    $insOrderItemStmt = $pdo->prepare($insOrderItemSql);

    // Prepare statement for inventory decrement and movement log
    $updProductStockStmt = $pdo->prepare('UPDATE products SET
        stock_quantity = GREATEST(0, stock_quantity - ?),
        sales_count = sales_count + ?,
        updated_at = UTC_TIMESTAMP()
        WHERE id = ?');

    $updVariantStockStmt = $pdo->prepare('UPDATE product_variants SET
        stock_quantity = GREATEST(0, stock_quantity - ?),
        updated_at = UTC_TIMESTAMP()
        WHERE id = ?');

    // Step i statement: Log inventory_movements (type='sale')
    $insInvMovementSql = 'INSERT INTO inventory_movements (
        product_id, variant_id, quantity_change, type, reference_type, reference_id, reason, created_by, created_at
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP()
    )';
    $insInvMovementStmt = $pdo->prepare($insInvMovementSql);

    foreach ($validatedItems as $vItem) {
        $sellerId      = $vItem['seller_id'];
        $sellerOrderId = $sellerOrderMap[$sellerId]['id'];
        $soSubtotal    = $sellerOrderMap[$sellerId]['subtotal'];
        $soDiscount    = $sellerOrderMap[$sellerId]['discount_amount'];

        // Pro-rate line item discount
        $itemDiscount = 0.00;
        if ($soSubtotal > 0.00 && $soDiscount > 0.00) {
            $itemDiscount = round(($vItem['total_price'] / $soSubtotal) * $soDiscount, 2);
        }

        $itemNetBasis = max(0.00, $vItem['total_price'] - $itemDiscount);

        // Commission rate for product category and vendor
        $categoryId = $vItem['category_id'] ?? null;
        $itemCommRate   = $commissionService->getCommissionRate($sellerId, $categoryId);
        $itemCommAmount = $commissionService->calculateCommission($itemNetBasis, $itemCommRate);
        $itemEarning    = round(max(0.00, $itemNetBasis - $itemCommAmount), 2);

        // Insert item record
        $insOrderItemStmt->execute([
            $orderId,
            $sellerOrderId,
            $vItem['product_id'],
            $vItem['variant_id'],
            $sellerId,
            $vItem['product_name'],
            $vItem['variant_info'],
            $vItem['sku'],
            $vItem['quantity'],
            $vItem['unit_price'],
            $vItem['total_price'],
            $itemDiscount,
            $itemCommRate,
            $itemCommAmount,
            $itemEarning,
        ]);

        // Step h: Decrement products.stock_quantity (and variant if applicable)
        $updProductStockStmt->execute([
            $vItem['quantity'],
            $vItem['quantity'],
            $vItem['product_id'],
        ]);

        if ($vItem['variant_id'] !== null) {
            $updVariantStockStmt->execute([
                $vItem['quantity'],
                $vItem['variant_id'],
            ]);
        }

        // Step i: Log inventory_movements (type='sale')
        // Negative quantity_change reflects stock reduction
        $insInvMovementStmt->execute([
            $vItem['product_id'],
            $vItem['variant_id'],
            -$vItem['quantity'],
            'sale',
            'order',
            $orderId,
            "Customer order fulfillment for #{$orderNumber}",
            $userId,
        ]);
    }

    // Step j: Record coupon_redemptions if applicable
    if ($couponId !== null && $couponDiscount > 0.00) {
        $insCouponRedemption = $pdo->prepare('INSERT INTO coupon_redemptions (
            coupon_id, user_id, order_id, discount_amount, created_at
        ) VALUES (
            ?, ?, ?, ?, UTC_TIMESTAMP()
        )');
        $insCouponRedemption->execute([
            $couponId,
            $userId,
            $orderId,
            $couponDiscount,
        ]);

        $updCouponCount = $pdo->prepare('UPDATE coupons SET used_count = used_count + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $updCouponCount->execute([$couponId]);
    }

    // Step k: Create payments record
    $insPaymentSql = 'INSERT INTO payments (
        order_id, method, transaction_id, amount, currency, status,
        gateway_response, is_simulated, created_at, updated_at
    ) VALUES (
        ?, ?, ?, ?, "PKR", ?,
        ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP()
    )';

    if ($paymentMethod === 'card') {
        $transactionId = 'TXN-CARD-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $payStatus = 'completed';
        $isSimulated = 1;
        $gatewayPayload = json_encode([
            'status'         => 'success',
            'authorization'  => 'SIM-AUTH-' . rand(100000, 999999),
            'message'        => 'Simulated card transaction captured successfully.',
            'timestamp'      => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    } else {
        $transactionId = 'TXN-COD-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $payStatus = 'pending';
        $isSimulated = 0;
        $gatewayPayload = json_encode([
            'status'    => 'pending',
            'message'   => 'Cash on Delivery payment collection scheduled at fulfillment.',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    $insPaymentStmt = $pdo->prepare($insPaymentSql);
    $insPaymentStmt->execute([
        $orderId,
        $paymentMethod,
        $transactionId,
        $totalAmount,
        $payStatus,
        $gatewayPayload,
        $isSimulated,
    ]);

    // Step l: Insert order_status_history
    $insHistorySql = 'INSERT INTO order_status_history (
        order_id, seller_order_id, old_status, new_status, comment, created_by, created_at
    ) VALUES (
        ?, NULL, NULL, "pending", "Order placed by customer", ?, UTC_TIMESTAMP()
    )';
    $insHistoryStmt = $pdo->prepare($insHistorySql);
    $insHistoryStmt->execute([
        $orderId,
        $userId,
    ]);

    // Step m: Create notifications for sellers and buyer
    foreach ($sellerOrderMap as $sellerId => $soData) {
        $soSubtotalFormatted = number_format((float) $soData['subtotal'], 2);
        $notificationService->create(
            (int) $sellerId,
            'order',
            "New Order Received: #{$orderNumber}",
            "You have received a new customer order #{$orderNumber} amounting to Rs. {$soSubtotalFormatted}. Please review and prepare fulfillment.",
            "/seller/order-details.php?id={$soData['id']}"
        );
    }

    // Buyer notification
    $totalFormatted = number_format($totalAmount, 2);
    $notificationService->create(
        $userId,
        'order',
        "Order Placed Successfully: #{$orderNumber}",
        "Thank you for shopping at ShopSphere! Your order #{$orderNumber} totaling Rs. {$totalFormatted} has been placed successfully.",
        "/order-details.php?order_number=" . urlencode($orderNumber)
    );

    // Step n: Clear cart
    $delCartItems = $pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
    $delCartItems->execute([$cartId]);

    $resetCartCoupon = $pdo->prepare('UPDATE carts SET coupon_code = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    $resetCartCoupon->execute([$cartId]);

    // Step o: Commit transaction
    $pdo->commit();

    // Redirect to confirmation page on success
    redirect(url('order-success.php?order_number=' . urlencode($orderNumber)));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[ShopSphere Checkout Processor Error] ' . $e->getMessage());
    flash('error', $e->getMessage());
    redirect(url('checkout.php'));
}
