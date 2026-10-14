<?php
/**
 * ShopSphere - Detailed Customer Order View
 *
 * Displays full itemized invoice, seller breakdowns, financial breakdown,
 * address snapshots, order timeline lifecycle, and cancellation controls.
 *
 * @package ShopSphere\Customer
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/classes/OrderService.php';

// Enforce customer login
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();

// Accept ?id=... or ?order_number=...
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
$orderNumber = sanitize_string($_GET['order_number'] ?? '');

if (!$orderId && $orderNumber === '') {
    flash('error', 'Please specify an order ID or order number.');
    redirect(url('orders.php'));
}

// Fetch order and strictly verify customer ownership
$order = null;
try {
    $sql = 'SELECT o.*, u.first_name, u.last_name, u.email, u.phone AS buyer_phone 
            FROM orders o 
            JOIN users u ON u.id = o.user_id 
            WHERE (' . ($orderId ? 'o.id = :id' : '1=0') . ' OR o.order_number = :order_num)
              AND o.user_id = :uid 
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $params = [
        ':order_num' => $orderNumber !== '' ? $orderNumber : '__NONE__',
        ':uid'       => $userId,
    ];
    if ($orderId) {
        $params[':id'] = $orderId;
    }
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ShopSphere Order Details Query Error] ' . $e->getMessage());
}

if (!$order) {
    flash('error', 'Order not found or you do not have permission to view this order.');
    redirect(url('orders.php'));
}

$orderId = (int) $order['id'];

// Handle POST: Cancel Order Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        flash('error', 'Session expired. Please try again.');
        redirect(url('order-details.php?id=' . $orderId));
    }

    if (!in_array(strtolower((string) $order['order_status']), ['pending', 'confirmed'], true)) {
        flash('error', 'This order cannot be cancelled because it is already ' . htmlspecialchars((string) $order['order_status']) . '.');
        redirect(url('order-details.php?id=' . $orderId));
    }

    try {
        $orderService = new OrderService($pdo);
        $cancelled = $orderService->cancelOrder($orderId, $userId);

        if ($cancelled) {
            flash('success', "Order #{$order['order_number']} has been cancelled successfully.");
        } else {
            flash('error', 'Unable to cancel this order. Please contact customer support.');
        }
    } catch (Throwable $e) {
        error_log('[ShopSphere Order Details Cancel Error] ' . $e->getMessage());
        flash('error', 'Failed to cancel order: ' . $e->getMessage());
    }

    redirect(url('order-details.php?id=' . $orderId));
}

// Decode address snapshots
$shippingAddress = json_decode((string) $order['shipping_address'], true) ?: [];
$billingAddress  = json_decode((string) $order['billing_address'], true) ?: $shippingAddress;

// Fetch line items
$orderItems = [];
try {
    $itemsSql = "SELECT 
        oi.*,
        sp.store_name,
        sp.store_slug,
        (SELECT pi.image_path 
         FROM product_images pi 
         WHERE pi.product_id = oi.product_id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC 
         LIMIT 1) AS primary_image
    FROM order_items oi
    LEFT JOIN seller_profiles sp ON sp.user_id = oi.seller_id
    WHERE oi.order_id = :order_id
    ORDER BY oi.id ASC";

    $stmtItems = $pdo->prepare($itemsSql);
    $stmtItems->execute([':order_id' => $orderId]);
    $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Order Details Items Error] ' . $e->getMessage());
}

// Fetch order status timeline history
$statusHistory = [];
try {
    $histSql = 'SELECT * FROM order_status_history 
                WHERE order_id = :order_id 
                ORDER BY created_at ASC, id ASC';
    $stmtHist = $pdo->prepare($histSql);
    $stmtHist->execute([':order_id' => $orderId]);
    $statusHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Order History Error] ' . $e->getMessage());
}

// Badge color helpers
$statusClass = match (strtolower((string) $order['order_status'])) {
    'delivered'  => 'ss-badge-success',
    'shipped'    => 'ss-badge-info',
    'processing' => 'ss-badge-warning',
    'confirmed'  => 'ss-badge-info',
    'cancelled', 'returned' => 'ss-badge-danger',
    default      => 'ss-badge-warning',
};

$paymentStatusBadge = match (strtolower((string) $order['payment_status'])) {
    'paid'     => 'bg-success',
    'failed'   => 'bg-danger',
    'refunded' => 'bg-info',
    default    => 'bg-warning text-dark',
};

$canCancel = in_array(strtolower((string) $order['order_status']), ['pending', 'confirmed'], true);

$page_title = 'Order #' . $order['order_number'];
$active_page = 'orders';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4 d-print-none" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li><a href="<?= url('orders.php') ?>">My Orders</a></li>
            <li>Order Details</li>
        </ol>
    </nav>

    <!-- Top Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 d-print-none">
        <div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= url('orders.php') ?>" class="btn btn-outline-secondary btn-sm" title="Back to Orders">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h4 class="fw-bold mb-0">Order #<?= e($order['order_number']) ?></h4>
                <span class="ss-badge <?= $statusClass ?> ms-2">
                    <?= e(ucfirst((string) $order['order_status'])) ?>
                </span>
            </div>
            <p class="text-muted small mb-0 mt-1">
                Placed on <?= e(format_date($order['created_at'], 'l, F j, Y \a\t h:i A')) ?>
            </p>
        </div>

        <div class="d-flex gap-2 align-items-center">
            <!-- Track Order Button -->
            <a href="<?= url('track-order.php?order_number=' . urlencode($order['order_number'])) ?>" class="btn btn-outline-primary btn-sm px-3">
                <i class="bi bi-truck me-1"></i> Track Order
            </a>

            <!-- Print Invoice Button -->
            <button type="button" class="btn btn-outline-secondary btn-sm px-3" onclick="window.print();">
                <i class="bi bi-printer me-1"></i> Print / Invoice
            </button>

            <!-- Cancel Order Button (if status is pending) -->
            <?php if ($canCancel): ?>
                <form action="<?= url('order-details.php?id=' . $orderId) ?>" method="POST" class="m-0" onsubmit="return confirm('Are you sure you want to cancel this order? This action cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_order">
                    <button type="submit" class="btn btn-outline-danger btn-sm px-3">
                        <i class="bi bi-x-circle me-1"></i> Cancel Order
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Print Only Header -->
    <div class="d-none d-print-block mb-4 border-bottom pb-3">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="fw-bold mb-1">ShopSphere Marketplace</h2>
                <p class="text-muted small mb-0">Official Customer Invoice</p>
            </div>
            <div class="text-end">
                <h5 class="mb-0 fw-bold">Order #<?= e($order['order_number']) ?></h5>
                <small class="text-muted">Date: <?= e(format_date($order['created_at'], 'M d, Y')) ?></small>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Main Order Content -->
        <div class="col-lg-8">
            <!-- Itemized Products Table -->
            <div class="ss-card shadow-sm mb-4">
                <div class="ss-card-header bg-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-bag-check-fill text-ss-accent me-2"></i> Ordered Items (<?= count($orderItems) ?>)
                    </h5>
                    <span class="badge bg-light text-dark border">
                        <?= count($orderItems) ?> Line Item(s)
                    </span>
                </div>
                <div class="ss-card-body p-0">
                    <div class="table-responsive">
                        <table class="table ss-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Product Details</th>
                                    <th>Sold By</th>
                                    <th class="text-center">Price</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orderItems as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="ss-cart-item-img flex-shrink-0" style="width: 55px; height: 55px;">
                                                    <?php if (!empty($item['primary_image'])): ?>
                                                        <img src="<?= e(asset($item['primary_image'])) ?>" alt="<?= e($item['product_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="ss-img-placeholder" style="font-size: 1.25rem;">
                                                            <i class="bi bi-box"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark text-truncate" style="max-width: 250px;" title="<?= e($item['product_name']) ?>">
                                                        <?= e($item['product_name']) ?>
                                                    </div>
                                                    <?php if (!empty($item['variant_info'])): ?>
                                                        <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">
                                                            <?= e($item['variant_info']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['sku'])): ?>
                                                        <small class="text-muted d-block" style="font-size: 0.72rem;">
                                                            SKU: <?= e($item['sku']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-primary border small">
                                                <i class="bi bi-shop me-1"></i><?= e($item['store_name'] ?? 'Marketplace Seller') ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?= format_price($item['unit_price']) ?>
                                        </td>
                                        <td class="text-center fw-bold">
                                            <?= (int) $item['quantity'] ?>
                                        </td>
                                        <td class="text-end fw-bold text-dark">
                                            <?= format_price($item['total_price']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Order Timeline / Status History -->
            <div class="ss-card shadow-sm mb-4">
                <div class="ss-card-header bg-white">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-clock-history text-ss-accent me-2"></i> Order Status History
                    </h5>
                </div>
                <div class="ss-card-body p-4">
                    <?php if (empty($statusHistory)): ?>
                        <p class="text-muted small mb-0">No timeline events recorded yet.</p>
                    <?php else: ?>
                        <div class="position-relative ps-4" style="border-left: 2px solid var(--ss-border);">
                            <?php foreach ($statusHistory as $index => $history): ?>
                                <div class="position-relative mb-4 last-mb-0">
                                    <!-- Timeline Node Circle -->
                                    <div class="position-absolute rounded-circle bg-ss-accent text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 20px; height: 20px; left: -31px; top: 0; font-size: 0.65rem;">
                                        <i class="bi bi-check"></i>
                                    </div>
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                                            <h6 class="fw-bold mb-0 text-capitalize text-dark">
                                                <?= e($history['new_status']) ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?= e(format_date($history['created_at'], 'M d, Y h:i A')) ?>
                                            </small>
                                        </div>
                                        <?php if (!empty($history['comment'])): ?>
                                            <p class="text-muted small mb-0 mt-1">
                                                <?= e($history['comment']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Financial Summary & Address Cards -->
        <div class="col-lg-4">
            <!-- Financial Summary Card -->
            <div class="ss-card shadow-sm mb-4">
                <div class="ss-card-header bg-white">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-receipt text-ss-accent me-2"></i> Payment Summary
                    </h5>
                </div>
                <div class="ss-card-body p-4">
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Subtotal:</span>
                        <strong class="text-dark"><?= format_price($order['subtotal']) ?></strong>
                    </div>

                    <?php if ((float) ($order['discount_amount'] ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between py-2 border-bottom text-success">
                            <span>
                                Coupon Discount
                                <?php if (!empty($order['coupon_code'])): ?>
                                    (<code><?= e($order['coupon_code']) ?></code>)
                                <?php endif; ?>:
                            </span>
                            <strong>-<?= format_price($order['discount_amount']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Shipping Fee:</span>
                        <strong class="text-dark">
                            <?= (float) $order['shipping_amount'] > 0 ? format_price($order['shipping_amount']) : '<span class="text-success">Free</span>' ?>
                        </strong>
                    </div>

                    <?php if ((float) ($order['tax_amount'] ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Sales Tax:</span>
                            <strong class="text-dark"><?= format_price($order['tax_amount']) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between py-3 fs-5 fw-bold text-ss-primary border-bottom border-2">
                        <span>Total Paid:</span>
                        <span class="text-ss-accent"><?= format_price($order['total_amount']) ?></span>
                    </div>

                    <!-- Payment Information -->
                    <div class="mt-3 pt-2">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small">Payment Method:</span>
                            <span class="badge bg-light text-dark border text-uppercase">
                                <?= e($order['payment_method'] ?? 'COD') ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">Payment Status:</span>
                            <span class="badge <?= $paymentStatusBadge ?> text-uppercase">
                                <?= e(ucfirst((string) ($order['payment_status'] ?? 'pending'))) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Shipping Address Card -->
            <div class="ss-card shadow-sm mb-4">
                <div class="ss-card-header bg-white">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-geo-alt-fill text-ss-accent me-2"></i> Shipping Address
                    </h5>
                </div>
                <div class="ss-card-body p-4">
                    <?php if (!empty($shippingAddress)): ?>
                        <h6 class="fw-bold mb-1"><?= e($shippingAddress['full_name'] ?? 'Recipient') ?></h6>
                        <p class="text-muted small mb-0">
                            <?php if (!empty($shippingAddress['phone'])): ?>
                                <i class="bi bi-telephone text-muted me-1"></i> <?= e($shippingAddress['phone']) ?><br>
                            <?php endif; ?>
                            <i class="bi bi-building text-muted me-1"></i> <?= e($shippingAddress['address_line1'] ?? '') ?>
                            <?php if (!empty($shippingAddress['address_line2'])): ?>
                                , <?= e($shippingAddress['address_line2']) ?>
                            <?php endif; ?><br>
                            <?= e($shippingAddress['city'] ?? '') ?><?= !empty($shippingAddress['state']) ? ', ' . e($shippingAddress['state']) : '' ?><?= !empty($shippingAddress['postal_code']) ? ' ' . e($shippingAddress['postal_code']) : '' ?><br>
                            <?= e($shippingAddress['country'] ?? 'Pakistan') ?>
                        </p>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Address details unavailable.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Delivery Instructions / Notes (if any) -->
            <?php if (!empty($order['notes'])): ?>
                <div class="ss-card shadow-sm mb-4">
                    <div class="ss-card-header bg-white">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-chat-left-text text-ss-accent me-2"></i> Customer Notes
                        </h5>
                    </div>
                    <div class="ss-card-body p-4">
                        <p class="text-muted small mb-0"><?= nl2br(e($order['notes'])) ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
