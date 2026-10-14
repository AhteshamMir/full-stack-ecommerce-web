<?php
/**
 * ShopSphere - Seller Dashboard Overview
 */
$page_title = 'Dashboard Overview';
$current_page = 'dashboard';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/OrderService.php';
require_once __DIR__ . '/../classes/CommissionService.php';

$orderService = new OrderService();
$commissionService = new CommissionService();

// Metrics for current seller
$earnings = $commissionService->getSellerEarnings($seller_id);
$balance = $commissionService->getSellerBalance($seller_id);

// Product counts
$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE seller_id = ?");
$stmt->execute([$seller_id]);
$total_products = (int)$stmt->fetchColumn();

// Low stock items
$stmt = $db->prepare("SELECT id, name, stock_quantity, low_stock_threshold 
                      FROM products 
                      WHERE seller_id = ? AND stock_quantity <= low_stock_threshold AND status = 'approved' 
                      ORDER BY stock_quantity ASC LIMIT 5");
$stmt->execute([$seller_id]);
$low_stock_items = $stmt->fetchAll();

// Recent Seller Orders
$recent_orders = $orderService->getSellerOrders($seller_id, [], 1, 5);
?>

<?php if (($seller['status'] ?? '') === 'pending'): ?>
    <div class="ss-alert ss-alert-warning mb-4">
        <i class="bi bi-clock-history fs-4"></i>
        <div>
            <h6 class="fw-bold mb-1">Application Under Review</h6>
            <p class="mb-0 small">Your seller registration is currently pending review by platform administrators. Once approved, you can immediately begin publishing products and accepting orders.</p>
        </div>
    </div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-wallet2"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($earnings['net_earnings'] ?? 0); ?></h3>
                <p>Total Net Earnings</p>
                <div class="trend up"><i class="bi bi-arrow-up"></i> Cleared Sales</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-cash-stack"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($balance['available_balance'] ?? 0); ?></h3>
                <p>Available for Payout</p>
                <a href="<?php echo url('/seller/payouts.php'); ?>" class="small text-ss-accent text-decoration-none">Withdraw &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-box-seam"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo $total_products; ?></h3>
                <p>Active Products</p>
                <a href="<?php echo url('/seller/products.php'); ?>" class="small text-ss-accent text-decoration-none">Manage &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-bag-check"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo (int)($earnings['order_count'] ?? 0); ?></h3>
                <p>Fulfillable Orders</p>
                <a href="<?php echo url('/seller/orders.php'); ?>" class="small text-ss-accent text-decoration-none">View All &rarr;</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Recent Orders Table -->
    <div class="col-lg-8">
        <div class="ss-card h-100">
            <div class="ss-card-header">
                <h5>Recent Store Orders</h5>
                <a href="<?php echo url('/seller/orders.php'); ?>" class="btn btn-ss-outline btn-sm">View All Orders</a>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($recent_orders['orders'])): ?>
                    <div class="p-4 text-center text-ss-muted">
                        <i class="bi bi-bag-x fs-2 d-block mb-2"></i>
                        No orders received yet. Make sure your catalog is stocked!
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Net Payout</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_orders['orders'] as $ord): ?>
                                    <tr>
                                        <td><strong><?php echo e($ord['order_number']); ?></strong></td>
                                        <td><?php echo e($ord['customer_name'] ?? 'Customer'); ?></td>
                                        <td><?php echo format_date($ord['created_at'], 'M d, Y'); ?></td>
                                        <td><?php echo $ord['item_count']; ?></td>
                                        <td><strong class="text-ss-success"><?php echo format_price($ord['seller_earning']); ?></strong></td>
                                        <td>
                                            <span class="ss-badge ss-badge-<?php 
                                                echo match($ord['status']) {
                                                    'delivered' => 'success',
                                                    'shipped' => 'info',
                                                    'processing' => 'warning',
                                                    'cancelled' => 'danger',
                                                    default => 'default'
                                                };
                                            ?>">
                                                <?php echo ucfirst($ord['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="<?php echo url('/seller/order-details.php?id=' . $ord['id']); ?>" class="btn btn-sm btn-light border">
                                                Details
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Low Stock Alerts -->
    <div class="col-lg-4">
        <div class="ss-card h-100">
            <div class="ss-card-header">
                <h5>Inventory Alerts</h5>
                <a href="<?php echo url('/seller/inventory.php'); ?>" class="btn btn-ss-outline btn-sm">Manage Stock</a>
            </div>
            <div class="ss-card-body p-3">
                <?php if (empty($low_stock_items)): ?>
                    <div class="text-center py-4 text-ss-muted">
                        <i class="bi bi-check-circle text-success fs-2 d-block mb-2"></i>
                        All products have healthy inventory levels!
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($low_stock_items as $lsi): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <h6 class="mb-0 small fw-bold"><?php echo e(truncate($lsi['name'], 25)); ?></h6>
                                    <small class="text-danger"><i class="bi bi-exclamation-triangle"></i> Threshold: <?php echo $lsi['low_stock_threshold']; ?></small>
                                </div>
                                <span class="badge bg-danger-subtle text-danger px-2 py-1">
                                    <?php echo $lsi['stock_quantity']; ?> left
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
