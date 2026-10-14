<?php
/**
 * ShopSphere - Customer Reviews & Rating Moderation
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

// Helper to sync product average rating
function sync_product_rating(PDO $db, int $productId): void {
    $stmt = $db->prepare("
        SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS count_rating 
        FROM reviews 
        WHERE product_id = :id AND status = 'approved'
    ");
    $stmt->execute(['id' => $productId]);
    $res = $stmt->fetch();

    $avg = round((float)($res['avg_rating'] ?? 0), 2);
    $count = (int)($res['count_rating'] ?? 0);

    $db->prepare("UPDATE products SET rating_avg = :avg, rating_count = :count, updated_at = NOW() WHERE id = :id")
       ->execute(['avg' => $avg, 'count' => $count, 'id' => $productId]);
}

// Handle Actions (Approve, Reject, Respond, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $reviewId = (int)($_POST['review_id'] ?? 0);

    if ($reviewId <= 0) {
        flash('error', 'Invalid review identifier.');
        redirect(url('/admin/reviews.php'));
    }

    $stmt = $db->prepare("SELECT * FROM reviews WHERE id = :id");
    $stmt->execute(['id' => $reviewId]);
    $review = $stmt->fetch();

    if (!$review) {
        flash('error', 'Review not found.');
        redirect(url('/admin/reviews.php'));
    }

    if ($action === 'approve') {
        $stmt = $db->prepare("UPDATE reviews SET status = 'approved', reported = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $reviewId]);

        sync_product_rating($db, (int)$review['product_id']);
        log_audit($db, 'approve_review', 'reviews', $reviewId, ['status' => $review['status']], ['status' => 'approved']);

        flash('success', 'Review approved and published to product page.');
        redirect(url('/admin/reviews.php'));

    } elseif ($action === 'reject') {
        $stmt = $db->prepare("UPDATE reviews SET status = 'rejected', updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $reviewId]);

        sync_product_rating($db, (int)$review['product_id']);
        log_audit($db, 'reject_review', 'reviews', $reviewId, ['status' => $review['status']], ['status' => 'rejected']);

        flash('success', 'Review rejected and removed from public view.');
        redirect(url('/admin/reviews.php'));

    } elseif ($action === 'respond') {
        $adminResp = trim($_POST['admin_response'] ?? '');
        $stmt = $db->prepare("UPDATE reviews SET admin_response = :resp, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['resp' => $adminResp ?: null, 'id' => $reviewId]);

        log_audit($db, 'respond_to_review', 'reviews', $reviewId, null, ['admin_response' => $adminResp]);
        flash('success', 'Administrative response updated.');
        redirect(url('/admin/reviews.php'));

    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM reviews WHERE id = :id");
        $stmt->execute(['id' => $reviewId]);

        sync_product_rating($db, (int)$review['product_id']);
        log_audit($db, 'delete_review', 'reviews', $reviewId);

        flash('success', 'Review deleted permanently.');
        redirect(url('/admin/reviews.php'));
    }
}

// Filters & Pagination
$status = trim($_GET['status'] ?? '');
$reported = isset($_GET['reported']) ? 1 : null;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["1=1"];
$params = [];

if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $where[] = "r.status = :status";
    $params['status'] = $status;
}

if ($reported === 1) {
    $where[] = "r.reported = 1";
}

if ($search !== '') {
    $where[] = "(r.title LIKE :search OR r.comment LIKE :search OR p.name LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
    $params['search'] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// Counts
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM reviews r 
    JOIN products p ON r.product_id = p.id 
    JOIN users u ON r.user_id = u.id 
    WHERE {$whereClause}
");
$countStmt->execute($params);
$totalReviews = (int)$countStmt->fetchColumn();

$pagination = paginate($totalReviews, $perPage, $page);

// Fetch Reviews
$query = "
    SELECT r.*, 
        p.name AS product_name, p.slug AS product_slug,
        u.first_name, u.last_name, u.email
    FROM reviews r
    JOIN products p ON r.product_id = p.id
    JOIN users u ON r.user_id = u.id
    WHERE {$whereClause}
    ORDER BY CASE WHEN r.status = 'pending' THEN 0 WHEN r.reported = 1 THEN 1 ELSE 2 END, r.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$reviews = $stmt->fetchAll();

// Overview Counts
$statPending = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn();
$statApproved = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE status = 'approved'")->fetchColumn();
$statReported = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE reported = 1")->fetchColumn();
$statTotal = (int)$db->query("SELECT COUNT(*) FROM reviews")->fetchColumn();

$page_title = 'Review Moderation';
$current_page = 'reviews';
require_once __DIR__ . '/header.php';
?>

<!-- Metrics Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-clock-history"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statPending); ?></h3>
                <p>Pending Moderation</p>
                <?php if ($statPending > 0): ?>
                    <div class="small text-warning fw-semibold"><i class="bi bi-bell"></i> Waiting for approval</div>
                <?php else: ?>
                    <div class="small text-ss-muted">Queue is clear</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-flag"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statReported); ?></h3>
                <p>Reported by Shoppers</p>
                <div class="small text-danger"><i class="bi bi-exclamation-triangle"></i> Flagged content</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-star-fill"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statApproved); ?></h3>
                <p>Approved Live Reviews</p>
                <div class="trend up"><i class="bi bi-check-circle"></i> Visible on products</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-chat-quote"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statTotal); ?></h3>
                <p>Total Reviews Submitted</p>
                <div class="small text-ss-muted">Platform lifetime</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/reviews.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search review text, product name, reviewer..." value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Review Statuses</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Approval (<?php echo $statPending; ?>)</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved (<?php echo $statApproved; ?>)</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check pt-2">
                    <input class="form-check-input" type="checkbox" name="reported" id="reportedCheck" value="1" <?php echo $reported === 1 ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="reportedCheck">Reported Only</label>
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <?php if ($search !== '' || $status !== '' || $reported !== null): ?>
                    <a href="<?php echo url('/admin/reviews.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Reviews Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Customer Product Reviews (<?php echo number_format($totalReviews); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($reviews)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-star fs-1 d-block mb-2"></i>
                No reviews found matching the selected filter criteria.
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product & Reviewer</th>
                            <th>Rating</th>
                            <th>Review Content</th>
                            <th>Admin Response</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $rv): ?>
                            <tr>
                                <td style="min-width: 200px;">
                                    <a href="<?php echo url('/product.php?slug=' . urlencode($rv['product_slug'])); ?>" target="_blank" class="fw-bold text-dark d-block">
                                        <?php echo e($rv['product_name']); ?>
                                    </a>
                                    <div class="small text-ss-muted">
                                        By <?php echo e($rv['first_name'] . ' ' . $rv['last_name']); ?> &bull; 
                                        <?php echo format_date($rv['created_at'], 'M d, Y'); ?>
                                    </div>
                                    <?php if (!empty($rv['is_verified_purchase'])): ?>
                                        <span class="badge bg-success text-white small" style="font-size: 10px;"><i class="bi bi-bag-check"></i> Verified Buyer</span>
                                    <?php endif; ?>
                                    <?php if (!empty($rv['reported'])): ?>
                                        <span class="badge bg-danger text-white small" style="font-size: 10px;"><i class="bi bi-flag-fill"></i> Flagged / Reported</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-warning">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="bi bi-star<?php echo $i <= $rv['rating'] ? '-fill' : ''; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="small fw-bold text-dark"><?php echo (int)$rv['rating']; ?> / 5</span>
                                </td>
                                <td style="max-width: 320px;">
                                    <?php if (!empty($rv['title'])): ?>
                                        <strong class="d-block text-dark"><?php echo e($rv['title']); ?></strong>
                                    <?php endif; ?>
                                    <p class="small mb-0 text-dark"><?php echo nl2br(e($rv['comment'] ?? '')); ?></p>
                                </td>
                                <td style="max-width: 250px;">
                                    <?php if (!empty($rv['admin_response'])): ?>
                                        <div class="p-2 bg-light rounded border small">
                                            <strong class="d-block text-primary"><i class="bi bi-shield-check"></i> Admin Reply:</strong>
                                            <?php echo truncate(e($rv['admin_response']), 100); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="small text-ss-muted">No response</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($rv['status']) {
                                        'approved' => 'success',
                                        'pending' => 'warning',
                                        'rejected' => 'danger',
                                        default => 'default'
                                    }; ?>">
                                        <?php echo ucfirst($rv['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <!-- Quick Approve -->
                                        <?php if ($rv['status'] !== 'approved'): ?>
                                            <form method="POST" action="<?php echo url('/admin/reviews.php'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="review_id" value="<?php echo (int)$rv['id']; ?>">
                                                <button type="submit" class="btn btn-outline-success btn-sm" title="Approve Review">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Quick Reject -->
                                        <?php if ($rv['status'] !== 'rejected'): ?>
                                            <form method="POST" action="<?php echo url('/admin/reviews.php'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="review_id" value="<?php echo (int)$rv['id']; ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm" title="Reject Review">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Reply Modal Trigger -->
                                        <button type="button" class="btn btn-light border btn-sm" data-bs-toggle="modal" data-bs-target="#respondModal<?php echo $rv['id']; ?>" title="Admin Response">
                                            <i class="bi bi-reply"></i>
                                        </button>

                                        <!-- Delete Review -->
                                        <form method="POST" action="<?php echo url('/admin/reviews.php'); ?>" onsubmit="return confirm('Permanently delete this review?');" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="review_id" value="<?php echo (int)$rv['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Review">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Respond Modal -->
                                    <div class="modal fade text-start" id="respondModal<?php echo $rv['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/reviews.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="respond">
                                                    <input type="hidden" name="review_id" value="<?php echo (int)$rv['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Admin Official Response</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="p-3 bg-light rounded border mb-3 small">
                                                            <strong>Customer Review:</strong>
                                                            <p class="mb-0 text-dark fst-italic">"<?php echo e($rv['comment'] ?? ''); ?>"</p>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Official Response (Visible publicly beneath the review)</label>
                                                            <textarea name="admin_response" class="form-control" rows="4" placeholder="Thank the customer or explain resolution..."><?php echo e($rv['admin_response'] ?? ''); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-ss-primary">Publish Response</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 pb-3">
                <?php echo render_admin_pagination($pagination, url('/admin/reviews.php'), ['search' => $search, 'status' => $status, 'reported' => $reported]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
