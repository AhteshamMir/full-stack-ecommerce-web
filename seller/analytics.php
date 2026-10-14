<?php
/** ShopSphere - Seller Analytics */
$page_title = 'Sales Analytics'; $current_page = 'analytics';
require_once __DIR__ . '/header.php';

// Top selling products
$stmt = $db->prepare("SELECT name, sales_count, stock_quantity, price, (sales_count * price) as revenue 
                      FROM products WHERE seller_id = ? AND status = 'approved' 
                      ORDER BY sales_count DESC LIMIT 10");
$stmt->execute([$seller_id]);
$top_products = $stmt->fetchAll();

// Monthly revenue
$stmt = $db->prepare("SELECT DATE_FORMAT(o.created_at, '%Y-%m') as month, COUNT(DISTINCT so.id) as orders, 
                      SUM(so.seller_earning) as revenue 
                      FROM seller_orders so 
                      JOIN orders o ON so.order_id = o.id 
                      WHERE so.seller_id = ? AND so.status IN ('delivered','shipped','processing')
                      GROUP BY month ORDER BY month DESC LIMIT 6");
$stmt->execute([$seller_id]);
$monthly = $stmt->fetchAll();
?>

<h4 class="mb-4">Sales Performance Analytics</h4>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="ss-card h-100">
            <div class="ss-card-header"><h5>Top Selling Products</h5></div>
            <div class="ss-card-body p-0">
                <?php if (empty($top_products)): ?>
                    <div class="p-4 text-center text-ss-muted">No sales data available yet.</div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0">
                            <thead><tr><th>#</th><th>Product</th><th>Units Sold</th><th>Price</th><th>Est. Revenue</th><th>Stock</th></tr></thead>
                            <tbody>
                                <?php foreach ($top_products as $i => $tp): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td><strong><?php echo e(truncate($tp['name'], 30)); ?></strong></td>
                                        <td><span class="badge bg-success-subtle text-success"><?php echo $tp['sales_count']; ?> sold</span></td>
                                        <td><?php echo format_price($tp['price']); ?></td>
                                        <td><strong><?php echo format_price($tp['revenue']); ?></strong></td>
                                        <td><?php echo $tp['stock_quantity']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="ss-card h-100">
            <div class="ss-card-header"><h5>Monthly Revenue Summary</h5></div>
            <div class="ss-card-body p-0">
                <?php if (empty($monthly)): ?>
                    <div class="p-4 text-center text-ss-muted">No monthly data yet.</div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0">
                            <thead><tr><th>Month</th><th>Orders</th><th>Net Revenue</th></tr></thead>
                            <tbody>
                                <?php foreach ($monthly as $m): ?>
                                    <tr>
                                        <td><strong><?php echo e($m['month']); ?></strong></td>
                                        <td><?php echo $m['orders']; ?></td>
                                        <td class="text-ss-success fw-bold"><?php echo format_price($m['revenue']); ?></td>
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
