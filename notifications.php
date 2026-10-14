<?php
/**
 * ShopSphere - In-App Customer Notifications List
 *
 * Displays account alerts, order status changes, and announcements
 * with visual unread indicators and bulk management capabilities.
 *
 * @package ShopSphere\Customer
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';

// Enforce customer login
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();

// Ensure notifications table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'system',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('[ShopSphere Notifications Table Check] ' . $e->getMessage());
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token expired.'], 403);
        }
        flash('error', 'Session expired. Please try again.');
        redirect(url('notifications.php'));
    }

    $action = sanitize_string($_POST['action'] ?? '');
    $notifId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

    // 1. Mark individual notification as read
    if ($action === 'mark_read' && $notifId) {
        try {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $notifId, ':uid' => $userId]);

            if (is_ajax()) {
                json_response(['success' => true]);
            }
            flash('success', 'Notification marked as read.');
        } catch (Throwable $e) {
            error_log('[ShopSphere Mark Read Error] ' . $e->getMessage());
            if (is_ajax()) {
                json_response(['success' => false, 'error' => 'Failed to update.'], 500);
            }
        }
        redirect(url('notifications.php'));
    }

    // 2. Mark all as read
    if ($action === 'mark_all_read') {
        try {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND (is_read = 0 OR is_read IS FALSE)');
            $stmt->execute([':uid' => $userId]);

            if (is_ajax()) {
                json_response(['success' => true]);
            }
            flash('success', 'All notifications have been marked as read.');
        } catch (Throwable $e) {
            error_log('[ShopSphere Mark All Read Error] ' . $e->getMessage());
            if (is_ajax()) {
                json_response(['success' => false, 'error' => 'Failed to update.'], 500);
            }
            flash('error', 'Failed to update notifications.');
        }
        redirect(url('notifications.php'));
    }

    // 3. Delete notification
    if ($action === 'delete' && $notifId) {
        try {
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :uid');
            $stmt->execute([':id' => $notifId, ':uid' => $userId]);

            if (is_ajax()) {
                json_response(['success' => true]);
            }
            flash('success', 'Notification deleted.');
        } catch (Throwable $e) {
            error_log('[ShopSphere Delete Notif Error] ' . $e->getMessage());
            if (is_ajax()) {
                json_response(['success' => false, 'error' => 'Failed to delete.'], 500);
            }
        }
        redirect(url('notifications.php'));
    }
}

// Total and unread counts
$totalNotifications = 0;
$unreadCount = 0;
try {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid');
    $stmtCount->execute([':uid' => $userId]);
    $totalNotifications = (int) $stmtCount->fetchColumn();

    $stmtUnread = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND (is_read = 0 OR is_read IS FALSE)');
    $stmtUnread->execute([':uid' => $userId]);
    $unreadCount = (int) $stmtUnread->fetchColumn();
} catch (Throwable $e) {
    error_log('[ShopSphere Notifications Count Error] ' . $e->getMessage());
}

// Pagination setup
$currentPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$perPage = 15;
$pagination = paginate($totalNotifications, $perPage, $currentPage);

// Fetch paginated notifications
$notifications = [];
try {
    $sql = 'SELECT * FROM notifications 
            WHERE user_id = :uid 
            ORDER BY created_at DESC, id DESC 
            LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Notifications Query Error] ' . $e->getMessage());
    $notifications = [];
}

$page_title = 'Notifications';
$active_page = 'notifications';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li>Notifications</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Notifications Content -->
        <div class="col-lg-9 col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-1">
                        <i class="bi bi-bell-fill text-ss-accent me-2"></i> Account Notifications
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge bg-primary fs-6 ms-2"><?= $unreadCount ?> Unread</span>
                        <?php endif; ?>
                    </h4>
                    <p class="text-muted small mb-0">Stay informed about orders, shipping milestones, and account activities.</p>
                </div>

                <?php if ($unreadCount > 0): ?>
                    <form action="<?= url('notifications.php') ?>" method="POST" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-check2-all me-1"></i> Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Notifications Card List -->
            <div class="ss-card shadow-sm">
                <div class="ss-card-body p-0">
                    <?php if (empty($notifications)): ?>
                        <div class="ss-empty-state py-5">
                            <i class="bi bi-bell-slash text-muted opacity-50 display-3 mb-3 d-block"></i>
                            <h5>No Notifications</h5>
                            <p class="text-muted small mb-0">You're all caught up! Updates regarding your orders will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush" id="notificationList">
                            <?php foreach ($notifications as $notif): ?>
                                <?php
                                $isUnread = empty($notif['is_read']);
                                $type = strtolower((string) ($notif['type'] ?? 'system'));

                                $iconInfo = match ($type) {
                                    'order'   => ['icon' => 'bi-box-seam', 'bg' => 'bg-primary-subtle text-primary'],
                                    'account' => ['icon' => 'bi-person-check', 'bg' => 'bg-success-subtle text-success'],
                                    'stock'   => ['icon' => 'bi-exclamation-triangle', 'bg' => 'bg-warning-subtle text-warning'],
                                    default   => ['icon' => 'bi-bell', 'bg' => 'bg-info-subtle text-info'],
                                };

                                // Normalize notification target link
                                $linkUrl = !empty($notif['link']) ? url(ltrim((string) $notif['link'], '/')) : null;
                                ?>
                                <div class="list-group-item p-3 d-flex gap-3 align-items-start transition-all <?= $isUnread ? 'bg-light border-start border-3 border-primary' : '' ?>" id="notif-row-<?= $notif['id'] ?>">
                                    <!-- Notification Icon -->
                                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 <?= $iconInfo['bg'] ?>" style="width: 44px; height: 44px; font-size: 1.25rem;">
                                        <i class="bi <?= $iconInfo['icon'] ?>"></i>
                                    </div>

                                    <!-- Notification Body -->
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <h6 class="fw-bold mb-1 text-dark <?= $isUnread ? 'fw-bold' : 'fw-semibold' ?>">
                                                <?php if ($linkUrl): ?>
                                                    <a href="<?= e($linkUrl) ?>" class="text-dark text-decoration-none hover-accent">
                                                        <?= e($notif['title']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= e($notif['title']) ?>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted flex-shrink-0" style="font-size: 0.75rem;">
                                                <?= e(time_ago($notif['created_at'])) ?>
                                            </small>
                                        </div>

                                        <p class="text-muted small mb-2" style="line-height: 1.5;">
                                            <?= e($notif['message']) ?>
                                        </p>

                                        <div class="d-flex align-items-center gap-3">
                                            <?php if ($linkUrl): ?>
                                                <a href="<?= e($linkUrl) ?>" class="btn btn-sm btn-link text-decoration-none p-0 text-ss-accent small fw-semibold">
                                                    View Details <i class="bi bi-arrow-right"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($isUnread): ?>
                                                <form action="<?= url('notifications.php') ?>" method="POST" class="d-inline mark-read-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-link text-muted text-decoration-none p-0 small">
                                                        <i class="bi bi-check2"></i> Mark as Read
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form action="<?= url('notifications.php') ?>" method="POST" class="d-inline ms-auto delete-notif-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="notification_id" value="<?= $notif['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none p-0 small opacity-75 hover-opacity-100" title="Delete notification">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($pagination['total_pages'] > 1): ?>
                            <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <small class="text-muted">
                                    Showing <?= $pagination['from'] ?> to <?= $pagination['to'] ?> of <?= $pagination['total'] ?> notifications
                                </small>
                                <nav aria-label="Notifications pagination">
                                    <ul class="ss-pagination mb-0 mt-0">
                                        <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= $pagination['has_prev'] ? url('notifications.php?page=' . $pagination['prev_page']) : '#' ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>
                                        <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                                            <li class="page-item">
                                                <a class="page-link <?= $p === $currentPage ? 'active' : '' ?>" href="<?= url('notifications.php?page=' . $p) ?>">
                                                    <?= $p ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= $pagination['has_next'] ? url('notifications.php?page=' . $pagination['next_page']) : '#' ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
