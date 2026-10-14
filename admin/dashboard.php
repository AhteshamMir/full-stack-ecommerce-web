<?php
/**
 * ShopSphere - Admin Executive Dashboard
 */
$page_title = 'Executive Dashboard';
$current_page = 'dashboard';
require_once __DIR__ . '/header.php';

// Calculate Platform Metrics
$total_gmv = 0;
$total_commissions = 0;
$total_orders = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as gmv FROM orders");
    $res = $stmt->fetch();
    $total_orders = (int)($res['count'] ?? 0);
    $total_gmv = (float)($res['gmv'] ?? 0);

    $stmt = $db->query("SELECT COALESCE(SUM(commission_amount), 0) FROM seller_orders");
    $total_commissions = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

// User Counts
$stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'");
$total_customers = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM seller_profiles WHERE status = 'approved'");
$total_sellers = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM seller_profiles WHERE status = 'pending'");
$pending_sellers = (int)$stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM payouts WHERE status = 'pending'");
$pending_payouts = (int)$stmt->fetchColumn();

// Recent Orders
$stmt = $db->query("SELECT o.*, u.first_name, u.last_name FROM orders o 
                    JOIN users u ON o.user_id = u.id 
                    ORDER BY o.id DESC LIMIT 5");
$recent_orders = $stmt->fetchAll();

// Pending Seller Applications
$stmt = $db->query("SELECT sp.*, u.first_name, u.last_name, u.email, u.phone FROM seller_profiles sp 
                    JOIN users u ON sp.user_id = u.id 
                    WHERE sp.status = 'pending' 
                    ORDER BY sp.user_id DESC LIMIT 5");
$pending_apps = $stmt->fetchAll();
?>

<!-- Metric Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-currency-dollar"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($total_gmv); ?></h3>
                <p>Gross Merchandise Value</p>
                <div class="trend up"><i class="bi bi-arrow-up"></i> Total Orders (<?php echo $total_orders; ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-pie-chart-fill"></i></div>
            <div class="ss-stat-info">
                <h3 class="text-ss-accent"><?php echo format_price($total_commissions); ?></h3>
                <p>Platform Commission Revenue</p>
                <div class="trend up"><i class="bi bi-wallet2"></i> Retained Margin</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-shop"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo $total_sellers; ?></h3>
                <p>Active Verified Sellers</p>
                <?php if ($pending_sellers > 0): ?>
                    <span class="badge bg-warning text-dark mt-1"><?php echo $pending_sellers; ?> Pending Approval</span>
                <?php else: ?>
                    <div class="small text-ss-muted mt-1">All approved</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-people-fill"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo $total_customers; ?></h3>
                <p>Registered Customers</p>
                <?php if ($pending_payouts > 0): ?>
                    <span class="badge bg-danger mt-1"><?php echo $pending_payouts; ?> Payouts Awaiting Action</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Pending Seller Applications -->
    <div class="col-lg-6">
        <div class="ss-card h-100">
            <div class="ss-card-header">
                <h5>Pending Seller Applications (<?php echo count($pending_apps); ?>)</h5>
                <a href="<?php echo url('/admin/sellers.php'); ?>" class="btn btn-ss-outline btn-sm">All Sellers</a>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($pending_apps)): ?>
                    <div class="p-4 text-center text-ss-muted">
                        <i class="bi bi-check2-all fs-2 d-block text-success mb-2"></i>
                        No pending seller registrations!
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0">
                            <thead>
                                <tr>
                                    <th>Store / Applicant</th>
                                    <th>Contact</th>
                                    <th>Applied Date</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_apps as $pa): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($pa['store_name']); ?></strong>
                                            <div class="small text-ss-muted"><?php echo e($pa['first_name'] . ' ' . $pa['last_name']); ?></div>
                                        </td>
                                        <td>
                                            <div class="small"><?php echo e($pa['email']); ?></div>
                                            <div class="small text-ss-muted"><?php echo e($pa['phone'] ?? ''); ?></div>
                                        </td>
                                        <td><?php echo format_date($pa['created_at'] ?? 'now', 'M d, Y'); ?></td>
                                        <td class="text-end">
                                            <a href="<?php echo url('/admin/seller-details.php?id=' . $pa['user_id']); ?>" class="btn btn-ss-primary btn-sm">
                                                Review
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

    <!-- Recent Platform Orders -->
    <div class="col-lg-6">
        <div class="ss-card h-100">
            <div class="ss-card-header">
                <h5>Recent Marketplace Orders</h5>
                <a href="<?php echo url('/admin/orders.php'); ?>" class="btn btn-ss-outline btn-sm">View All Orders</a>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($recent_orders)): ?>
                    <div class="p-4 text-center text-ss-muted">
                        <i class="bi bi-bag-x fs-2 d-block mb-2"></i>
                        No orders recorded on platform yet.
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th class="text-end">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_orders as $ro): ?>
                                    <tr>
                                        <td><strong><?php echo e($ro['order_number']); ?></strong></td>
                                        <td><?php echo e($ro['first_name'] . ' ' . $ro['last_name']); ?></td>
                                        <td><strong><?php echo format_price($ro['total_amount']); ?></strong></td>
                                        <td>
                                            <span class="ss-badge ss-badge-<?php echo match($ro['order_status']) {
                                                'delivered' => 'success',
                                                'shipped' => 'info',
                                                'processing' => 'warning',
                                                'cancelled' => 'danger',
                                                default => 'default'
                                            }; ?>">
                                                <?php echo ucfirst($ro['order_status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?php echo url('/admin/order-details.php?id=' . $ro['id']); ?>" class="btn btn-light border btn-sm">
                                                Inspect
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
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
