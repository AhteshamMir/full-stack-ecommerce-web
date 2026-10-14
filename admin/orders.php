<?php
/**
 * ShopSphere - All Orders Oversight
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

// Filter & Search Parameters
$search = trim($_GET['search'] ?? '');
$orderStatus = trim($_GET['order_status'] ?? '');
$paymentStatus = trim($_GET['payment_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(o.order_number LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)";
    $params['search'] = "%{$search}%";
}

if ($orderStatus !== '' && in_array($orderStatus, ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'], true)) {
    $where[] = "o.order_status = :order_status";
    $params['order_status'] = $orderStatus;
}

if ($paymentStatus !== '' && in_array($paymentStatus, ['pending', 'paid', 'failed', 'refunded', 'partially_refunded'], true)) {
    $where[] = "o.payment_status = :payment_status";
    $params['payment_status'] = $paymentStatus;
}

if ($dateFrom !== '') {
    $where[] = "o.created_at >= :date_from";
    $params['date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = "o.created_at <= :date_to";
    $params['date_to'] = $dateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// Total Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o JOIN users u ON o.user_id = u.id WHERE {$whereClause}");
$countStmt->execute($params);
$totalOrders = (int)$countStmt->fetchColumn();

$pagination = paginate($totalOrders, $perPage, $page);

// Fetch Orders
$query = "
    SELECT 
        o.*,
        u.first_name, u.last_name, u.email, u.phone,
        (SELECT COUNT(*) FROM seller_orders so WHERE so.order_id = o.id) AS packages_count,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items_count,
        (SELECT COALESCE(SUM(so.commission_amount), 0) FROM seller_orders so WHERE so.order_id = o.id) AS commission_earned
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE {$whereClause}
    ORDER BY o.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

// Overview Aggregate Metrics
$statTotalGmv = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_status != 'cancelled'")->fetchColumn();
$statTotalCount = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$statPending = (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('pending', 'processing')")->fetchColumn();
$statDelivered = (int)$db->query("SELECT COUNT(*) FROM orders WHERE order_status = 'delivered'")->fetchColumn();

$page_title = 'Marketplace Orders';
$current_page = 'orders';
require_once __DIR__ . '/header.php';
?>

<!-- Metrics Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-currency-dollar"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($statTotalGmv); ?></h3>
                <p>Gross Merchandise Value</p>
                <div class="small text-ss-muted">Excludes cancelled</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-bag-check"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statTotalCount); ?></h3>
                <p>Total Orders Placed</p>
                <div class="small text-ss-muted">Platform lifetime</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-clock-history"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statPending); ?></h3>
                <p>Awaiting Fulfillment</p>
                <div class="small text-warning"><i class="bi bi-box-seam"></i> Pending or processing</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-check-circle"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statDelivered); ?></h3>
                <p>Delivered Orders</p>
                <div class="small text-success"><i class="bi bi-truck"></i> Completed cycle</div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/orders.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control" placeholder="Search order #, customer, email..." value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-2">
                <select name="order_status" class="form-select">
                    <option value="">All Order Statuses</option>
                    <option value="pending" <?php echo $orderStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="confirmed" <?php echo $orderStatus === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="processing" <?php echo $orderStatus === 'processing' ? 'selected' : ''; ?>>Processing</option>
                    <option value="shipped" <?php echo $orderStatus === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                    <option value="delivered" <?php echo $orderStatus === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $orderStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="returned" <?php echo $orderStatus === 'returned' ? 'selected' : ''; ?>>Returned</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="payment_status" class="form-select">
                    <option value="">All Payment Statuses</option>
                    <option value="paid" <?php echo $paymentStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="pending" <?php echo $paymentStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="failed" <?php echo $paymentStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    <option value="refunded" <?php echo $paymentStatus === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control" placeholder="From Date" value="<?php echo e($dateFrom); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control" placeholder="To Date" value="<?php echo e($dateTo); ?>">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-ss-primary w-100" title="Apply Filter"><i class="bi bi-funnel"></i></button>
                <?php if ($search !== '' || $orderStatus !== '' || $paymentStatus !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="<?php echo url('/admin/orders.php'); ?>" class="btn btn-light border" title="Reset Filters"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Orders Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Orders Directory (<?php echo number_format($totalOrders); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($orders)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-bag-x fs-1 d-block mb-2"></i>
                <p class="mb-0">No orders found matching the filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Total Amount</th>
                            <th>Packages & Items</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo url('/admin/order-details.php?id=' . $o['id']); ?>" class="fw-bold text-dark text-decoration-none">
                                        <?php echo e($o['order_number']); ?>
                                    </a>
                                </td>
                                <td>
                                    <strong><?php echo e($o['first_name'] . ' ' . $o['last_name']); ?></strong>
                                    <div class="small text-ss-muted"><?php echo e($o['email']); ?></div>
                                </td>
                                <td>
                                    <?php echo format_date($o['created_at'], 'M d, Y'); ?>
                                    <div class="small text-ss-muted"><?php echo format_date($o['created_at'], 'h:i A'); ?></div>
                                </td>
                                <td>
                                    <strong class="text-dark"><?php echo format_price($o['total_amount']); ?></strong>
                                    <?php if ($o['commission_earned'] > 0): ?>
                                        <div class="small text-success">+<?php echo format_price($o['commission_earned']); ?> comm.</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php echo (int)$o['packages_count']; ?> package(s) &bull; <?php echo (int)$o['items_count']; ?> item(s)
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo match($o['payment_status']) {
                                        'paid' => 'bg-success',
                                        'pending' => 'bg-warning text-dark',
                                        'refunded' => 'bg-info text-dark',
                                        default => 'bg-danger'
                                    }; ?>">
                                        <?php echo ucfirst($o['payment_status']); ?>
                                    </span>
                                    <div class="small text-ss-muted mt-1 text-uppercase"><?php echo e($o['payment_method']); ?></div>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($o['order_status']) {
                                        'delivered' => 'success',
                                        'shipped' => 'info',
                                        'processing', 'confirmed' => 'warning',
                                        'cancelled' => 'danger',
                                        default => 'default'
                                    }; ?>">
                                        <?php echo ucfirst($o['order_status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?php echo url('/admin/order-details.php?id=' . $o['id']); ?>" class="btn btn-ss-outline btn-sm">
                                        <i class="bi bi-eye"></i> Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 pb-3">
                <?php echo render_admin_pagination($pagination, url('/admin/orders.php'), [
                    'search' => $search,
                    'order_status' => $orderStatus,
                    'payment_status' => $paymentStatus,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
