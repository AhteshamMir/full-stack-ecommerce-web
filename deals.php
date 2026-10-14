<?php
/**
 * ShopSphere - Flash Deals & Discounts Page
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/ProductService.php';

$page_title = 'Hot Deals & Limited-Time Discounts — ' . APP_NAME;
require_once __DIR__ . '/includes/header.php';

$productService = new ProductService();
$deals = $productService->getDealProducts(24);

// Simulated 48-hour promotional countdown timer
$deal_end_time = date('Y-m-d H:i:s', strtotime('+2 days 23:59:59'));
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Flash Deals</li>
        </ol>
    </nav>

    <!-- Promotional Header Banner with Countdown -->
    <div class="p-4 p-md-5 mb-4 rounded bg-primary text-white d-flex flex-wrap justify-content-between align-items-center" style="background: linear-gradient(135deg, #1e2a4a 0%, #4f6df5 100%) !important;">
        <div>
            <span class="badge bg-danger mb-2 px-3 py-2 text-uppercase fw-bold" style="letter-spacing: 1px;">Mega Marketplace Sale</span>
            <h1 class="h2 fw-bold mb-2">Unbeatable Flash Deals</h1>
            <p class="mb-0 text-white-50">Grab massive price slashes up to 60% off from verified sellers nationwide.</p>
        </div>
        <div class="mt-3 mt-md-0">
            <div class="text-white-50 small mb-1 text-center text-md-end">Offers Expire In:</div>
            <div class="ss-countdown ss-deals-timer" data-end="<?php echo e($deal_end_time); ?>">
                <div class="time-block"><span class="number days">02</span><span class="label">Days</span></div>
                <div class="separator">:</div>
                <div class="time-block"><span class="number hours">18</span><span class="label">Hours</span></div>
                <div class="separator">:</div>
                <div class="time-block"><span class="number minutes">45</span><span class="label">Mins</span></div>
                <div class="separator">:</div>
                <div class="time-block"><span class="number seconds">20</span><span class="label">Secs</span></div>
            </div>
        </div>
    </div>

    <?php if (empty($deals)): ?>
        <div class="ss-card ss-empty-state py-5 text-center">
            <i class="bi bi-lightning-charge fs-1 text-warning mb-2"></i>
            <h5>No flash deals active right now</h5>
            <p class="text-ss-muted">Check back soon for upcoming holiday promotions and vendor campaigns.</p>
            <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary btn-sm">Explore Regular Products</a>
        </div>
    <?php else: ?>
        <div class="row g-3 row-cols-2 row-cols-md-3 row-cols-lg-4">
            <?php foreach ($deals as $prod): ?>
                <?php 
                $discount_pct = round((($prod['compare_price'] - $prod['price']) / $prod['compare_price']) * 100);
                ?>
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
                            <div class="ss-product-category text-danger fw-bold">Limited Offer</div>
                            <h6><a href="<?php echo e(url('/product.php?slug=' . $prod['slug'])); ?>"><?php echo e($prod['name']); ?></a></h6>
                            <div class="ss-product-seller">
                                Store: <a href="<?php echo e(url('/seller-store.php?id=' . $prod['seller_id'])); ?>"><?php echo e($prod['store_name'] ?? 'Verified Seller'); ?></a>
                            </div>
                            <div class="ss-product-price">
                                <span class="current text-danger"><?php echo format_price($prod['price']); ?></span>
                                <span class="original"><?php echo format_price($prod['compare_price']); ?></span>
                            </div>
                        </div>
                        <div class="ss-product-card-footer">
                            <button class="btn btn-ss-primary btn-sm w-100 ss-add-to-cart" data-product-id="<?php echo $prod['id']; ?>">
                                <i class="bi bi-cart-plus"></i> Claim Deal
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
