<?php
/**
 * ShopSphere - Seller Orders Management
 */
$page_title = 'Store Orders';
$current_page = 'orders';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/OrderService.php';

$orderService = new OrderService();
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$status = $_GET['status'] ?? '';

$filters = [];
if (!empty($status)) {
    $filters['status'] = $status;
}

$result = $orderService->getSellerOrders($seller_id, $filters, $page, $per_page);
$orders = $result['orders'];
$total = $result['total'];
$total_pages = ceil($total / $per_page);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Customer Order Allocations</h4>
        <p class="text-ss-muted small mb-0">Total <?php echo $total; ?> order packages allocated to your store</p>
    </div>
</div>

<!-- Filters -->
<div class="ss-card mb-4 p-3 bg-white">
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo url('/seller/orders.php'); ?>" class="btn btn-sm <?php echo empty($status) ? 'btn-ss-primary' : 'btn-light border'; ?>">All Orders</a>
        <a href="<?php echo url('/seller/orders.php?status=pending'); ?>" class="btn btn-sm <?php echo $status === 'pending' ? 'btn-ss-primary' : 'btn-light border'; ?>">Pending</a>
        <a href="<?php echo url('/seller/orders.php?status=processing'); ?>" class="btn btn-sm <?php echo $status === 'processing' ? 'btn-ss-primary' : 'btn-light border'; ?>">Processing</a>
        <a href="<?php echo url('/seller/orders.php?status=shipped'); ?>" class="btn btn-sm <?php echo $status === 'shipped' ? 'btn-ss-primary' : 'btn-light border'; ?>">Shipped</a>
        <a href="<?php echo url('/seller/orders.php?status=delivered'); ?>" class="btn btn-sm <?php echo $status === 'delivered' ? 'btn-ss-primary' : 'btn-light border'; ?>">Delivered</a>
        <a href="<?php echo url('/seller/orders.php?status=cancelled'); ?>" class="btn btn-sm <?php echo $status === 'cancelled' ? 'btn-ss-primary' : 'btn-light border'; ?>">Cancelled</a>
    </div>
</div>

<!-- Orders Table -->
<div class="ss-card">
    <div class="ss-card-body p-0">
        <?php if (empty($orders)): ?>
            <div class="py-5 text-center text-ss-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 text-muted"></i>
                <h5>No orders found</h5>
                <p class="small">No customer orders matching this status.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Customer Name</th>
                            <th>Items</th>
                            <th>Subtotal</th>
                            <th>Commission</th>
                            <th>Your Earnings</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $ord): ?>
                            <tr>
                                <td><strong><?php echo e($ord['order_number']); ?></strong></td>
                                <td><?php echo format_date($ord['created_at'], 'M d, Y'); ?></td>
                                <td><?php echo e($ord['customer_name'] ?? 'Customer'); ?></td>
                                <td><?php echo $ord['item_count']; ?></td>
                                <td><?php echo format_price($ord['subtotal']); ?></td>
                                <td class="text-danger">-<?php echo format_price($ord['commission_amount']); ?></td>
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
                                <td class="text-end">
                                    <a href="<?php echo url('/seller/order-details.php?id=' . $ord['id']); ?>" class="btn btn-sm btn-ss-primary">
                                        Fulfill / View
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

<?php if ($total_pages > 1): ?>
    <nav class="ss-pagination mt-4">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <a href="<?php echo url('/seller/orders.php?' . http_build_query(array_merge($_GET, ['page' => $p]))); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                <?php echo $p; ?>
            </a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
