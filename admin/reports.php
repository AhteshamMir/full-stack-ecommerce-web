<?php
/**
 * ShopSphere - Financial & Marketplace Analytics Reports
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

// Date Range Filtering
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));

$whereOrders = "o.created_at >= :dfrom AND o.created_at <= :dto";
$whereSellerOrders = "so.created_at >= :dfrom AND so.created_at <= :dto";
$dateParams = [
    'dfrom' => $dateFrom . ' 00:00:00',
    'dto'   => $dateTo . ' 23:59:59'
];

// Financial Aggregates
$stmt = $db->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        COALESCE(SUM(total_amount), 0) AS total_gmv,
        COALESCE(SUM(shipping_amount), 0) AS total_shipping,
        COALESCE(SUM(discount_amount), 0) AS total_discounts,
        COALESCE(SUM(tax_amount), 0) AS total_tax
    FROM orders o
    WHERE {$whereOrders} AND o.order_status != 'cancelled'
");
$stmt->execute($dateParams);
$metrics = $stmt->fetch();

$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(commission_amount), 0) AS platform_commission,
        COALESCE(SUM(seller_earning), 0) AS seller_earnings
    FROM seller_orders so
    WHERE {$whereSellerOrders} AND so.status != 'cancelled'
");
$stmt->execute($dateParams);
$feeMetrics = $stmt->fetch();

// Top Performing Vendors by Sales in range
$stmt = $db->prepare("
    SELECT 
        sp.store_name, sp.user_id,
        COUNT(so.id) AS order_packages,
        COALESCE(SUM(so.subtotal), 0) AS gross_sales,
        COALESCE(SUM(so.commission_amount), 0) AS commission_paid,
        COALESCE(SUM(so.seller_earning), 0) AS net_payout
    FROM seller_orders so
    JOIN seller_profiles sp ON so.seller_id = sp.user_id
    WHERE {$whereSellerOrders} AND so.status != 'cancelled'
    GROUP BY sp.user_id, sp.store_name
    ORDER BY gross_sales DESC
    LIMIT 10
");
$stmt->execute($dateParams);
$topSellers = $stmt->fetchAll();

// Sales by Category
$stmt = $db->prepare("
    SELECT 
        c.name AS category_name,
        COUNT(oi.id) AS units_sold,
        COALESCE(SUM(oi.total_price), 0) AS category_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    JOIN orders o ON oi.order_id = o.id
    WHERE {$whereOrders} AND o.order_status != 'cancelled'
    GROUP BY c.id, c.name
    ORDER BY category_revenue DESC
    LIMIT 10
");
$stmt->execute($dateParams);
$categorySales = $stmt->fetchAll();

// Orders by Payment Method
$stmt = $db->prepare("
    SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS volume
    FROM orders o
    WHERE {$whereOrders} AND o.order_status != 'cancelled'
    GROUP BY payment_method
    ORDER BY volume DESC
");
$stmt->execute($dateParams);
$paymentDistribution = $stmt->fetchAll();

$page_title = 'Financial & Marketplace Reports';
$current_page = 'reports';
require_once __DIR__ . '/header.php';
?>

<!-- Date Filter Header -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/reports.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-4">
                <label class="form-label small fw-bold mb-1">Date Range (From)</label>
                <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold mb-1">Date Range (To)</label>
                <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>" required>
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Generate Report</button>
                <button type="button" class="btn btn-light border" onclick="window.print()"><i class="bi bi-printer"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Revenue Key Figures -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-cash-stack"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($metrics['total_gmv']); ?></h3>
                <p>Gross Merchandise Value</p>
                <div class="small text-ss-muted"><?php echo number_format((int)$metrics['total_orders']); ?> orders placed</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-pie-chart"></i></div>
            <div class="ss-stat-info">
                <h3 class="text-ss-accent"><?php echo format_price($feeMetrics['platform_commission']); ?></h3>
                <p>Platform Retained Margin</p>
                <div class="trend up"><i class="bi bi-wallet2"></i> Commission revenue</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-shop"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($feeMetrics['seller_earnings']); ?></h3>
                <p>Vendor Net Payable</p>
                <div class="small text-ss-muted">To be settled with sellers</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-ticket-perforated"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($metrics['total_discounts']); ?></h3>
                <p>Promotional Vouchers</p>
                <div class="small text-ss-muted">Customer discounts</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Top Sellers Ranking -->
    <div class="col-lg-7">
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-trophy text-warning me-2"></i> Top Vendor Performance in Selected Period</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($topSellers)): ?>
                    <div class="p-4 text-center text-ss-muted">No seller orders recorded in this date range.</div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Store Name</th>
                                    <th>Orders</th>
                                    <th>Gross GMV</th>
                                    <th>Platform Fee</th>
                                    <th>Seller Net</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSellers as $ts): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo url('/admin/seller-details.php?id=' . $ts['user_id']); ?>" class="fw-bold text-dark">
                                                <?php echo e($ts['store_name']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo (int)$ts['order_packages']; ?></td>
                                        <td><strong><?php echo format_price($ts['gross_sales']); ?></strong></td>
                                        <td class="text-danger">-<?php echo format_price($ts['commission_paid']); ?></td>
                                        <td class="text-success fw-bold"><?php echo format_price($ts['net_payout']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Category Sales Breakdown -->
    <div class="col-lg-5">
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5><i class="bi bi-tags text-primary me-2"></i> Category Revenue</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($categorySales)): ?>
                    <div class="p-4 text-center text-ss-muted">No product sales in this period.</div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Units Sold</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categorySales as $cs): ?>
                                    <tr>
                                        <td><strong><?php echo e($cs['category_name']); ?></strong></td>
                                        <td><?php echo number_format((int)$cs['units_sold']); ?></td>
                                        <td class="text-end fw-bold"><?php echo format_price($cs['category_revenue']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Methods Distribution -->
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-credit-card text-success me-2"></i> Payment Methods Volume</h5>
            </div>
            <div class="ss-card-body p-0">
                <div class="ss-table-responsive">
                    <table class="ss-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Gateway / Channel</th>
                                <th>Orders</th>
                                <th class="text-end">Total Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentDistribution as $pd): ?>
                                <tr>
                                    <td><strong class="text-uppercase"><?php echo e($pd['payment_method']); ?></strong></td>
                                    <td><?php echo (int)$pd['count']; ?></td>
                                    <td class="text-end fw-bold"><?php echo format_price($pd['volume']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
