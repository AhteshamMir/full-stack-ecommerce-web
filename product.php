<?php
/**
 * ShopSphere - Product Detail Page
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/ProductService.php';
require_once __DIR__ . '/classes/ReviewService.php';

$productService = new ProductService();
$reviewService = new ReviewService();

$slug = trim($_GET['slug'] ?? '');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$product = null;
if (!empty($slug)) {
    $product = $productService->getProductBySlug($slug);
} elseif ($id > 0) {
    $product = $productService->getProductById($id);
}

if (!$product || $product['status'] !== 'approved') {
    http_response_code(404);
    require_once __DIR__ . '/404.php';
    exit;
}

// Increment view count
$productService->incrementViews((int)$product['id']);

$page_title = $product['name'] . ' — ' . APP_NAME;
require_once __DIR__ . '/includes/header.php';

$images = $productService->getProductImages((int)$product['id']);
$variants = $productService->getProductVariants((int)$product['id']);
$related_products = $productService->getRelatedProducts((int)$product['id'], 4);
$reviews_data = $reviewService->getProductReviews((int)$product['id'], 1, 10);
$reviews = $reviews_data['reviews'];
$reviews_total = $reviews_data['total'];

$current_user_id = get_current_user_id();
$can_review = $current_user_id ? $reviewService->canReview($current_user_id, (int)$product['id']) : false;

$primary_image = !empty($images[0]['image_path']) 
    ? asset($images[0]['image_path']) 
    : (!empty($product['image_path']) ? asset($product['image_path']) : asset('images/misc/placeholder.svg'));
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li><a href="<?php echo e(url('/shop.php')); ?>">Shop</a></li>
            <?php if (!empty($product['category_name'])): ?>
                <li><a href="<?php echo e(url('/category.php?slug=' . ($product['category_slug'] ?? ''))); ?>"><?php echo e($product['category_name']); ?></a></li>
            <?php endif; ?>
            <li><?php echo e(truncate($product['name'], 30)); ?></li>
        </ol>
    </nav>

    <div class="row g-4 mb-5">
        <!-- Image Gallery -->
        <div class="col-lg-5">
            <div class="ss-product-gallery">
                <div class="ss-product-main-image">
                    <img id="mainProductImg" src="<?php echo e($primary_image); ?>" alt="<?php echo e($product['name']); ?>">
                </div>
                <?php if (!empty($images) && count($images) > 1): ?>
                    <div class="ss-product-thumbnails">
                        <?php foreach ($images as $idx => $img): ?>
                            <div class="ss-product-thumbnail <?php echo $idx === 0 ? 'active' : ''; ?>" data-image="<?php echo e(asset($img['image_path'])); ?>">
                                <img src="<?php echo e(asset($img['image_path'])); ?>" alt="<?php echo e($img['alt_text'] ?? $product['name']); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Product Summary & Purchase Options -->
        <div class="col-lg-7">
            <div class="ss-product-info">
                <h1 class="h2 mb-2"><?php echo e($product['name']); ?></h1>

                <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                    <div class="ss-product-rating">
                        <?php 
                        $rating = round($product['rating_avg'] ?? 0);
                        for ($i = 1; $i <= 5; $i++):
                        ?>
                            <i class="bi bi-star<?php echo $i <= $rating ? '-fill' : ''; ?>"></i>
                        <?php endfor; ?>
                        <span class="ms-1 fw-bold"><?php echo number_format($product['rating_avg'] ?? 0, 1); ?></span>
                        <a href="#reviews-section" class="text-ss-muted small">(<?php echo (int)($product['rating_count'] ?? 0); ?> reviews)</a>
                    </div>
                    <span class="text-ss-muted">|</span>
                    <span class="small text-ss-muted">SKU: <strong><?php echo e($product['sku'] ?? 'N/A'); ?></strong></span>
                </div>

                <div class="price-section mb-3">
                    <span class="current-price ss-base-price" data-price="<?php echo (float)$product['price']; ?>"><?php echo format_price($product['price']); ?></span>
                    <?php if (!empty($product['compare_price']) && $product['compare_price'] > $product['price']): ?>
                        <span class="original-price"><?php echo format_price($product['compare_price']); ?></span>
                        <?php $discount_pct = round((($product['compare_price'] - $product['price']) / $product['compare_price']) * 100); ?>
                        <span class="discount-badge"><?php echo (int)$discount_pct; ?>% OFF</span>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <?php if ($product['stock_quantity'] > 5): ?>
                        <span class="ss-stock-status in-stock"><i class="bi bi-check-circle"></i> In Stock (<?php echo (int)$product['stock_quantity']; ?> units)</span>
                    <?php elseif ($product['stock_quantity'] > 0): ?>
                        <span class="ss-stock-status low-stock"><i class="bi bi-exclamation-circle"></i> Only <?php echo (int)$product['stock_quantity']; ?> units left</span>
                    <?php else: ?>
                        <span class="ss-stock-status out-of-stock"><i class="bi bi-x-circle"></i> Out of Stock</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($product['short_description'])): ?>
                    <p class="text-ss-muted mb-4"><?php echo nl2br(e($product['short_description'])); ?></p>
                <?php endif; ?>

                <!-- Product Form -->
                <form class="ss-product-actions-form">
                    <?php if (!empty($variants)): ?>
                        <div class="ss-variant-group mb-4">
                            <label>Choose Option / Variant:</label>
                            <div class="ss-variant-options">
                                <?php foreach ($variants as $idx => $v): ?>
                                    <button type="button"
                                            class="ss-variant-btn <?php echo $idx === 0 ? 'active' : ''; ?>"
                                            data-variant-id="<?php echo (int)$v['id']; ?>"
                                            data-price-adjustment="<?php echo (float)$v['price_adjustment']; ?>"
                                            data-stock="<?php echo (int)$v['stock_quantity']; ?>">
                                        <?php echo e($v['name'] . ': ' . $v['value']); ?>
                                        <?php if ($v['price_adjustment'] > 0): ?>
                                            (+<?php echo format_price($v['price_adjustment']); ?>)
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="variant_id" value="<?php echo (int)($variants[0]['id'] ?? ''); ?>">
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="variant_id" value="">
                    <?php endif; ?>

                    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
                        <div class="ss-quantity-control">
                            <button type="button" class="ss-qty-minus"><i class="bi bi-dash"></i></button>
                            <input type="number" class="ss-qty-input" name="quantity" value="1" min="1" max="<?php echo max(1, (int)$product['stock_quantity']); ?>" readonly>
                            <button type="button" class="ss-qty-plus"><i class="bi bi-plus"></i></button>
                        </div>

                        <?php if ($product['stock_quantity'] > 0): ?>
                            <button type="button" class="btn btn-ss-primary px-4 py-2 ss-add-to-cart" data-product-id="<?php echo (int)$product['id']; ?>">
                                <i class="bi bi-cart-plus me-1"></i> Add to Cart
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary px-4 py-2" disabled>
                                Currently Unavailable
                            </button>
                        <?php endif; ?>

                        <button type="button" class="btn btn-ss-outline px-3 py-2 ss-wishlist-toggle" data-product-id="<?php echo (int)$product['id']; ?>" title="Add to Wishlist">
                            <i class="bi bi-heart me-1"></i> Wishlist
                        </button>
                    </div>
                </form>

                <!-- Seller Info Card -->
                <div class="ss-seller-info-box d-flex align-items-center justify-content-between p-3 rounded border bg-light">
                    <div class="d-flex align-items-center gap-3">
                        <div class="ss-stat-icon blue mb-0" style="width: 42px; height: 42px;">
                            <i class="bi bi-shop"></i>
                        </div>
                        <div>
                            <div class="small text-ss-muted">Sold by</div>
                            <h6 class="mb-0 fw-bold">
                                <a href="<?php echo e(url('/seller-store.php?id=' . $product['seller_id'])); ?>">
                                    <?php echo e($product['store_name'] ?? 'Verified Merchant'); ?>
                                </a>
                            </h6>
                            <span class="badge bg-success py-1 px-2" style="font-size: 0.65rem;">Verified Seller</span>
                        </div>
                    </div>
                    <a href="<?php echo e(url('/seller-store.php?id=' . $product['seller_id'])); ?>" class="btn btn-ss-outline btn-sm">
                        Visit Store
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Description & Specifications -->
    <div class="row g-4 mb-5">
        <div class="col-12">
            <div class="ss-card">
                <div class="ss-card-header bg-white">
                    <ul class="nav nav-tabs card-header-tabs" id="productTab" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active fw-bold" id="desc-tab" data-bs-toggle="tab" data-bs-target="#desc" type="button">Product Description</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link fw-bold" id="specs-tab" data-bs-toggle="tab" data-bs-target="#specs" type="button">Specifications</button>
                        </li>
                    </ul>
                </div>
                <div class="ss-card-body p-4 tab-content">
                    <div class="tab-pane fade show active" id="desc">
                        <?php if (!empty($product['description'])): ?>
                            <div style="line-height: 1.8;"><?php echo nl2br(e($product['description'])); ?></div>
                        <?php else: ?>
                            <p class="text-ss-muted mb-0">No detailed description available for this product.</p>
                        <?php endif; ?>
                    </div>
                    <div class="tab-pane fade" id="specs">
                        <table class="table table-striped table-bordered mb-0">
                            <tbody>
                                <tr>
                                    <th style="width: 30%;">Brand / Store</th>
                                    <td><?php echo e($product['store_name'] ?? 'Independent Vendor'); ?></td>
                                </tr>
                                <tr>
                                    <th>Category</th>
                                    <td><?php echo e($product['category_name'] ?? 'Uncategorized'); ?></td>
                                </tr>
                                <tr>
                                    <th>SKU</th>
                                    <td><?php echo e($product['sku'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Weight</th>
                                    <td><?php echo !empty($product['weight']) ? e($product['weight']) . ' kg' : 'Standard'; ?></td>
                                </tr>
                                <tr>
                                    <th>Dimensions</th>
                                    <td><?php echo e($product['dimensions'] ?? 'Standard packaging'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Reviews Section -->
    <div class="row g-4 mb-5" id="reviews-section">
        <div class="col-12">
            <div class="ss-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                    <h3 class="h4 mb-0">Customer Reviews (<?php echo $reviews_total; ?>)</h3>
                    <?php if ($can_review): ?>
                        <button class="btn btn-ss-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#reviewFormCollapse">
                            <i class="bi bi-pencil-square"></i> Write a Review
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($can_review): ?>
                    <div class="collapse mb-4" id="reviewFormCollapse">
                        <div class="p-3 bg-light border rounded">
                            <h5 class="h6 mb-3">Rate and Review this Product</h5>
                            <form action="<?php echo e(url('/actions/review-actions.php')); ?>" method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Your Rating (1 to 5 Stars):</label>
                                    <div class="ss-rating-input d-flex gap-2 text-warning fs-5" style="cursor: pointer;">
                                        <i class="bi bi-star-fill" data-rating="1"></i>
                                        <i class="bi bi-star-fill" data-rating="2"></i>
                                        <i class="bi bi-star-fill" data-rating="3"></i>
                                        <i class="bi bi-star-fill" data-rating="4"></i>
                                        <i class="bi bi-star-fill" data-rating="5"></i>
                                    </div>
                                    <input type="hidden" name="rating" value="5">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Headline / Title</label>
                                    <input type="text" name="title" class="ss-form-control" required placeholder="Summarize your experience">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Review Comment</label>
                                    <textarea name="comment" rows="3" class="ss-form-control" required placeholder="What did you like or dislike?"></textarea>
                                </div>
                                <button type="submit" class="btn btn-ss-primary btn-sm">Submit Review</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($reviews)): ?>
                    <div class="text-center py-4 text-ss-muted">
                        <i class="bi bi-chat-left-text fs-2 d-block mb-2"></i>
                        No customer reviews yet. Be the first verified buyer to leave a review!
                    </div>
                <?php else: ?>
                    <div class="ss-reviews-list">
                        <?php foreach ($reviews as $rev): ?>
                            <div class="ss-review">
                                <div class="ss-review-header">
                                    <div class="ss-review-avatar">
                                        <?php echo strtoupper(substr($rev['first_name'] ?? 'U', 0, 1)); ?>
                                    </div>
                                    <div class="ss-review-meta">
                                        <h6 class="mb-0 fw-bold"><?php echo e(($rev['first_name'] ?? 'Customer') . ' ' . substr($rev['last_name'] ?? '', 0, 1) . '.'); ?></h6>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="ss-product-rating mb-0">
                                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                                    <i class="bi bi-star<?php echo $s <= $rev['rating'] ? '-fill' : ''; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <small class="text-ss-muted"><?php echo format_date($rev['created_at'], 'M d, Y'); ?></small>
                                            <?php if (!empty($rev['is_verified_purchase'])): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle py-0" style="font-size: 0.65rem;">Verified Purchase</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="ss-review-body">
                                    <strong class="d-block mb-1"><?php echo e($rev['title']); ?></strong>
                                    <p class="mb-0 text-ss-muted"><?php echo nl2br(e($rev['comment'])); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Related Products -->
    <?php if (!empty($related_products)): ?>
        <div class="mb-5">
            <h3 class="h4 mb-4">Related Products</h3>
            <div class="row g-3 row-cols-2 row-cols-md-4">
                <?php foreach ($related_products as $rel): ?>
                    <div class="col">
                        <div class="ss-product-card h-100">
                            <div class="ss-product-card-img">
                                <a href="<?php echo e(url('/product.php?slug=' . $rel['slug'])); ?>">
                                    <img src="<?php echo e(!empty($rel['image_path']) ? asset($rel['image_path']) : asset('images/misc/placeholder.svg')); ?>" alt="<?php echo e($rel['name']); ?>">
                                </a>
                            </div>
                            <div class="ss-product-card-body">
                                <h6><a href="<?php echo e(url('/product.php?slug=' . $rel['slug'])); ?>"><?php echo e($rel['name']); ?></a></h6>
                                <div class="ss-product-price">
                                    <span class="current"><?php echo format_price($rel['price']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
