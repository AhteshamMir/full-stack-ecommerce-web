<?php
/**
 * ShopSphere - Homepage
 */
$page_title = 'ShopSphere — Premier Multi-Vendor Online Marketplace';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/classes/ProductService.php';

$productService = new ProductService();
$db = get_db();

// Hero Banners
$hero_banners = [];
try {
    $stmt = $db->query("SELECT * FROM banners WHERE is_active = 1 AND position = 'hero' ORDER BY sort_order ASC LIMIT 3");
    $hero_banners = $stmt->fetchAll();
} catch (Exception $e) {}

// Featured Categories
$categories = $productService->getCategories();
$featured_categories = array_slice($categories, 0, 8);

// Curation sections
$featured_products = $productService->getFeaturedProducts(8);
$trending_products = $productService->getTrendingProducts(8);
$deals = $productService->getDealProducts(4);

// Top Verified Sellers
$sellers = [];
try {
    $stmt = $db->query("SELECT sp.*, u.first_name, u.last_name FROM seller_profiles sp 
                        JOIN users u ON sp.user_id = u.id 
                        WHERE sp.status = 'approved' 
                        ORDER BY sp.total_sales DESC, sp.rating_avg DESC LIMIT 4");
    $sellers = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="container py-3">
    <!-- Hero Slider / Banner Section -->
    <div class="ss-hero mb-4">
        <div class="ss-hero-slide" style="background-image: url('<?php echo asset('images/banners/hero-default.jpg'); ?>');">
            <div class="ss-hero-overlay"></div>
            <div class="ss-hero-content">
                <span class="badge bg-primary-subtle text-primary mb-2 px-3 py-2 text-uppercase fw-bold" style="letter-spacing: 1px;">Pakistan's Trusted Marketplace</span>
                <h1>Discover Millions of Products from Verified Sellers</h1>
                <p>Enjoy guaranteed authentic items, seamless Cash on Delivery, and hassle-free returns right to your doorstep.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary">
                        <i class="bi bi-bag"></i> Start Shopping
                    </a>
                    <a href="<?php echo e(url('/deals.php')); ?>" class="btn btn-ss-outline bg-white">
                        <i class="bi bi-lightning-charge"></i> Hot Deals
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Trust Badges -->
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-3">
            <div class="ss-stat-card border h-100">
                <div class="ss-stat-icon blue"><i class="bi bi-truck"></i></div>
                <div class="ss-stat-info">
                    <h6 class="fw-bold mb-1">Fast Delivery</h6>
                    <p class="text-ss-muted small">Nationwide shipping with rider tracking</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ss-stat-card border h-100">
                <div class="ss-stat-icon green"><i class="bi bi-shield-check"></i></div>
                <div class="ss-stat-info">
                    <h6 class="fw-bold mb-1">100% Secure</h6>
                    <p class="text-ss-muted small">Safe Cash on Delivery & buyer protection</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ss-stat-card border h-100">
                <div class="ss-stat-icon yellow"><i class="bi bi-patch-check"></i></div>
                <div class="ss-stat-info">
                    <h6 class="fw-bold mb-1">Verified Sellers</h6>
                    <p class="text-ss-muted small">Vetted quality merchants and genuine items</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="ss-stat-card border h-100">
                <div class="ss-stat-icon red"><i class="bi bi-arrow-repeat"></i></div>
                <div class="ss-stat-info">
                    <h6 class="fw-bold mb-1">7-Day Easy Returns</h6>
                    <p class="text-ss-muted small">Hassle-free refunds if not satisfied</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured Categories -->
    <div class="ss-section pt-0">
        <div class="ss-section-header">
            <h2>Explore Top Categories</h2>
            <a href="<?php echo e(url('/shop.php')); ?>" class="ss-view-all">All Categories <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="row g-3 row-cols-2 row-cols-md-4 row-cols-lg-8">
            <?php foreach ($featured_categories as $cat): ?>
                <div class="col">
                    <a href="<?php echo e(url('/category.php?slug=' . $cat['slug'])); ?>" class="ss-category-card">
                        <div class="ss-category-card-img">
                            <i class="bi <?php echo !empty($cat['icon']) ? e($cat['icon']) : 'bi-bag-check'; ?>"></i>
                        </div>
                        <h6><?php echo e($cat['name']); ?></h6>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Flash Deals Section -->
    <?php if (!empty($deals)): ?>
        <div class="ss-section">
            <div class="ss-section-header">
                <div class="d-flex align-items-center gap-3">
                    <h2><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Flash Deals</h2>
                    <span class="badge bg-danger">Limited Time</span>
                </div>
                <a href="<?php echo e(url('/deals.php')); ?>" class="ss-view-all">View All Deals <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="row g-3 row-cols-2 row-cols-md-4">
                <?php foreach ($deals as $prod): ?>
                    <?php $discount_pct = round((($prod['compare_price'] - $prod['price']) / $prod['compare_price']) * 100); ?>
                    <div class="col">
                        <div class="ss-product-card h-100">
                            <div class="ss-product-card-img">
                                <a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>">
                                    <img src="<?php echo e(!empty($prod['image_path']) ? asset($prod['image_path']) : asset('images/misc/placeholder.svg')); ?>" alt="<?php echo e($prod['name']); ?>">
                                </a>
                                <span class="ss-product-badge sale"><?php echo $discount_pct; ?>% OFF</span>
                                <div class="ss-product-actions">
                                    <button class="ss-product-action-btn ss-wishlist-toggle" data-product-id="<?php echo $prod['id']; ?>">
                                        <i class="bi bi-heart"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="ss-product-card-body">
                                <h6><a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>"><?php echo e($prod['name']); ?></a></h6>
                                <div class="ss-product-price">
                                    <span class="current text-danger"><?php echo format_price($prod['price']); ?></span>
                                    <span class="original"><?php echo format_price($prod['compare_price']); ?></span>
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
        </div>
    <?php endif; ?>

    <!-- Featured Products -->
    <div class="ss-section">
        <div class="ss-section-header">
            <h2>Featured Products</h2>
            <a href="<?php echo e(url('/shop.php')); ?>" class="ss-view-all">Shop All <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="row g-3 row-cols-2 row-cols-md-4">
            <?php foreach ($featured_products as $prod): ?>
                <div class="col">
                    <div class="ss-product-card h-100">
                        <div class="ss-product-card-img">
                            <a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>">
                                <img src="<?php echo e(!empty($prod['image_path']) ? asset($prod['image_path']) : asset('images/misc/placeholder.svg')); ?>" alt="<?php echo e($prod['name']); ?>">
                            </a>
                            <span class="ss-product-badge featured">Featured</span>
                            <div class="ss-product-actions">
                                <button class="ss-product-action-btn ss-wishlist-toggle" data-product-id="<?php echo $prod['id']; ?>">
                                    <i class="bi bi-heart"></i>
                                </button>
                            </div>
                        </div>
                        <div class="ss-product-card-body">
                            <div class="ss-product-category"><?php echo e($prod['category_name'] ?? 'General'); ?></div>
                            <h6><a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>"><?php echo e($prod['name']); ?></a></h6>
                            <div class="ss-product-seller">
                                Store: <a href="<?php echo e(url('/seller-store.php?id=' . $prod['seller_id'])); ?>"><?php echo e($prod['store_name'] ?? 'Verified Seller'); ?></a>
                            </div>
                            <div class="ss-product-price">
                                <span class="current"><?php echo format_price($prod['price']); ?></span>
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
    </div>

    <!-- Verified Seller Highlights -->
    <?php if (!empty($sellers)): ?>
        <div class="ss-section">
            <div class="ss-section-header">
                <h2>Featured Verified Stores</h2>
                <a href="<?php echo e(url('/seller/register.php')); ?>" class="ss-view-all">Become a Seller <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="row g-3 row-cols-1 row-cols-md-2 row-cols-lg-4">
                <?php foreach ($sellers as $s): ?>
                    <div class="col">
                        <div class="ss-card h-100 p-3 text-center border">
                            <div class="mx-auto rounded-circle bg-light border d-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; overflow: hidden;">
                                <?php if (!empty($s['store_logo'])): ?>
                                    <img src="<?php echo e(asset($s['store_logo'])); ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                                <?php else: ?>
                                    <span class="fs-4 fw-bold text-primary"><?php echo strtoupper(substr($s['store_name'], 0, 1)); ?></span>
                                <?php endif; ?>
                            </div>
                            <h6 class="fw-bold mb-1"><?php echo e($s['store_name']); ?></h6>
                            <span class="badge bg-success-subtle text-success border border-success-subtle mb-2">Verified Merchant</span>
                            <p class="text-ss-muted small mb-3"><?php echo e(truncate($s['store_description'] ?? 'Quality store with verified authentic catalog products.', 60)); ?></p>
                            <a href="<?php echo e(url('/seller-store.php?id=' . $s['user_id'])); ?>" class="btn btn-ss-outline btn-sm w-100">
                                Visit Store
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Trending Products -->
    <div class="ss-section">
        <div class="ss-section-header">
            <h2>Trending on ShopSphere</h2>
            <a href="<?php echo e(url('/shop.php?sort=popular')); ?>" class="ss-view-all">Explore Trending <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="row g-3 row-cols-2 row-cols-md-4">
            <?php foreach ($trending_products as $prod): ?>
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
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
