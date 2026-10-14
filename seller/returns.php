<?php
/** ShopSphere - Seller Returns */
$page_title = 'Return Requests'; $current_page = 'returns';
require_once __DIR__ . '/header.php';

$stmt = $db->prepare("SELECT rr.*, oi.product_name, o.order_number, u.first_name, u.last_name 
                      FROM return_requests rr 
                      JOIN order_items oi ON rr.order_item_id = oi.id 
                      JOIN orders o ON rr.order_id = o.id 
                      JOIN users u ON rr.user_id = u.id 
                      JOIN seller_orders so ON rr.seller_order_id = so.id 
                      WHERE so.seller_id = ? 
                      ORDER BY rr.created_at DESC");
$stmt->execute([$seller_id]);
$returns = $stmt->fetchAll();
?>

<h4 class="mb-4">Customer Return Requests</h4>

<div class="ss-card">
    <div class="ss-card-body p-0">
        <?php if (empty($returns)): ?>
            <div class="py-5 text-center text-ss-muted"><i class="bi bi-arrow-return-left fs-1 d-block mb-2"></i><h5>No return requests filed</h5><p class="small">Customer return requests will show up here for resolution.</p></div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead><tr><th>Return #</th><th>Order</th><th>Product</th><th>Customer</th><th>Reason</th><th>Status</th><th>Requested</th></tr></thead>
                    <tbody>
                        <?php foreach ($returns as $r): ?>
                            <tr>
                                <td><strong>#RR-<?php echo $r['id']; ?></strong></td>
                                <td><?php echo e($r['order_number']); ?></td>
                                <td><?php echo e(truncate($r['product_name'], 25)); ?></td>
                                <td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                <td><?php echo e(truncate($r['reason'], 40)); ?></td>
                                <td><span class="ss-badge ss-badge-<?php echo match($r['status']) { 'approved' => 'success', 'rejected' => 'danger', 'completed' => 'info', default => 'warning' }; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                <td><?php echo format_date($r['created_at'], 'M d, Y'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
