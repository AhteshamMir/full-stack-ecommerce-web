<?php
/**
 * ShopSphere - Shopping Cart
 */
$page_title = 'Your Shopping Cart';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/classes/CartService.php';

$cartService = new CartService();
$user_id = get_current_user_id();
$cart = $cartService->getCart($user_id);
$cart_items = $cart ? $cartService->getCartItems($cart['id']) : [];
$cart_total = $cart ? $cartService->getCartTotal($cart['id']) : ['subtotal' => 0, 'discount' => 0, 'total' => 0];
?>

<div class="container py-4">
    <nav class="ss-breadcrumb">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li>Shopping Cart</li>
        </ol>
    </nav>

    <h1 class="h3 fw-bold mb-4">Shopping Cart (<?php echo count($cart_items); ?> Items)</h1>

    <?php if (empty($cart_items)): ?>
        <div class="ss-card ss-empty-state py-5 text-center">
            <i class="bi bi-cart-x" style="font-size: 4rem; color: #9ca3af;"></i>
            <h4 class="mt-3">Your cart is empty</h4>
            <p class="text-ss-muted">Browse our marketplace to discover thousands of products from verified sellers.</p>
            <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-primary px-4 py-2">
                <i class="bi bi-bag"></i> Start Shopping
            </a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="ss-card">
                    <div class="ss-card-body p-0">
                        <div class="ss-table-responsive">
                            <table class="ss-table ss-cart-table mb-0">
                                <thead>
                                    <tr>
                                        <th colspan="2">Product</th>
                                        <th>Price</th>
                                        <th style="width: 130px;">Quantity</th>
                                        <th>Subtotal</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart_items as $item): ?>
                                        <tr class="ss-cart-item">
                                            <td style="width: 80px;">
                                                <div style="width: 70px; height: 70px; border-radius: 8px; overflow: hidden; background: #f3f4f6;">
                                                    <img src="<?php echo e(!empty($item['image_path']) ? asset($item['image_path']) : asset('images/misc/placeholder.svg')); ?>" alt="<?php echo e($item['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                </div>
                                            </td>
                                            <td>
                                                <a href="<?php echo e(url('/product.php?slug=' . ($item['slug'] ?? ''))); ?>" class="fw-bold text-ss-primary"><?php echo e($item['name']); ?></a>
                                                <?php if (!empty($item['variant_info'])): ?>
                                                    <div class="small text-ss-muted">Variant: <?php echo e($item['variant_info']); ?></div>
                                                <?php endif; ?>
                                                <div class="small text-ss-muted">Seller: <?php echo e($item['store_name'] ?? 'Verified Seller'); ?></div>
                                            </td>
                                            <td><?php echo format_price($item['unit_price']); ?></td>
                                            <td>
                                                <div class="ss-quantity-control">
                                                    <button type="button" class="ss-qty-minus"><i class="bi bi-dash"></i></button>
                                                    <input type="number" class="ss-qty-input ss-cart-qty" value="<?php echo $item['quantity']; ?>" min="1" max="<?php echo $item['stock_quantity'] ?? 99; ?>" data-item-id="<?php echo $item['id']; ?>" data-original-qty="<?php echo $item['quantity']; ?>">
                                                    <button type="button" class="ss-qty-plus"><i class="bi bi-plus"></i></button>
                                                </div>
                                            </td>
                                            <td class="ss-item-total fw-bold"><?php echo format_price($item['unit_price'] * $item['quantity']); ?></td>
                                            <td>
                                                <button class="btn btn-link text-danger p-0 ss-cart-remove" data-item-id="<?php echo $item['id']; ?>" title="Remove">
                                                    <i class="bi bi-trash3 fs-5"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-3 d-flex flex-wrap justify-content-between">
                    <a href="<?php echo e(url('/shop.php')); ?>" class="btn btn-ss-outline">
                        <i class="bi bi-arrow-left"></i> Continue Shopping
                    </a>
                </div>
            </div>

            <!-- Order Summary Sidebar -->
            <div class="col-lg-4">
                <div class="ss-card ss-cart-summary p-4" style="position: sticky; top: 20px;">
                    <h5 class="fw-bold mb-3 pb-3 border-bottom">Order Summary</h5>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-ss-muted">Subtotal</span>
                        <span class="fw-bold ss-summary-subtotal"><?php echo format_price($cart_total['subtotal']); ?></span>
                    </div>

                    <?php if (($cart_total['discount'] ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between mb-2 ss-summary-discount-row">
                            <span class="text-ss-muted">Coupon Discount</span>
                            <span class="text-danger fw-bold ss-summary-discount">-<?php echo format_price($cart_total['discount']); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-ss-muted">Estimated Shipping</span>
                        <span class="ss-summary-shipping text-ss-success fw-bold">Free</span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between mb-4">
                        <span class="h5 mb-0">Total</span>
                        <span class="h5 mb-0 text-ss-primary fw-bold ss-summary-total"><?php echo format_price($cart_total['total'] ?? $cart_total['subtotal']); ?></span>
                    </div>

                    <!-- Coupon Form -->
                    <form class="ss-coupon-form mb-4">
                        <?php echo csrf_field(); ?>
                        <div class="input-group">
                            <input type="text" name="coupon_code" class="form-control" placeholder="Enter coupon code" value="<?php echo e($cart['coupon_code'] ?? ''); ?>">
                            <button type="submit" class="btn btn-ss-outline">Apply</button>
                        </div>
                    </form>

                    <a href="<?php echo e(url('/checkout.php')); ?>" class="btn btn-ss-primary w-100 py-3 fw-bold">
                        <i class="bi bi-lock"></i> Proceed to Checkout
                    </a>

                    <div class="text-center mt-3">
                        <small class="text-ss-muted"><i class="bi bi-shield-check"></i> Secure Checkout | <i class="bi bi-arrow-return-left"></i> 7-Day Returns</small>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
