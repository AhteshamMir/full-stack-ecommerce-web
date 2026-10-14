<?php
/**
 * ShopSphere - Search Results Page
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/ProductService.php';

$query = trim($_GET['q'] ?? '');
$page_title = (!empty($query) ? 'Search: "' . e($query) . '"' : 'Search Products') . ' — ' . APP_NAME;
require_once __DIR__ . '/includes/header.php';

$productService = new ProductService();
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;

$filters = [
    'status' => 'approved',
    'search' => $query
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
            <li>Search Results</li>
        </ol>
    </nav>

    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">
            Search Results for: <span class="text-ss-accent">"<?php echo e($query); ?>"</span>
        </h1>
        <p class="text-ss-muted small">Found <?php echo $total; ?> matching products</p>
    </div>

    <?php if (empty($products)): ?>
        <div class="ss-card ss-empty-state py-5 text-center">
            <i class="bi bi-search fs-1 text-muted mb-2"></i>
            <h5>No products match your search</h5>
            <p class="text-ss-muted">Please check your spelling or search with different keywords.</p>
            <div class="d-flex justify-content-center gap-2 mt-3">
                <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary btn-sm">Browse All Products</a>
                <a href="<?php echo e(url('/deals.php')); ?>" class="btn btn-ss-outline btn-sm">View Hot Deals</a>
            </div>
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
                    <a href="<?php echo e(url('/search.php?q=' . urlencode($query) . '&page=' . $p)); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
