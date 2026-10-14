<?php
/**
 * ShopSphere - Product Oversight
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

// Handle Admin Actions (Approve, Reject, Toggle Featured/Trending, Archive)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        flash('error', 'Invalid product identifier.');
        redirect(url('/admin/products.php'));
    }

    $stmt = $db->prepare("SELECT p.*, sp.store_name FROM products p JOIN seller_profiles sp ON p.seller_id = sp.user_id WHERE p.id = :id");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        flash('error', 'Product not found.');
        redirect(url('/admin/products.php'));
    }

    if ($action === 'approve') {
        $stmt = $db->prepare("UPDATE products SET status = 'approved', rejection_reason = NULL, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $productId]);

        // Send notification to seller
        $notif = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (:user_id, 'stock', 'Product Approved', :msg, :link, NOW())
        ");
        $notif->execute([
            'user_id' => $product['seller_id'],
            'msg'     => "Your product \"{$product['name']}\" was approved and is now live on marketplace.",
            'link'    => '/seller/products.php'
        ]);

        log_audit($db, 'approve_product', 'products', $productId, ['status' => $product['status']], ['status' => 'approved']);
        flash('success', "Product \"{$product['name']}\" approved successfully.");
        redirect(url('/admin/products.php'));

    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? 'Product does not meet marketplace standards or listing guidelines.');
        $stmt = $db->prepare("UPDATE products SET status = 'rejected', rejection_reason = :reason, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['reason' => $reason, 'id' => $productId]);

        $notif = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (:user_id, 'stock', 'Product Rejected', :msg, :link, NOW())
        ");
        $notif->execute([
            'user_id' => $product['seller_id'],
            'msg'     => "Your product \"{$product['name']}\" was rejected: {$reason}",
            'link'    => '/seller/products.php'
        ]);

        log_audit($db, 'reject_product', 'products', $productId, ['status' => $product['status']], ['status' => 'rejected', 'reason' => $reason]);
        flash('success', "Product \"{$product['name']}\" rejected.");
        redirect(url('/admin/products.php'));

    } elseif ($action === 'toggle_featured') {
        $newFeatured = empty($product['is_featured']) ? 1 : 0;
        $db->prepare("UPDATE products SET is_featured = :f, updated_at = NOW() WHERE id = :id")->execute(['f' => $newFeatured, 'id' => $productId]);
        log_audit($db, 'toggle_featured_product', 'products', $productId, ['is_featured' => $product['is_featured']], ['is_featured' => $newFeatured]);
        flash('success', 'Product featured highlight updated.');
        redirect(url('/admin/products.php'));

    } elseif ($action === 'toggle_trending') {
        $newTrending = empty($product['is_trending']) ? 1 : 0;
        $db->prepare("UPDATE products SET is_trending = :t, updated_at = NOW() WHERE id = :id")->execute(['t' => $newTrending, 'id' => $productId]);
        log_audit($db, 'toggle_trending_product', 'products', $productId, ['is_trending' => $product['is_trending']], ['is_trending' => $newTrending]);
        flash('success', 'Product trending status updated.');
        redirect(url('/admin/products.php'));

    } elseif ($action === 'archive') {
        $db->prepare("UPDATE products SET status = 'archived', updated_at = NOW() WHERE id = :id")->execute(['id' => $productId]);
        log_audit($db, 'archive_product', 'products', $productId, ['status' => $product['status']], ['status' => 'archived']);
        flash('success', 'Product archived.');
        redirect(url('/admin/products.php'));
    }
}

// Search and Filter Options
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$sellerId = !empty($_GET['seller_id']) ? (int)$_GET['seller_id'] : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search)";
    $params['search'] = "%{$search}%";
}

if ($status !== '' && in_array($status, ['approved', 'pending', 'rejected', 'draft', 'archived'], true)) {
    $where[] = "p.status = :status";
    $params['status'] = $status;
}

if ($categoryId !== null && $categoryId > 0) {
    $where[] = "p.category_id = :category_id";
    $params['category_id'] = $categoryId;
}

if ($sellerId !== null && $sellerId > 0) {
    $where[] = "p.seller_id = :seller_id";
    $params['seller_id'] = $sellerId;
}

$whereClause = implode(' AND ', $where);

// Total Counts
$countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE {$whereClause}");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();

$pagination = paginate($totalProducts, $perPage, $page);

// Fetch Products
$query = "
    SELECT 
        p.*, 
        sp.store_name, sp.store_slug,
        c.name AS category_name,
        (SELECT pi.image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS primary_image
    FROM products p
    JOIN seller_profiles sp ON p.seller_id = sp.user_id
    JOIN categories c ON p.category_id = c.id
    WHERE {$whereClause}
    ORDER BY CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END, p.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// Filter Dropdown Lists
$categoriesList = $db->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();
$sellersList = $db->query("SELECT user_id, store_name FROM seller_profiles ORDER BY store_name ASC")->fetchAll();

// Status Counts
$statTotal = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$statPending = (int)$db->query("SELECT COUNT(*) FROM products WHERE status = 'pending'")->fetchColumn();
$statApproved = (int)$db->query("SELECT COUNT(*) FROM products WHERE status = 'approved'")->fetchColumn();
$statRejected = (int)$db->query("SELECT COUNT(*) FROM products WHERE status = 'rejected'")->fetchColumn();

$page_title = 'Product Catalog Oversight';
$current_page = 'products';
require_once __DIR__ . '/header.php';
?>

<!-- Quick Metrics -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-hourglass-split"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statPending); ?></h3>
                <p>Pending Product Approvals</p>
                <?php if ($statPending > 0): ?>
                    <div class="small text-warning fw-semibold"><i class="bi bi-bell"></i> Requires curation review</div>
                <?php else: ?>
                    <div class="small text-ss-muted">No pending items</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-check2-circle"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statApproved); ?></h3>
                <p>Live Approved Items</p>
                <div class="trend up"><i class="bi bi-shop"></i> Visible in store</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-x-octagon"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statRejected); ?></h3>
                <p>Rejected Items</p>
                <div class="small text-ss-muted">Declined submissions</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-box-seam"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statTotal); ?></h3>
                <p>Total Catalog Products</p>
                <div class="small text-ss-muted">Across all vendors</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Form -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/products.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search product title, SKU, barcode..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $statPending; ?>)</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved (<?php echo $statApproved; ?>)</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="category_id" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categoriesList as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                            <?php echo e($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="seller_id" class="form-select">
                    <option value="">All Sellers</option>
                    <?php foreach ($sellersList as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>" <?php echo $sellerId === (int)$s['user_id'] ? 'selected' : ''; ?>>
                            <?php echo e($s['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <?php if ($search !== '' || $status !== '' || $categoryId !== null || $sellerId !== null): ?>
                    <a href="<?php echo url('/admin/products.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Products List (<?php echo number_format($totalProducts); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($products)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                <p class="mb-0">No products match your search or filter filters.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Seller Store</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Badges</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $prod): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded border bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; overflow: hidden; flex-shrink: 0;">
                                            <?php if (!empty($prod['primary_image'])): ?>
                                                <img src="<?php echo asset($prod['primary_image']); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <i class="bi bi-image text-muted fs-4"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="<?php echo url('/product.php?slug=' . urlencode($prod['slug'])); ?>" target="_blank" class="fw-bold text-dark d-block">
                                                <?php echo e($prod['name']); ?>
                                            </a>
                                            <span class="small text-ss-muted">
                                                SKU: <code><?php echo e($prod['sku'] ?? 'N/A'); ?></code> &bull; 
                                                <i class="bi bi-star-fill text-warning"></i> <?php echo number_format((float)$prod['rating_avg'], 1); ?> (<?php echo (int)$prod['rating_count']; ?>)
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo url('/admin/seller-details.php?id=' . $prod['seller_id']); ?>" class="fw-semibold text-primary">
                                        <i class="bi bi-shop me-1"></i> <?php echo e($prod['store_name']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo e($prod['category_name']); ?></span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?php echo format_price($prod['price']); ?></strong>
                                    <?php if ($prod['compare_price'] && $prod['compare_price'] > $prod['price']): ?>
                                        <div class="small text-muted text-decoration-line-through"><?php echo format_price($prod['compare_price']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($prod['stock_quantity'] <= $prod['low_stock_threshold']): ?>
                                        <span class="badge bg-danger"><?php echo $prod['stock_quantity']; ?> left</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border"><?php echo $prod['stock_quantity']; ?> in stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <!-- Featured Toggle -->
                                        <form method="POST" action="<?php echo url('/admin/products.php'); ?>" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_featured">
                                            <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                            <button type="submit" class="badge <?php echo !empty($prod['is_featured']) ? 'bg-primary' : 'bg-light text-muted border'; ?> border-0 p-1" title="Toggle Featured">
                                                <i class="bi bi-lightning-fill"></i> Featured
                                            </button>
                                        </form>

                                        <!-- Trending Toggle -->
                                        <form method="POST" action="<?php echo url('/admin/products.php'); ?>" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_trending">
                                            <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                            <button type="submit" class="badge <?php echo !empty($prod['is_trending']) ? 'bg-warning text-dark' : 'bg-light text-muted border'; ?> border-0 p-1" title="Toggle Trending">
                                                <i class="bi bi-fire"></i> Trending
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($prod['status']) {
                                        'approved' => 'success',
                                        'pending' => 'warning',
                                        'rejected' => 'danger',
                                        'draft' => 'info',
                                        'archived' => 'default',
                                        default => 'default'
                                    }; ?>">
                                        <?php echo ucfirst($prod['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <?php if ($prod['status'] === 'pending'): ?>
                                            <!-- Quick Approve -->
                                            <form method="POST" action="<?php echo url('/admin/products.php'); ?>" class="d-inline" onsubmit="return confirm('Approve and publish this product?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" title="Approve Product">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                            </form>

                                            <!-- Reject Modal Trigger -->
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $prod['id']; ?>" title="Reject Product">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php else: ?>
                                            <?php if ($prod['status'] === 'approved'): ?>
                                                <a href="<?php echo url('/product.php?slug=' . urlencode($prod['slug'])); ?>" target="_blank" class="btn btn-light border btn-sm" title="View Storefront">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($prod['status'] !== 'approved'): ?>
                                                <form method="POST" action="<?php echo url('/admin/products.php'); ?>" class="d-inline">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm" title="Approve">
                                                        <i class="bi bi-check2"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($prod['status'] !== 'archived'): ?>
                                                <form method="POST" action="<?php echo url('/admin/products.php'); ?>" class="d-inline" onsubmit="return confirm('Archive this product?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="archive">
                                                    <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                                    <button type="submit" class="btn btn-light border btn-sm text-danger" title="Archive">
                                                        <i class="bi bi-archive"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Reject Product Modal -->
                                    <div class="modal fade text-start" id="rejectModal<?php echo $prod['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/products.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="product_id" value="<?php echo (int)$prod['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reject Product: <?php echo e($prod['name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Rejection Reason</label>
                                                            <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Specify why this product cannot be approved (e.g. prohibited item, missing specs, incorrect pricing)..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
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
                <?php echo render_admin_pagination($pagination, url('/admin/products.php'), [
                    'search' => $search, 
                    'status' => $status, 
                    'category_id' => $categoryId, 
                    'seller_id' => $sellerId
                ]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
