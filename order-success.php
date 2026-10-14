<?php
/**
 * ShopSphere - Order Confirmation & Success View
 *
 * Confirms successful order placement, verifies buyer ownership,
 * displays immutable transaction summaries, and links to order lifecycle tracking.
 *
 * @package ShopSphere\Customer
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Enforce authentication
require_login();

$userId = (int) get_current_user_id();
$orderNumber = trim((string) ($_GET['order_number'] ?? ''));

if ($orderNumber === '') {
    flash('error', 'Please specify a valid order number.');
    redirect(url('orders.php'));
}

$pdo = get_db_connection();

// Verify that the requested order exists and strictly belongs to the authenticated user
$orderStmt = $pdo->prepare('SELECT o.*, u.first_name, u.last_name, u.email 
    FROM orders o 
    JOIN users u ON u.id = o.user_id 
    WHERE o.order_number = :order_number AND o.user_id = :user_id 
    LIMIT 1');
$orderStmt->execute([
    ':order_number' => $orderNumber,
    ':user_id'      => $userId,
]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    flash('error', 'Order not found or access denied.');
    redirect(url('orders.php'));
}

// Decode shipping address snapshot
$shippingAddress = [];
if (!empty($order['shipping_address'])) {
    $decoded = json_decode((string) $order['shipping_address'], true);
    if (is_array($decoded)) {
        $shippingAddress = $decoded;
    }
}

// Fetch line items count
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id');
$countStmt->execute([':order_id' => $order['id']]);
$itemsCount = (int) $countStmt->fetchColumn();

// Fetch seller order count
$sellerOrdersStmt = $pdo->prepare('SELECT COUNT(*) FROM seller_orders WHERE order_id = :order_id');
$sellerOrdersStmt->execute([':order_id' => $order['id']]);
$vendorsCount = (int) $sellerOrdersStmt->fetchColumn();

$page_title = 'Order Confirmed — #' . $order['order_number'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-5">
    <nav class="ss-breadcrumb mb-4">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li><a href="<?php echo e(url('/orders.php')); ?>">My Orders</a></li>
            <li>Order Confirmation</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="ss-card ss-order-success shadow-sm">
                <!-- Success Green Checkmark Icon -->
                <div class="success-icon mb-3" style="width: 80px; height: 80px; background: rgba(16, 185, 129, 0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                    <i class="bi bi-check-lg" style="font-size: 3rem; color: #10b981;"></i>
                </div>

                <h1 class="h2 fw-bold text-ss-primary mb-2">Thank You for Your Order!</h1>
                <p class="text-ss-muted mb-4 lead" style="font-size: 1.05rem;">
                    Your order has been placed and is currently being processed by our verified marketplace sellers.
                </p>

                <!-- Order Information Overview Box -->
                <div class="bg-light p-4 rounded-3 text-start mb-4 border">
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-3">
                            <span class="text-ss-muted d-block small text-uppercase fw-semibold">Order Number</span>
                            <strong class="text-ss-primary fs-6"><?php echo e($order['order_number']); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <span class="text-ss-muted d-block small text-uppercase fw-semibold">Date Placed</span>
                            <span class="fw-semibold text-ss-text"><?php echo format_date($order['created_at'], 'M d, Y'); ?></span>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <span class="text-ss-muted d-block small text-uppercase fw-semibold">Payment Method</span>
                            <span class="fw-semibold text-ss-text text-capitalize">
                                <?php if ($order['payment_method'] === 'card'): ?>
                                    <i class="bi bi-credit-card-2-front text-ss-accent me-1"></i> Simulated Card
                                <?php else: ?>
                                    <i class="bi bi-cash-stack text-success me-1"></i> Cash on Delivery
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <span class="text-ss-muted d-block small text-uppercase fw-semibold">Total Amount</span>
                            <strong class="text-ss-primary fs-5"><?php echo format_price($order['total_amount']); ?></strong>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="row g-3 align-items-center">
                        <div class="col-md-6">
                            <span class="text-ss-muted d-block small text-uppercase fw-semibold mb-1">Status</span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-capitalize px-3 py-1">
                                    <i class="bi bi-clock me-1"></i> Order <?php echo e($order['order_status']); ?>
                                </span>
                                <?php if ($order['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1">
                                        <i class="bi bi-check-circle me-1"></i> Paid
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1">
                                        <i class="bi bi-hourglass-split me-1"></i> Payment Pending
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <span class="text-ss-muted d-block small text-uppercase fw-semibold mb-1">Marketplace Fulfillment</span>
                            <span class="small text-ss-text">
                                <i class="bi bi-shop me-1 text-ss-accent"></i> 
                                <?php echo $vendorsCount; ?> <?php echo $vendorsCount === 1 ? 'Seller' : 'Sellers'; ?> fulfilling <?php echo $itemsCount; ?> <?php echo $itemsCount === 1 ? 'item' : 'items'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Shipping Address Card -->
                <?php if (!empty($shippingAddress)): ?>
                    <div class="bg-white p-4 rounded-3 text-start mb-4 border">
                        <h6 class="fw-bold mb-2 text-ss-primary">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i> Shipping Destination
                        </h6>
                        <div class="text-ss-muted small">
                            <strong class="text-ss-text"><?php echo e($shippingAddress['full_name'] ?? ''); ?></strong> &bull; <?php echo e($shippingAddress['phone'] ?? ''); ?><br>
                            <?php echo e($shippingAddress['address_line1'] ?? ''); ?>
                            <?php if (!empty($shippingAddress['address_line2'])): ?>
                                , <?php echo e($shippingAddress['address_line2']); ?>
                            <?php endif; ?><br>
                            <?php echo e($shippingAddress['city'] ?? ''); ?>, <?php echo e($shippingAddress['state'] ?? ''); ?> <?php echo e($shippingAddress['postal_code'] ?? ''); ?>, <?php echo e($shippingAddress['country'] ?? 'Pakistan'); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Next Steps Info Alert -->
                <div class="alert alert-info d-flex align-items-center text-start mb-4" role="alert">
                    <i class="bi bi-info-circle-fill fs-4 me-3 flex-shrink-0"></i>
                    <div class="small">
                        We have dispatched in-app notifications to the sellers. You can view the real-time shipping tracking, item status, and vendor breakdown on your order details page anytime.
                    </div>
                </div>

                <!-- Action Navigation Links -->
                <div class="d-flex flex-wrap justify-content-center gap-3">
                    <a href="<?php echo e(url('/order-details.php?order_number=' . urlencode($order['order_number']))); ?>" class="btn btn-ss-primary px-4 py-2 fw-semibold">
                        <i class="bi bi-receipt me-1"></i> View Order Details
                    </a>
                    <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-outline px-4 py-2 fw-semibold">
                        <i class="bi bi-bag me-1"></i> Continue Shopping
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
