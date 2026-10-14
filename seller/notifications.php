<?php
/** ShopSphere - Seller Notifications */
$page_title = 'Notifications'; $current_page = 'notifications';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/NotificationService.php';

$notificationService = new NotificationService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read' && !empty($_POST['notification_id'])) {
        $notificationService->markAsRead((int)$_POST['notification_id'], $seller_id);
    } elseif ($action === 'mark_all_read') {
        $notificationService->markAllAsRead($seller_id);
    }
    flash('success', 'Notifications updated.');
    redirect(url('/seller/notifications.php'));
}

$notifications = $notificationService->getUserNotifications($seller_id, 50, 0);
$unread = $notificationService->getUnreadCount($seller_id);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Notifications <span class="badge bg-primary"><?php echo $unread; ?> unread</span></h4>
    <?php if ($unread > 0): ?>
        <form method="POST" class="d-inline">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-ss-outline btn-sm"><i class="bi bi-check-all"></i> Mark All Read</button>
        </form>
    <?php endif; ?>
</div>

<div class="ss-card">
    <?php if (empty($notifications)): ?>
        <div class="py-5 text-center text-ss-muted"><i class="bi bi-bell-slash fs-1 d-block mb-2"></i><h5>No notifications</h5></div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $n): ?>
                <div class="list-group-item d-flex align-items-start gap-3 py-3 <?php echo !$n['is_read'] ? 'bg-primary-subtle' : ''; ?>">
                    <div class="flex-shrink-0">
                        <i class="bi bi-<?php echo match(true) { str_contains($n['type'] ?? '', 'order') => 'bag-check', str_contains($n['type'] ?? '', 'product') => 'box-seam', str_contains($n['type'] ?? '', 'payout') => 'wallet2', default => 'bell' }; ?> fs-4 text-primary"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-bold mb-1"><?php echo e($n['title']); ?></div>
                        <p class="small text-ss-muted mb-1"><?php echo e($n['message']); ?></p>
                        <small class="text-ss-muted"><?php echo time_ago($n['created_at']); ?></small>
                    </div>
                    <div class="flex-shrink-0 d-flex gap-2 align-items-center">
                        <?php if (!empty($n['link'])): ?>
                            <a href="<?php echo e(url($n['link'])); ?>" class="btn btn-sm btn-light border">View</a>
                        <?php endif; ?>
                        <?php if (!$n['is_read']): ?>
                            <form method="POST" class="d-inline">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?php echo $n['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-light border" title="Mark as read"><i class="bi bi-check"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
