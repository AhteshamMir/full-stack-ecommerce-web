<?php
/** ShopSphere - Seller Reviews */
$page_title = 'Product Reviews'; $current_page = 'reviews';
require_once __DIR__ . '/header.php';

$stmt = $db->prepare("SELECT r.*, p.name as product_name, u.first_name, u.last_name 
                      FROM reviews r 
                      JOIN products p ON r.product_id = p.id 
                      JOIN users u ON r.user_id = u.id 
                      WHERE p.seller_id = ? 
                      ORDER BY r.created_at DESC LIMIT 50");
$stmt->execute([$seller_id]);
$reviews = $stmt->fetchAll();
?>

<h4 class="mb-4">Customer Reviews on Your Products</h4>

<div class="ss-card">
    <div class="ss-card-body p-0">
        <?php if (empty($reviews)): ?>
            <div class="py-5 text-center text-ss-muted">
                <i class="bi bi-chat-left-text fs-1 d-block mb-2"></i>
                <h5>No customer reviews yet</h5>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead><tr><th>Product</th><th>Customer</th><th>Rating</th><th>Title</th><th>Comment</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($reviews as $r): ?>
                            <tr>
                                <td><strong><?php echo e(truncate($r['product_name'], 25)); ?></strong></td>
                                <td><?php echo e($r['first_name'] . ' ' . substr($r['last_name'], 0, 1) . '.'); ?></td>
                                <td><?php for ($i = 1; $i <= 5; $i++) echo '<i class="bi bi-star' . ($i <= $r['rating'] ? '-fill text-warning' : '') . '"></i>'; ?></td>
                                <td><?php echo e($r['title']); ?></td>
                                <td><?php echo e(truncate($r['comment'], 40)); ?></td>
                                <td><span class="ss-badge ss-badge-<?php echo $r['status'] === 'approved' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'warning'); ?>"><?php echo ucfirst($r['status']); ?></span></td>
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
