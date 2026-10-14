<?php
/**
 * ShopSphere - Category Products Page
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/ProductService.php';

$productService = new ProductService();

$slug = trim($_GET['slug'] ?? '');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$category = null;
if (!empty($slug)) {
    $category = $productService->getCategoryBySlug($slug);
} elseif ($id > 0) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM categories WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$id]);
    $category = $stmt->fetch();
}

if (!$category) {
    redirect(url('/shop.php'));
}

$page_title = $category['name'] . ' — ' . APP_NAME;
require_once __DIR__ . '/includes/header.php';

// Pagination and sorting
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$sort = $_GET['sort'] ?? 'newest';

$filters = [
    'status' => 'approved',
    'category_id' => (int)$category['id'],
    'sort' => $sort
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
            <li><a href="<?php echo e(url('/shop.php')); ?>">Categories</a></li>
            <li><?php echo e($category['name']); ?></li>
        </ol>
    </nav>

    <!-- Category Header Banner -->
    <div class="p-4 mb-4 rounded bg-white border">
        <h1 class="h3 fw-bold mb-2"><?php echo e($category['name']); ?></h1>
        <?php if (!empty($category['description'])): ?>
            <p class="text-ss-muted mb-0"><?php echo e($category['description']); ?></p>
        <?php endif; ?>
    </div>

    <!-- Product Grid -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <span class="text-ss-muted small">Showing <?php echo count($products); ?> of <?php echo $total; ?> products</span>
        <div class="d-flex align-items-center gap-2">
            <span class="small text-ss-muted">Sort:</span>
            <select class="form-select form-select-sm" style="width: auto;" onchange="location = this.value;">
                <option value="<?php echo e(url('/category.php?slug=' . $category['slug'] . '&sort=newest')); ?>" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                <option value="<?php echo e(url('/category.php?slug=' . $category['slug'] . '&sort=price_low')); ?>" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                <option value="<?php echo e(url('/category.php?slug=' . $category['slug'] . '&sort=price_high')); ?>" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                <option value="<?php echo e(url('/category.php?slug=' . $category['slug'] . '&sort=popular')); ?>" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Popular</option>
            </select>
        </div>
    </div>

    <?php if (empty($products)): ?>
        <div class="ss-card ss-empty-state py-5 text-center">
            <i class="bi bi-tags fs-1 text-muted mb-2"></i>
            <h5>No products in this category yet</h5>
            <p class="text-ss-muted">Check back soon as verified vendors add new stock daily.</p>
            <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary btn-sm">Explore All Products</a>
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
                            <div class="ss-product-seller">
                                Store: <a href="<?php echo e(url('/seller-store.php?id=' . $prod['seller_id'])); ?>"><?php echo e($prod['store_name'] ?? 'Verified Seller'); ?></a>
                            </div>
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
                    <a href="<?php echo e(url('/category.php?slug=' . $category['slug'] . '&page=' . $p . '&sort=' . $sort)); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
