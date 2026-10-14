<?php
/**
 * ShopSphere - Public Seller Storefront
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/ProductService.php';

$seller_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug = trim($_GET['slug'] ?? '');

$db = get_db();
$seller = null;

if ($seller_id > 0) {
    $stmt = $db->prepare("SELECT sp.*, u.first_name, u.last_name, u.created_at as member_since 
                          FROM seller_profiles sp 
                          JOIN users u ON sp.user_id = u.id 
                          WHERE sp.user_id = ? AND sp.status = 'approved' LIMIT 1");
    $stmt->execute([$seller_id]);
    $seller = $stmt->fetch();
} elseif (!empty($slug)) {
    $stmt = $db->prepare("SELECT sp.*, u.first_name, u.last_name, u.created_at as member_since 
                          FROM seller_profiles sp 
                          JOIN users u ON sp.user_id = u.id 
                          WHERE sp.store_slug = ? AND sp.status = 'approved' LIMIT 1");
    $stmt->execute([$slug]);
    $seller = $stmt->fetch();
}

if (!$seller) {
    redirect(url('/shop.php'));
}

$page_title = $seller['store_name'] . ' — Verified Seller Store';
require_once __DIR__ . '/includes/header.php';

$productService = new ProductService();
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;

$filters = [
    'status' => 'approved',
    'seller_id' => (int)$seller['user_id']
];

$result = $productService->getProducts($filters, $page, $per_page);
$products = $result['products'];
$total = $result['total'];
$total_pages = ceil($total / $per_page);
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li><a href="<?php echo e(url('/shop.php')); ?>">Sellers</a></li>
            <li><?php echo e($seller['store_name']); ?></li>
        </ol>
    </nav>

    <!-- Store Hero Header Banner -->
    <div class="ss-card mb-4 overflow-hidden border">
        <div style="height: 180px; background: linear-gradient(135deg, #1e2a4a 0%, #2d3f6b 100%);">
            <?php if (!empty($seller['store_banner'])): ?>
                <img src="<?php echo e(asset($seller['store_banner'])); ?>" alt="Banner" style="width: 100%; height: 100%; object-fit: cover;">
            <?php endif; ?>
        </div>
        <div class="p-4 bg-white d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle border p-1 bg-white shadow-sm" style="width: 80px; height: 80px; margin-top: -50px; overflow: hidden; background: #fff;">
                    <?php if (!empty($seller['store_logo'])): ?>
                        <img src="<?php echo e(asset($seller['store_logo'])); ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                    <?php else: ?>
                        <div class="w-100 h-100 bg-light d-flex align-items-center justify-content-center text-primary fs-3 fw-bold">
                            <?php echo strtoupper(substr($seller['store_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="h4 mb-1 fw-bold"><?php echo e($seller['store_name']); ?></h1>
                    <div class="d-flex flex-wrap align-items-center gap-2 small text-ss-muted">
                        <span class="badge bg-success py-1 px-2"><i class="bi bi-patch-check"></i> Verified Merchant</span>
                        <span><i class="bi bi-box-seam"></i> <?php echo $total; ?> Active Products</span>
                        <span><i class="bi bi-calendar3"></i> Joined <?php echo format_date($seller['member_since'], 'M Y'); ?></span>
                    </div>
                </div>
            </div>
            <div>
                <a href="<?php echo e(url('/contact.php?subject=' . urlencode('Inquiry for Store: ' . $seller['store_name']))); ?>" class="btn btn-ss-outline btn-sm">
                    <i class="bi bi-chat-text"></i> Contact Merchant
                </a>
            </div>
        </div>
        <?php if (!empty($seller['store_description'])): ?>
            <div class="px-4 pb-3 bg-white text-ss-muted small">
                <?php echo nl2br(e($seller['store_description'])); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Store Products Catalog -->
    <h3 class="h5 fw-bold mb-3">Products from this Store</h3>

    <?php if (empty($products)): ?>
        <div class="ss-card ss-empty-state py-5 text-center">
            <i class="bi bi-inbox fs-1 text-muted mb-2"></i>
            <h5>This store has no active products listed yet</h5>
            <p class="text-ss-muted">Explore other certified merchants on ShopSphere.</p>
            <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary btn-sm">Browse Marketplace</a>
        </div>
    <?php else: ?>
        <div class="row g-3 row-cols-2 row-cols-md-3 row-cols-lg-4">
            <?php foreach ($products as $prod): ?>
                <div class="col">
                    <div class="ss-product-card h-100">
                        <div class="ss-product-card-img">
                            <a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>">
                                <img src="<?php echo e(!empty($prod['image_path']) ? asset($prod['image_path']) : asset('images/misc/placeholder.svg')); ?>" alt="<?php echo e($prod['name']); ?>">
                            </a>
                            <div class="ss-product-actions">
                                <button class="ss-product-action-btn ss-wishlist-toggle" data-product-id="<?php echo $prod['id']; ?>">
                                    <i class="bi bi-heart"></i>
                                </button>
                            </div>
                        </div>
                        <div class="ss-product-card-body">
                            <h6><a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>"><?php echo e($prod['name']); ?></a></h6>
                            <div class="ss-product-price">
                                <span class="current"><?php echo format_price($prod['price']); ?></span>
                                <?php if (!empty($prod['compare_price']) && $prod['compare_price'] > $prod['price']): ?>
                                    <span class="original"><?php echo format_price($prod['compare_price']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ss-product-card-footer">
                            <button class="btn btn-ss-primary btn-sm w-100 ss-add-to-cart" data-product-id="<?php echo $prod['id']; ?>">
                                <i class="bi bi-cart-plus"></i> Add to Cart
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav class="ss-pagination mt-4">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="<?php echo e(url('/seller-store.php?id=' . $seller['user_id'] . '&page=' . $p)); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
