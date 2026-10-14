<?php
/**
 * ShopSphere - Public & Customer Order Tracking
 *
 * Enables customers and guest shoppers to track delivery milestones in real-time
 * with visual stepper progression, carrier info, and tracking numbers.
 *
 * @package ShopSphere\Orders
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';

$pdo = get_db_connection();
$currentUserId = get_current_user_id();

$orderNumberInput = sanitize_string($_GET['order_number'] ?? $_POST['order_number'] ?? '');
$contactInput     = sanitize_string($_GET['contact'] ?? $_POST['contact'] ?? '');

$searched = ($orderNumberInput !== '');
$order = null;
$error = '';
$trackingInfoList = [];

if ($searched) {
    try {
        // Query master order
        if ($currentUserId !== null) {
            // Logged in: check if order belongs to user, or allow searching with matching email/phone
            $stmt = $pdo->prepare('SELECT o.*, u.email AS buyer_email, u.phone AS buyer_phone 
                FROM orders o 
                JOIN users u ON u.id = o.user_id 
                WHERE o.order_number = :num 
                  AND (o.user_id = :uid OR u.email = :contact OR u.phone = :contact)
                LIMIT 1');
            $stmt->execute([
                ':num'     => $orderNumberInput,
                ':uid'     => $currentUserId,
                ':contact' => $contactInput,
            ]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Guest mode: MUST provide matching email or phone
            if ($contactInput === '') {
                $error = 'Guest tracking requires both Order Number and Billing Email or Phone Number.';
            } else {
                $stmt = $pdo->prepare('SELECT o.*, u.email AS buyer_email, u.phone AS buyer_phone 
                    FROM orders o 
                    JOIN users u ON u.id = o.user_id 
                    WHERE o.order_number = :num 
                      AND (LOWER(u.email) = LOWER(:email) OR u.phone = :phone)
                    LIMIT 1');
                $stmt->execute([
                    ':num'   => $orderNumberInput,
                    ':email' => $contactInput,
                    ':phone' => $contactInput,
                ]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$order) {
                    // Also check snapshot addresses email/phone
                    $stmtAlt = $pdo->prepare('SELECT o.* FROM orders o WHERE o.order_number = :num LIMIT 1');
                    $stmtAlt->execute([':num' => $orderNumberInput]);
                    $altOrder = $stmtAlt->fetch(PDO::FETCH_ASSOC);

                    if ($altOrder) {
                        $shipAddr = json_decode((string) $altOrder['shipping_address'], true) ?: [];
                        if (!empty($shipAddr['phone']) && $shipAddr['phone'] === $contactInput) {
                            $order = $altOrder;
                        }
                    }
                }
            }
        }

        if ($searched && !$order && empty($error)) {
            $error = 'No order found matching the provided order number and contact information.';
        }

        // If order found, fetch carrier and tracking numbers from seller_orders or shipments
        if ($order) {
            $orderId = (int) $order['id'];

            // 1. Seller orders tracking
            try {
                $soStmt = $pdo->prepare('SELECT so.id, so.status, so.tracking_number, so.shipping_carrier, so.shipped_at, sp.store_name 
                    FROM seller_orders so 
                    LEFT JOIN seller_profiles sp ON sp.user_id = so.seller_id 
                    WHERE so.order_id = :order_id');
                $soStmt->execute([':order_id' => $orderId]);
                $trackingInfoList = $soStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $trackingInfoList = [];
            }
        }
    } catch (Throwable $e) {
        error_log('[ShopSphere Track Order Error] ' . $e->getMessage());
        $error = 'An error occurred while tracking your order. Please try again later.';
    }
}

// Stepper milestone mapping
$stepperStages = [
    'pending'    => ['label' => 'Order Placed', 'desc' => 'Your order is pending confirmation', 'step' => 1],
    'confirmed'  => ['label' => 'Confirmed',    'desc' => 'Order has been verified & approved', 'step' => 2],
    'processing' => ['label' => 'Processing',   'desc' => 'Items are being assembled & packaged', 'step' => 3],
    'shipped'    => ['label' => 'Shipped',      'desc' => 'Package has been handed over to courier', 'step' => 4],
    'delivered'  => ['label' => 'Delivered',    'desc' => 'Order safely reached destination', 'step' => 5],
];

$currentOrderStatus = strtolower((string) ($order['order_status'] ?? ''));
$isCancelled = in_array($currentOrderStatus, ['cancelled', 'returned'], true);

$currentStepNumber = match ($currentOrderStatus) {
    'confirmed'  => 2,
    'processing' => 3,
    'shipped'    => 4,
    'delivered'  => 5,
    'cancelled', 'returned' => 0,
    default      => 1, // 'pending'
};

$page_title = 'Track Order';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li>Track Order</li>
        </ol>
    </nav>

    <!-- Page Header Hero Banner -->
    <div class="text-center mb-5">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 mb-2">Live Courier Tracking</span>
        <h2 class="fw-bold mb-2">Track Your Shipment</h2>
        <p class="text-muted mx-auto" style="max-width: 600px;">
            Enter your order reference number below to check real-time order status, courier dispatches, and delivery timelines.
        </p>
    </div>

    <!-- Tracking Lookup Form Card -->
    <div class="row justify-content-center mb-5">
        <div class="col-lg-8">
            <div class="ss-card shadow-sm p-4">
                <form action="<?= url('track-order.php') ?>" method="GET" class="row g-3">
                    <div class="<?= $currentUserId ? 'col-md-9' : 'col-md-6' ?>">
                        <label for="order_number" class="form-label fw-semibold small">Order Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted"><i class="bi bi-hash"></i></span>
                            <input type="text" 
                                   class="form-control ss-form-control" 
                                   id="order_number" 
                                   name="order_number" 
                                   placeholder="e.g. ORD-20261013-XXXX or SS-..." 
                                   value="<?= e($orderNumberInput) ?>" 
                                   required>
                        </div>
                    </div>

                    <?php if (!$currentUserId): ?>
                        <div class="col-md-6">
                            <label for="contact" class="form-label fw-semibold small">Billing Email or Phone <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white text-muted"><i class="bi bi-person"></i></span>
                                <input type="text" 
                                       class="form-control ss-form-control" 
                                       id="contact" 
                                       name="contact" 
                                       placeholder="Email or phone used at checkout" 
                                       value="<?= e($contactInput) ?>" 
                                       required>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="<?= $currentUserId ? 'col-md-3' : 'col-12' ?> d-flex align-items-end">
                        <button type="submit" class="btn btn-ss-primary w-100 py-2">
                            <i class="bi bi-search me-1"></i> Track Order
                        </button>
                    </div>
                </form>

                <?php if ($currentUserId): ?>
                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            Logged in as customer. You can also view all your purchases directly in
                            <a href="<?= url('orders.php') ?>" class="text-ss-accent text-decoration-none fw-semibold">My Orders</a>.
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Error Alert Display -->
    <?php if (!empty($error)): ?>
        <div class="row justify-content-center mb-5">
            <div class="col-lg-8">
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?= e($error) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Order Tracking Results Card -->
    <?php if ($order): ?>
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="ss-card shadow-sm mb-4">
                    <!-- Results Card Header -->
                    <div class="ss-card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2 p-4">
                        <div>
                            <span class="badge bg-light text-dark border mb-1">Live Tracking</span>
                            <h4 class="fw-bold mb-0">Order #<?= e($order['order_number']) ?></h4>
                            <small class="text-muted">
                                Placed on <?= e(format_date($order['created_at'], 'M d, Y h:i A')) ?>
                            </small>
                        </div>
                        <div class="text-md-end">
                            <div class="fs-5 fw-bold text-dark">
                                <?= format_price($order['total_amount']) ?>
                            </div>
                            <span class="badge bg-primary text-uppercase">
                                <?= e($order['order_status']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Progress Stepper Body -->
                    <div class="ss-card-body p-4">
                        <?php if ($isCancelled): ?>
                            <!-- Cancelled / Returned Warning Banner -->
                            <div class="alert alert-danger d-flex align-items-center gap-3 mb-4" role="alert">
                                <i class="bi bi-x-octagon-fill fs-2"></i>
                                <div>
                                    <h6 class="alert-heading fw-bold mb-1">
                                        Order is <?= ucfirst($currentOrderStatus) ?>
                                    </h6>
                                    <p class="mb-0 small">
                                        This order was cancelled and is no longer moving through the fulfillment pipeline. If you have questions regarding refunds, please contact customer support.
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Visual Progression Stepper -->
                            <div class="py-4 px-2">
                                <div class="row g-2 text-center position-relative">
                                    <?php foreach ($stepperStages as $stageKey => $stage): ?>
                                        <?php
                                        $stageStep = $stage['step'];
                                        $isCompleted = ($currentStepNumber >= $stageStep);
                                        $isCurrent   = ($currentStepNumber === $stageStep);

                                        $iconClass = match ($stageKey) {
                                            'pending'    => 'bi-receipt',
                                            'confirmed'  => 'bi-check2-circle',
                                            'processing' => 'bi-box-seam',
                                            'shipped'    => 'bi-truck',
                                            'delivered'  => 'bi-house-check',
                                            default      => 'bi-dot',
                                        };
                                        ?>
                                        <div class="col">
                                            <div class="d-flex flex-column align-items-center">
                                                <!-- Step Circle -->
                                                <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm mb-2 transition-all"
                                                     style="width: 52px; height: 52px; 
                                                            background-color: <?= $isCompleted ? 'var(--ss-accent)' : '#e5e7eb' ?>; 
                                                            color: <?= $isCompleted ? '#fff' : '#6b7280' ?>; 
                                                            border: 3px solid <?= $isCurrent ? '#3b82f6' : 'transparent' ?>;
                                                            font-size: 1.4rem;">
                                                    <i class="bi <?= $iconClass ?>"></i>
                                                </div>

                                                <!-- Step Label -->
                                                <h6 class="fw-bold mb-1 <?= $isCompleted ? 'text-dark' : 'text-muted' ?>" style="font-size: 0.875rem;">
                                                    <?= e($stage['label']) ?>
                                                </h6>
                                                <small class="text-muted d-none d-md-block" style="font-size: 0.72rem; max-width: 120px;">
                                                    <?= e($stage['desc']) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <hr class="my-4 text-muted opacity-25">

                        <!-- Shipping & Courier Breakdown Section -->
                        <div class="row g-4">
                            <!-- Shipping Address Card -->
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-3"><i class="bi bi-geo-alt-fill text-ss-accent me-2"></i>Destination Details</h6>
                                <?php
                                $shippingAddress = json_decode((string) $order['shipping_address'], true) ?: [];
                                ?>
                                <?php if (!empty($shippingAddress)): ?>
                                    <div class="p-3 bg-light rounded border">
                                        <strong class="d-block text-dark mb-1"><?= e($shippingAddress['full_name'] ?? 'Recipient') ?></strong>
                                        <div class="text-muted small">
                                            <?= e($shippingAddress['address_line1'] ?? '') ?><br>
                                            <?php if (!empty($shippingAddress['address_line2'])): ?>
                                                <?= e($shippingAddress['address_line2']) ?><br>
                                            <?php endif; ?>
                                            <?= e($shippingAddress['city'] ?? '') ?>, <?= e($shippingAddress['country'] ?? 'Pakistan') ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted small">Destination address on file.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Carrier & Tracking Numbers -->
                            <div class="col-md-6">
                                <h6 class="fw-bold mb-3"><i class="bi bi-truck text-ss-accent me-2"></i>Shipment & Courier Data</h6>
                                <?php
                                $hasAssignedTracking = false;
                                ?>
                                <?php if (!empty($trackingInfoList)): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($trackingInfoList as $track): ?>
                                            <?php if (!empty($track['tracking_number'])): ?>
                                                <?php $hasAssignedTracking = true; ?>
                                                <div class="p-3 bg-light rounded border">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <span class="badge bg-primary"><?= e($track['shipping_carrier'] ?: 'Courier') ?></span>
                                                        <small class="text-muted"><?= e(ucfirst((string) $track['status'])) ?></small>
                                                    </div>
                                                    <div class="fw-bold text-dark font-monospace fs-6">
                                                        Tracking #: <?= e($track['tracking_number']) ?>
                                                    </div>
                                                    <?php if (!empty($track['store_name'])): ?>
                                                        <small class="text-muted">Shipped by: <?= e($track['store_name']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$hasAssignedTracking): ?>
                                    <div class="p-3 bg-light rounded border text-muted small">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Tracking number will be generated as soon as your shipment is dispatched by the courier partner (e.g. TCS, Leopards, Call Courier).
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Customer Actions -->
                        <?php if ($currentUserId): ?>
                            <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                                <a href="<?= url('order-details.php?id=' . $order['id']) ?>" class="btn btn-outline-primary btn-sm px-3">
                                    <i class="bi bi-file-earmark-text me-1"></i> View Full Invoice
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
