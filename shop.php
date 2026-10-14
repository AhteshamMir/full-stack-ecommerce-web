<?php
/**
 * ShopSphere - Master Product Catalog
 */
$page_title = 'Shop Products';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/classes/ProductService.php';

$productService = new ProductService();
$db = get_db();

// Filter parameters
$category_id = isset($_GET['category']) ? (int)$_GET['category'] : null;
$seller_id = isset($_GET['seller']) ? (int)$_GET['seller'] : null;
$search = trim($_GET['q'] ?? '');
$min_price = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;
$in_stock = !empty($_GET['in_stock']);
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;

$filters = [
    'status' => 'approved',
    'search' => $search,
    'category_id' => $category_id,
    'seller_id' => $seller_id,
    'min_price' => $min_price,
    'max_price' => $max_price,
    'in_stock' => $in_stock,
    'sort' => $sort
];

$result = $productService->getProducts($filters, $page, $per_page);
$products = $result['products'];
$total = $result['total'];
$total_pages = ceil($total / $per_page);

// Fetch categories for sidebar
$categories = $productService->getCategories();
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Shop All Products</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Filters -->
        <div class="col-lg-3">
            <div class="ss-card p-3 mb-4">
                <h5 class="mb-3 border-bottom pb-2">Filter Products</h5>
                <form action="<?php echo e(url('/shop.php')); ?>" method="GET" id="filterForm">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="q" value="<?php echo e($search); ?>">
                    <?php endif; ?>

                    <!-- Categories -->
                    <div class="ss-sidebar-filter">
                        <h6>Categories</h6>
                        <ul class="ss-filter-list">
                            <li>
                                <a href="<?php echo e(url('/shop.php')); ?>" class="<?php echo empty($category_id) ? 'active fw-bold' : ''; ?>">
                                    All Categories
                                </a>
                            </li>
                            <?php foreach ($categories as $cat): ?>
                                <li>
                                    <a href="<?php echo e(url('/shop.php?category=' . $cat['id'])); ?>" class="<?php echo $category_id === (int)$cat['id'] ? 'active fw-bold' : ''; ?>">
                                        <?php echo e($cat['name']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Price Range -->
                    <div class="ss-sidebar-filter">
                        <h6>Price Range (PKR)</h6>
                        <div class="ss-price-range mb-2">
                            <input type="number" name="min_price" class="ss-form-control py-1 px-2" placeholder="Min" value="<?php echo e($min_price ?? ''); ?>">
                            <span>-</span>
                            <input type="number" name="max_price" class="ss-form-control py-1 px-2" placeholder="Max" value="<?php echo e($max_price ?? ''); ?>">
                        </div>
                    </div>

                    <!-- In Stock Checkbox -->
                    <div class="ss-sidebar-filter">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="in_stock" value="1" id="inStockCheck" <?php echo $in_stock ? 'checked' : ''; ?>>
                            <label class="form-check-label small" for="inStockCheck">
                                In Stock Only
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100 btn-sm mt-3">
                        <i class="bi bi-funnel"></i> Apply Filters
                    </button>
                    <?php if (!empty($category_id) || !empty($min_price) || !empty($max_price) || !empty($in_stock) || !empty($search)): ?>
                        <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-outline w-100 btn-sm mt-2">Clear Filters</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Main Product Area -->
        <div class="col-lg-9">
            <!-- Header bar with count & sorting -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 p-3 bg-white border rounded">
                <div class="text-ss-muted small">
                    Showing <strong><?php echo $total > 0 ? (($page - 1) * $per_page + 1) : 0; ?>–<?php echo min($page * $per_page, $total); ?></strong> of <strong><?php echo $total; ?></strong> products
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label for="sortSelect" class="small text-ss-muted mb-0">Sort By:</label>
                    <select id="sortSelect" class="form-select form-select-sm" style="width: auto;" onchange="location = this.value;">
                        <option value="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['sort' => 'newest'])))); ?>" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest Arrivals</option>
                        <option value="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['sort' => 'price_low'])))); ?>" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['sort' => 'price_high'])))); ?>" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['sort' => 'popular'])))); ?>" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                        <option value="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['sort' => 'rating'])))); ?>" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                    </select>
                </div>
            </div>

            <?php if (empty($products)): ?>
                <div class="ss-card ss-empty-state py-5 text-center">
                    <i class="bi bi-box-seam" style="font-size: 3rem; color: #9ca3af;"></i>
                    <h5 class="mt-3">No products found</h5>
                    <p class="text-ss-muted">Try adjusting your filters or search keywords.</p>
                    <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary btn-sm">Reset Filters</a>
                </div>
            <?php else: ?>
                <div class="row g-3 row-cols-2 row-cols-md-3 row-cols-lg-3">
                    <?php foreach ($products as $prod): ?>
                        <div class="col">
                            <div class="ss-product-card h-100">
                                <div class="ss-product-card-img">
                                    <?php 
                                    $imgUrl = !empty($prod['image_path']) ? asset($prod['image_path']) : asset('images/misc/placeholder.svg');
                                    ?>
                                    <a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>">
                                        <img src="<?php echo e($imgUrl); ?>" alt="<?php echo e($prod['name']); ?>" loading="lazy">
                                    </a>
                                    <?php if (!empty($prod['compare_price']) && $prod['compare_price'] > $prod['price']): ?>
                                        <?php $discount_pct = round((($prod['compare_price'] - $prod['price']) / $prod['compare_price']) * 100); ?>
                                        <span class="ss-product-badge sale"><?php echo $discount_pct; ?>% OFF</span>
                                    <?php elseif (!empty($prod['is_featured'])): ?>
                                        <span class="ss-product-badge featured">Featured</span>
                                    <?php endif; ?>

                                    <div class="ss-product-actions">
                                        <button class="ss-product-action-btn ss-wishlist-toggle" data-product-id="<?php echo $prod['id']; ?>" title="Add to Wishlist">
                                            <i class="bi bi-heart"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="ss-product-card-body">
                                    <div class="ss-product-category">
                                        <?php echo e($prod['category_name'] ?? 'General'); ?>
                                    </div>
                                    <h6>
                                        <a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>">
                                            <?php echo e($prod['name']); ?>
                                        </a>
                                    </h6>
                                    <div class="ss-product-seller">
                                        Store: <a href="<?php echo e(url('/seller-store.php?id=' . $prod['seller_id'])); ?>"><?php echo e($prod['store_name'] ?? 'Verified Seller'); ?></a>
                                    </div>
                                    <div class="ss-product-rating">
                                        <?php 
                                        $rating = round($prod['rating_avg'] ?? 0);
                                        for ($i = 1; $i <= 5; $i++):
                                        ?>
                                            <i class="bi bi-star<?php echo $i <= $rating ? '-fill' : ''; ?>"></i>
                                        <?php endfor; ?>
                                        <span>(<?php echo (int)($prod['rating_count'] ?? 0); ?>)</span>
                                    </div>
                                    <div class="ss-product-price">
                                        <span class="current"><?php echo format_price($prod['price']); ?></span>
                                        <?php if (!empty($prod['compare_price']) && $prod['compare_price'] > $prod['price']): ?>
                                            <span class="original"><?php echo format_price($prod['compare_price']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ss-product-card-footer">
                                    <?php if ($prod['stock_quantity'] > 0): ?>
                                        <button class="btn btn-ss-primary btn-sm w-100 ss-add-to-cart" data-product-id="<?php echo $prod['id']; ?>">
                                            <i class="bi bi-cart-plus"></i> Add to Cart
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm w-100" disabled>
                                            Out of Stock
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <nav class="ss-pagination mt-4">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['page' => $page - 1])))); ?>" class="page-link"><i class="bi bi-chevron-left"></i></a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <a href="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['page' => $p])))); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo e(url('/shop.php?' . http_build_query(array_merge($_GET, ['page' => $page + 1])))); ?>" class="page-link"><i class="bi bi-chevron-right"></i></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
