<?php
/**
 * ShopSphere - Full Order Breakdown & Inspection
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/helpers.php';

require_role('admin');
$db = get_db();

$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    flash('error', 'Invalid order identifier.');
    redirect(url('/admin/orders.php'));
}

// Fetch Master Order
$stmt = $db->prepare("
    SELECT o.*, u.first_name, u.last_name, u.email, u.phone AS customer_phone
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = :id
");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    flash('error', 'Order not found.');
    redirect(url('/admin/orders.php'));
}

// Handle Status & Package Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_order_status') {
        $newStatus = $_POST['order_status'] ?? '';
        $comment = trim($_POST['comment'] ?? 'Administrative status change');
        $allowed = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

        if (!in_array($newStatus, $allowed, true)) {
            flash('error', 'Invalid order status selected.');
            redirect(url('/admin/order-details.php?id=' . $orderId));
        }

        $oldStatus = $order['order_status'];

        $db->beginTransaction();
        try {
            // Update order
            $stmt = $db->prepare("UPDATE orders SET order_status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $orderId]);

            // If delivered, cascade to seller orders that are not cancelled
            if ($newStatus === 'delivered') {
                $db->prepare("UPDATE seller_orders SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE order_id = :id AND status != 'cancelled'")
                   ->execute(['id' => $orderId]);
            } elseif ($newStatus === 'cancelled') {
                $db->prepare("UPDATE seller_orders SET status = 'cancelled', updated_at = NOW() WHERE order_id = :id")
                   ->execute(['id' => $orderId]);
            }

            // Insert into history
            $stmtHist = $db->prepare("
                INSERT INTO order_status_history (order_id, old_status, new_status, comment, created_by, created_at)
                VALUES (:order_id, :old_status, :new_status, :comment, :created_by, NOW())
            ");
            $stmtHist->execute([
                'order_id'   => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'comment'    => $comment,
                'created_by' => get_current_user_id()
            ]);

            // Notify customer
            $notif = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, created_at)
                VALUES (:user_id, 'order', :title, :msg, :link, NOW())
            ");
            $notif->execute([
                'user_id' => $order['user_id'],
                'title'   => "Order #{$order['order_number']} Status: " . ucfirst($newStatus),
                'msg'     => "Your order has been updated to {$newStatus}. {$comment}",
                'link'    => '/order-details.php?id=' . $orderId
            ]);

            log_audit($db, 'update_order_status', 'orders', $orderId, ['order_status' => $oldStatus], ['order_status' => $newStatus, 'comment' => $comment]);

            $db->commit();
            flash('success', "Order status successfully updated to " . ucfirst($newStatus) . ".");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Failed to update order status: ' . $e->getMessage());
        }

        redirect(url('/admin/order-details.php?id=' . $orderId));

    } elseif ($action === 'update_payment_status') {
        $newPaymentStatus = $_POST['payment_status'] ?? '';
        $allowed = ['pending', 'paid', 'failed', 'refunded', 'partially_refunded'];

        if (!in_array($newPaymentStatus, $allowed, true)) {
            flash('error', 'Invalid payment status selected.');
            redirect(url('/admin/order-details.php?id=' . $orderId));
        }

        $oldPaymentStatus = $order['payment_status'];

        $stmt = $db->prepare("UPDATE orders SET payment_status = :status, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['status' => $newPaymentStatus, 'id' => $orderId]);

        log_audit($db, 'update_payment_status', 'orders', $orderId, ['payment_status' => $oldPaymentStatus], ['payment_status' => $newPaymentStatus]);

        flash('success', "Payment status set to " . ucfirst($newPaymentStatus) . ".");
        redirect(url('/admin/order-details.php?id=' . $orderId));

    } elseif ($action === 'update_seller_package') {
        $sellerOrderId = (int)($_POST['seller_order_id'] ?? 0);
        $pkgStatus = $_POST['package_status'] ?? '';
        $carrier = trim($_POST['shipping_carrier'] ?? '');
        $tracking = trim($_POST['tracking_number'] ?? '');

        if ($sellerOrderId <= 0) {
            flash('error', 'Invalid package identifier.');
            redirect(url('/admin/order-details.php?id=' . $orderId));
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE seller_orders 
                SET status = :status, shipping_carrier = :carrier, tracking_number = :tracking, updated_at = NOW()
                WHERE id = :id AND order_id = :order_id
            ");
            $stmt->execute([
                'status'   => $pkgStatus,
                'carrier'  => $carrier,
                'tracking' => $tracking,
                'id'       => $sellerOrderId,
                'order_id' => $orderId
            ]);

            // Sync order items status
            $db->prepare("UPDATE order_items SET status = :status, updated_at = NOW() WHERE seller_order_id = :id")
               ->execute(['status' => $pkgStatus, 'id' => $sellerOrderId]);

            log_audit($db, 'update_seller_package', 'seller_orders', $sellerOrderId, null, [
                'status' => $pkgStatus, 'tracking' => $tracking, 'carrier' => $carrier
            ]);

            $db->commit();
            flash('success', "Seller package #{$sellerOrderId} updated successfully.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Failed to update package: ' . $e->getMessage());
        }

        redirect(url('/admin/order-details.php?id=' . $orderId));
    }
}

// Decode JSON Address Snapshots
$shippingAddress = is_string($order['shipping_address']) ? json_decode($order['shipping_address'], true) : $order['shipping_address'];
$billingAddress = is_string($order['billing_address']) ? json_decode($order['billing_address'], true) : $order['billing_address'];

// Fetch Seller Order Packages
$stmt = $db->prepare("
    SELECT so.*, sp.store_name, sp.store_slug, u.email AS seller_email, u.phone AS seller_phone
    FROM seller_orders so
    JOIN seller_profiles sp ON so.seller_id = sp.user_id
    JOIN users u ON so.seller_id = u.id
    WHERE so.order_id = :id
    ORDER BY so.id ASC
");
$stmt->execute(['id' => $orderId]);
$sellerPackages = $stmt->fetchAll();

// Fetch All Order Items
$stmt = $db->prepare("
    SELECT oi.*, 
        (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = oi.product_id AND pi.is_primary = 1 LIMIT 1) AS product_image
    FROM order_items oi
    WHERE oi.order_id = :id
    ORDER BY oi.id ASC
");
$stmt->execute(['id' => $orderId]);
$allItems = $stmt->fetchAll();

// Group Items by Seller Order
$itemsByPackage = [];
foreach ($allItems as $item) {
    $itemsByPackage[$item['seller_order_id']][] = $item;
}

// Fetch Payments Transactions
$stmt = $db->prepare("SELECT * FROM payments WHERE order_id = :id ORDER BY id DESC");
$stmt->execute(['id' => $orderId]);
$payments = $stmt->fetchAll();

// Fetch Status History
$stmt = $db->prepare("
    SELECT osh.*, u.first_name, u.last_name, u.role
    FROM order_status_history osh
    LEFT JOIN users u ON osh.created_by = u.id
    WHERE osh.order_id = :id
    ORDER BY osh.id DESC
");
$stmt->execute(['id' => $orderId]);
$statusHistory = $stmt->fetchAll();

$page_title = 'Order Inspection: ' . $order['order_number'];
$current_page = 'orders';
require_once __DIR__ . '/header.php';
?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <a href="<?php echo url('/admin/orders.php'); ?>" class="btn btn-light border btn-sm mb-2">
            <i class="bi bi-arrow-left"></i> Back to Orders Directory
        </a>
        <h2 class="h4 mb-1 d-flex align-items-center gap-2">
            Order #<?php echo e($order['order_number']); ?>
            <span class="ss-badge ss-badge-<?php echo match($order['order_status']) {
                'delivered' => 'success',
                'shipped' => 'info',
                'processing', 'confirmed' => 'warning',
                'cancelled' => 'danger',
                default => 'default'
            }; ?> fs-6">
                <?php echo ucfirst($order['order_status']); ?>
            </span>
            <span class="badge <?php echo match($order['payment_status']) {
                'paid' => 'bg-success',
                'pending' => 'bg-warning text-dark',
                default => 'bg-danger'
            }; ?> fs-6">
                Payment: <?php echo ucfirst($order['payment_status']); ?>
            </span>
        </h2>
        <div class="text-ss-muted small">
            Placed on <?php echo format_date($order['created_at'], 'l, F j, Y \a\t h:i A'); ?> &bull; IP: <?php echo e($order['ip_address'] ?? 'Unknown'); ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-light border btn-sm" onclick="window.print()">
            <i class="bi bi-printer"></i> Print Invoice
        </button>
        <button type="button" class="btn btn-ss-primary btn-sm" data-bs-toggle="modal" data-bs-target="#statusModal">
            <i class="bi bi-pencil-square"></i> Change Order Status
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#paymentModal">
            <i class="bi bi-credit-card"></i> Payment Status
        </button>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Customer & Shipping Info -->
    <div class="col-lg-4">
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5><i class="bi bi-person text-primary me-2"></i> Customer Details</h5>
            </div>
            <div class="ss-card-body">
                <div class="mb-3">
                    <label class="text-ss-muted small text-uppercase fw-semibold">Customer</label>
                    <div class="fw-bold text-dark"><?php echo e($order['first_name'] . ' ' . $order['last_name']); ?></div>
                    <div><a href="mailto:<?php echo e($order['email']); ?>"><?php echo e($order['email']); ?></a></div>
                    <?php if (!empty($order['customer_phone'])): ?>
                        <div class="small text-ss-muted"><i class="bi bi-telephone"></i> <?php echo e($order['customer_phone']); ?></div>
                    <?php endif; ?>
                </div>
                <hr>
                <div class="mb-3">
                    <label class="text-ss-muted small text-uppercase fw-semibold">Shipping Address</label>
                    <?php if (is_array($shippingAddress)): ?>
                        <div class="fw-bold"><?php echo e($shippingAddress['full_name'] ?? ''); ?></div>
                        <div class="text-dark"><?php echo e($shippingAddress['address_line1'] ?? ''); ?></div>
                        <?php if (!empty($shippingAddress['address_line2'])): ?>
                            <div class="text-dark"><?php echo e($shippingAddress['address_line2']); ?></div>
                        <?php endif; ?>
                        <div><?php echo e(($shippingAddress['city'] ?? '') . ', ' . ($shippingAddress['state'] ?? '') . ' ' . ($shippingAddress['postal_code'] ?? '')); ?></div>
                        <div class="small text-ss-muted">Phone: <?php echo e($shippingAddress['phone'] ?? ''); ?></div>
                    <?php else: ?>
                        <p class="text-ss-muted mb-0">No address data recorded.</p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($order['notes'])): ?>
                    <hr>
                    <div>
                        <label class="text-ss-muted small text-uppercase fw-semibold">Order Instructions / Notes</label>
                        <p class="small text-dark mb-0 fst-italic">"<?php echo e($order['notes']); ?>"</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Financial Audit Card -->
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-receipt text-primary me-2"></i> Financial Audit Breakdown</h5>
            </div>
            <div class="ss-card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-ss-muted">Subtotal:</span>
                    <strong class="text-dark"><?php echo format_price($order['subtotal']); ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-ss-muted">Shipping Fee:</span>
                    <strong class="text-dark"><?php echo format_price($order['shipping_amount']); ?></strong>
                </div>
                <?php if ($order['tax_amount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-ss-muted">Tax:</span>
                        <strong class="text-dark"><?php echo format_price($order['tax_amount']); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($order['discount_amount'] > 0): ?>
                    <div class="d-flex justify-content-between mb-2 text-danger">
                        <span>Coupon Discount (<?php echo e($order['coupon_code'] ?? 'Promo'); ?>):</span>
                        <strong>-<?php echo format_price($order['discount_amount']); ?></strong>
                    </div>
                <?php endif; ?>
                <hr>
                <div class="d-flex justify-content-between mb-3 fs-5">
                    <strong>Total Amount:</strong>
                    <strong class="text-ss-accent"><?php echo format_price($order['total_amount']); ?></strong>
                </div>

                <div class="p-3 bg-light rounded border">
                    <div class="d-flex justify-content-between mb-1 small">
                        <span class="text-ss-muted">Payment Method:</span>
                        <span class="fw-bold text-uppercase"><?php echo e($order['payment_method']); ?></span>
                    </div>
                    <div class="d-flex justify-content-between small">
                        <span class="text-ss-muted">Payment Status:</span>
                        <span class="fw-bold <?php echo $order['payment_status'] === 'paid' ? 'text-success' : 'text-danger'; ?>">
                            <?php echo strtoupper($order['payment_status']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seller Packages & Items -->
    <div class="col-lg-8">
        <h5 class="mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-boxes text-primary"></i> Multi-Vendor Order Packages (<?php echo count($sellerPackages); ?>)
        </h5>

        <?php foreach ($sellerPackages as $pkg): ?>
            <?php $pkgItems = $itemsByPackage[$pkg['id']] ?? []; ?>
            <div class="ss-card mb-4">
                <div class="ss-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <strong class="text-dark me-2">
                            <i class="bi bi-shop text-primary"></i> <?php echo e($pkg['store_name']); ?>
                        </strong>
                        <span class="ss-badge ss-badge-<?php echo match($pkg['status']) {
                            'delivered' => 'success',
                            'shipped' => 'info',
                            'processing', 'confirmed' => 'warning',
                            'cancelled' => 'danger',
                            default => 'default'
                        }; ?>">
                            <?php echo ucfirst($pkg['status']); ?>
                        </span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-light border btn-sm" data-bs-toggle="modal" data-bs-target="#pkgModal<?php echo $pkg['id']; ?>">
                            <i class="bi bi-truck"></i> Logistics & Tracking
                        </button>
                    </div>
                </div>
                <div class="ss-card-body p-0">
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Price</th>
                                    <th>Qty</th>
                                    <th>Total</th>
                                    <th>Platform Fee</th>
                                    <th>Vendor Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pkgItems as $it): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rounded border bg-light d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; overflow: hidden; flex-shrink: 0;">
                                                    <?php if (!empty($it['product_image'])): ?>
                                                        <img src="<?php echo asset($it['product_image']); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                                    <?php else: ?>
                                                        <i class="bi bi-image text-muted"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <strong class="text-dark d-block"><?php echo e($it['product_name']); ?></strong>
                                                    <span class="small text-ss-muted">
                                                        <?php if (!empty($it['variant_info'])): ?>
                                                            Variant: <?php echo e($it['variant_info']); ?> &bull; 
                                                        <?php endif; ?>
                                                        SKU: <code><?php echo e($it['sku'] ?? 'N/A'); ?></code>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo format_price($it['unit_price']); ?></td>
                                        <td><strong>&times; <?php echo (int)$it['quantity']; ?></strong></td>
                                        <td><strong><?php echo format_price($it['total_price']); ?></strong></td>
                                        <td>
                                            <span class="text-danger">-<?php echo format_price($it['commission_amount']); ?></span>
                                            <div class="small text-ss-muted">(<?php echo number_format((float)$it['commission_rate'], 1); ?>%)</div>
                                        </td>
                                        <td>
                                            <strong class="text-success"><?php echo format_price($it['seller_earning']); ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="small">
                            <?php if (!empty($pkg['shipping_carrier']) || !empty($pkg['tracking_number'])): ?>
                                <strong>Logistics:</strong> <?php echo e($pkg['shipping_carrier'] ?: 'Standard Carrier'); ?> 
                                &bull; Tracking: <code><?php echo e($pkg['tracking_number'] ?: 'N/A'); ?></code>
                            <?php else: ?>
                                <span class="text-ss-muted">No carrier or tracking assigned yet.</span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-4">
                            <div>
                                <span class="small text-ss-muted">Package Total:</span> 
                                <strong><?php echo format_price($pkg['subtotal']); ?></strong>
                            </div>
                            <div>
                                <span class="small text-ss-muted">Commission:</span> 
                                <strong class="text-danger">-<?php echo format_price($pkg['commission_amount']); ?></strong>
                            </div>
                            <div>
                                <span class="small text-ss-muted">Vendor Payout:</span> 
                                <strong class="text-success"><?php echo format_price($pkg['seller_earning']); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal for Package Logistics -->
            <div class="modal fade" id="pkgModal<?php echo $pkg['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="<?php echo url('/admin/order-details.php?id=' . $orderId); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="update_seller_package">
                            <input type="hidden" name="seller_order_id" value="<?php echo (int)$pkg['id']; ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Package Logistics: <?php echo e($pkg['store_name']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Package Status</label>
                                    <select name="package_status" class="form-select" required>
                                        <option value="pending" <?php echo $pkg['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="confirmed" <?php echo $pkg['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="processing" <?php echo $pkg['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                        <option value="shipped" <?php echo $pkg['status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                        <option value="delivered" <?php echo $pkg['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                        <option value="cancelled" <?php echo $pkg['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Shipping Carrier</label>
                                    <input type="text" name="shipping_carrier" class="form-control" placeholder="e.g. TCS, Leopards, M&P, Trax" value="<?php echo e($pkg['shipping_carrier'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Tracking Number</label>
                                    <input type="text" name="tracking_number" class="form-control" placeholder="e.g. PK-7819283719" value="<?php echo e($pkg['tracking_number'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-ss-primary">Save Package Info</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Order Lifecycle & Audit History -->
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-clock-history text-primary me-2"></i> Status Lifecycle History</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($statusHistory)): ?>
                    <div class="p-3 text-ss-muted small">No manual status changes logged yet.</div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Old Status</th>
                                    <th>New Status</th>
                                    <th>Updated By</th>
                                    <th>Comment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statusHistory as $h): ?>
                                    <tr>
                                        <td><?php echo format_date($h['created_at'], 'M d, Y h:i A'); ?></td>
                                        <td><span class="badge bg-light text-muted border"><?php echo ucfirst((string)$h['old_status']); ?></span></td>
                                        <td><span class="badge bg-primary"><?php echo ucfirst($h['new_status']); ?></span></td>
                                        <td><?php echo e(($h['first_name'] ?? 'System') . ' (' . ($h['role'] ?? 'auto') . ')'); ?></td>
                                        <td><?php echo e($h['comment'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Update Master Order Status -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('/admin/order-details.php?id=' . $orderId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_order_status">
                <div class="modal-header">
                    <h5 class="modal-title">Change Overall Order Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Target Status</label>
                        <select name="order_status" class="form-select" required>
                            <option value="pending" <?php echo $order['order_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="confirmed" <?php echo $order['order_status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="processing" <?php echo $order['order_status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="shipped" <?php echo $order['order_status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                            <option value="delivered" <?php echo $order['order_status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled" <?php echo $order['order_status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="returned" <?php echo $order['order_status'] === 'returned' ? 'selected' : ''; ?>>Returned</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Audit Note / Reason</label>
                        <textarea name="comment" class="form-control" rows="3" placeholder="Explain reason for status change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ss-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Update Payment Status -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('/admin/order-details.php?id=' . $orderId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_payment_status">
                <div class="modal-header">
                    <h5 class="modal-title">Update Payment Reconciliation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Status</label>
                        <select name="payment_status" class="form-select" required>
                            <option value="paid" <?php echo $order['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid (Payment Confirmed)</option>
                            <option value="pending" <?php echo $order['payment_status'] === 'pending' ? 'selected' : ''; ?>>Pending (Awaiting Collection)</option>
                            <option value="failed" <?php echo $order['payment_status'] === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="refunded" <?php echo $order['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            <option value="partially_refunded" <?php echo $order['payment_status'] === 'partially_refunded' ? 'selected' : ''; ?>>Partially Refunded</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ss-primary">Update Payment Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
