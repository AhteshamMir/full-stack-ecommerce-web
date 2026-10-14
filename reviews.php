<?php
/**
 * ShopSphere - Customer Product Reviews History
 *
 * Allows authenticated customers to review all ratings and feedback they have
 * submitted across the marketplace, inspect official merchant responses, and manage reviews.
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
require_once __DIR__ . '/classes/ReviewService.php';

// Enforce customer login
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();

// Ensure reviews table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        user_id INT NOT NULL,
        order_item_id INT NULL,
        rating INT NOT NULL DEFAULT 5,
        title VARCHAR(255) NULL,
        comment TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'approved',
        is_verified_purchase TINYINT(1) NOT NULL DEFAULT 1,
        admin_response TEXT NULL,
        reported TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('[ShopSphere Reviews Table Check] ' . $e->getMessage());
}

// Handle POST: Delete Review
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        flash('error', 'Session expired. Please try again.');
        redirect(url('reviews.php'));
    }

    $action   = sanitize_string($_POST['action'] ?? '');
    $reviewId = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);

    if ($action === 'delete' && $reviewId) {
        try {
            // Verify ownership and get product ID for rating re-sync
            $stmtCheck = $pdo->prepare('SELECT product_id FROM reviews WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmtCheck->execute([':id' => $reviewId, ':uid' => $userId]);
            $productId = $stmtCheck->fetchColumn();

            if ($productId !== false) {
                $delStmt = $pdo->prepare('DELETE FROM reviews WHERE id = :id AND user_id = :uid');
                $delStmt->execute([':id' => $reviewId, ':uid' => $userId]);

                // Re-sync rolling product rating
                try {
                    $reviewService = new ReviewService($pdo);
                    $reviewService->updateProductRating((int) $productId);
                } catch (Throwable $e) {
                    // Non-critical rating sync exception
                }

                flash('success', 'Your review has been deleted.');
            } else {
                flash('error', 'Review not found or permission denied.');
            }
        } catch (Throwable $e) {
            error_log('[ShopSphere Review Delete Error] ' . $e->getMessage());
            flash('error', 'Failed to delete review.');
        }
        redirect(url('reviews.php'));
    }
}

// Total reviews count
$totalReviews = 0;
try {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE user_id = :uid');
    $countStmt->execute([':uid' => $userId]);
    $totalReviews = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('[ShopSphere Reviews Count Error] ' . $e->getMessage());
}

// Pagination setup
$currentPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$perPage = 10;
$pagination = paginate($totalReviews, $perPage, $currentPage);

// Fetch reviews for current customer
$reviews = [];
try {
    $sql = "SELECT 
        r.*,
        p.name AS product_name,
        p.slug AS product_slug,
        p.price,
        (SELECT pi.image_path 
         FROM product_images pi 
         WHERE pi.product_id = p.id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC 
         LIMIT 1) AS product_image
    FROM reviews r
    JOIN products p ON p.id = r.product_id
    WHERE r.user_id = :uid
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Reviews Fetch Error] ' . $e->getMessage());
    $reviews = [];
}

$page_title = 'My Reviews';
$active_page = 'reviews';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li>My Reviews</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Reviews Content -->
        <div class="col-lg-9 col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-1">
                        <i class="bi bi-star-fill text-warning me-2"></i> My Ratings & Reviews
                        <span class="badge bg-light text-muted border fs-6 ms-2"><?= $totalReviews ?> Reviews</span>
                    </h4>
                    <p class="text-muted small mb-0">Help other shoppers by sharing honest opinions and verified product feedback.</p>
                </div>
            </div>

            <!-- Reviews List Card -->
            <?php if (empty($reviews)): ?>
                <div class="ss-card shadow-sm">
                    <div class="ss-empty-state py-5">
                        <i class="bi bi-star text-muted opacity-50 display-3 mb-3 d-block"></i>
                        <h5>No Reviews Written Yet</h5>
                        <p class="text-muted small mb-4">
                            You haven't submitted any reviews yet. Complete purchases and share your experience with the community!
                        </p>
                        <a href="<?= url('orders.php') ?>" class="btn btn-ss-primary px-4">
                            <i class="bi bi-bag-check me-1"></i> View Delivered Orders
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($reviews as $rev): ?>
                        <?php
                        $rating = (int) $rev['rating'];
                        $statusClass = match (strtolower((string) $rev['status'])) {
                            'approved' => 'ss-badge-success',
                            'rejected' => 'ss-badge-danger',
                            default    => 'ss-badge-warning',
                        };
                        ?>
                        <div class="ss-card shadow-sm">
                            <div class="ss-card-body p-4">
                                <div class="d-flex flex-column flex-sm-row gap-3">
                                    <!-- Product Thumbnail -->
                                    <div class="ss-cart-item-img flex-shrink-0" style="width: 75px; height: 75px;">
                                        <?php if (!empty($rev['product_image'])): ?>
                                            <img src="<?= e(asset($rev['product_image'])) ?>" alt="<?= e($rev['product_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="ss-img-placeholder" style="font-size: 1.5rem;">
                                                <i class="bi bi-box"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Review Content -->
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                                            <div>
                                                <h6 class="fw-bold mb-1">
                                                    <a href="<?= url('product.php?slug=' . urlencode((string) $rev['product_slug'])) ?>" class="text-dark text-decoration-none">
                                                        <?= e($rev['product_name']) ?>
                                                    </a>
                                                </h6>
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    <!-- Star Rating -->
                                                    <div class="text-warning" style="font-size: 0.875rem;">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <span class="fw-bold small text-dark"><?= $rating ?>.0 / 5.0</span>
                                                    <?php if (!empty($rev['is_verified_purchase'])): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.65rem;">
                                                            <i class="bi bi-patch-check-fill me-1"></i> Verified Purchase
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="text-sm-end">
                                                <span class="ss-badge <?= $statusClass ?> mb-1">
                                                    <?= e(ucfirst((string) $rev['status'])) ?>
                                                </span>
                                                <small class="text-muted d-block" style="font-size: 0.72rem;">
                                                    <?= e(format_date($rev['created_at'], 'M d, Y')) ?>
                                                </small>
                                            </div>
                                        </div>

                                        <!-- Review Title -->
                                        <?php if (!empty($rev['title'])): ?>
                                            <h6 class="fw-bold text-dark mb-1" style="font-size: 0.925rem;">
                                                <?= e($rev['title']) ?>
                                            </h6>
                                        <?php endif; ?>

                                        <!-- Review Comment -->
                                        <?php if (!empty($rev['comment'])): ?>
                                            <p class="text-muted small mb-2" style="line-height: 1.6;">
                                                <?= nl2br(e($rev['comment'])) ?>
                                            </p>
                                        <?php endif; ?>

                                        <!-- Admin / Merchant Response -->
                                        <?php if (!empty($rev['admin_response'])): ?>
                                            <div class="p-3 bg-light rounded border mt-3 ms-2">
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <span class="badge bg-primary" style="font-size: 0.65rem;">Seller Response</span>
                                                    <small class="text-muted fw-semibold">Official Reply</small>
                                                </div>
                                                <p class="text-dark small mb-0 fst-italic">
                                                    "<?= e($rev['admin_response']) ?>"
                                                </p>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Review Footer Action Bar -->
                                        <div class="border-top pt-2 mt-3 d-flex justify-content-between align-items-center">
                                            <a href="<?= url('product.php?slug=' . urlencode((string) $rev['product_slug'])) ?>" class="btn btn-sm btn-link text-decoration-none p-0 text-ss-accent small">
                                                <i class="bi bi-box-arrow-up-right me-1"></i> View Product Page
                                            </a>

                                            <form action="<?= url('reviews.php') ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this review?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="review_id" value="<?= $rev['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Review">
                                                    <i class="bi bi-trash me-1"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination Controls -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="mt-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <small class="text-muted">
                            Showing <?= $pagination['from'] ?> to <?= $pagination['to'] ?> of <?= $pagination['total'] ?> reviews
                        </small>
                        <nav aria-label="Reviews pagination">
                            <ul class="ss-pagination mb-0 mt-0">
                                <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $pagination['has_prev'] ? url('reviews.php?page=' . $pagination['prev_page']) : '#' ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                                    <li class="page-item">
                                        <a class="page-link <?= $p === $currentPage ? 'active' : '' ?>" href="<?= url('reviews.php?page=' . $p) ?>">
                                            <?= $p ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $pagination['has_next'] ? url('reviews.php?page=' . $pagination['next_page']) : '#' ?>">
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
