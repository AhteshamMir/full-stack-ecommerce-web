<?php
/**
 * ShopSphere - Multi-Vendor Checkout Page
 *
 * Implements a 4-step multi-vendor checkout experience:
 * Step 1: Shipping address selection (saved addresses radio + add new option)
 * Step 2: Items review grouped by seller with stock availability checks
 * Step 3: Payment method selection (Cash on Delivery or Simulated Card)
 * Step 4: Order delivery instructions & notes
 *
 * Displays financial summary sidebar (subtotal, shipping, coupon discount, grand total)
 * and dispatches state-changing order submission with CSRF and idempotency protection.
 *
 * @package ShopSphere\Checkout
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/CartService.php';

// Enforce customer authentication
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();

// 1. Fetch active shopping cart and line items
$cartService = new CartService($pdo);
$cart = $cartService->getCart($userId);
$cartId = (int) $cart['id'];
$cartItems = $cartService->getCartItems($cartId);

if (empty($cartItems)) {
    flash('info', 'Your shopping cart is empty. Please add items before proceeding to checkout.');
    redirect(url('cart.php'));
}

// 2. Calculate cart totals and coupon discount
$totals = $cartService->getCartTotal($cartId, $userId);
$subtotal = (float) $totals['subtotal'];
$discount = (float) $totals['discount_amount'];
$couponCode = $totals['coupon_code'];
$shippingAmount = 0.00; // Free marketplace shipping promotion
$grandTotal = (float) $totals['total'];

// 3. Stock validation
$hasOutOfStock = false;
foreach ($cartItems as $cItem) {
    if (!$cItem['is_available']) {
        $hasOutOfStock = true;
        break;
    }
}

// 4. Fetch user's saved shipping addresses
$addrStmt = $pdo->prepare('SELECT * FROM addresses WHERE user_id = :user_id ORDER BY is_default DESC, id DESC');
$addrStmt->execute([':user_id' => $userId]);
$savedAddresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 5. Group cart items by seller
$sellerGroups = [];
foreach ($cartItems as $item) {
    $sellerId = (int) $item['seller_id'];
    if (!isset($sellerGroups[$sellerId])) {
        $sellerGroups[$sellerId] = [
            'store_name' => (string) ($item['store_name'] ?? 'Verified Seller'),
            'store_slug' => (string) ($item['store_slug'] ?? ''),
            'items'      => [],
            'subtotal'   => 0.00,
        ];
    }
    $sellerGroups[$sellerId]['items'][] = $item;
    $sellerGroups[$sellerId]['subtotal'] += (float) $item['line_total'];
}

// 6. Generate cryptographic idempotency key
$idempotencyKey = 'chk_' . bin2hex(random_bytes(16));

$page_title = 'Secure Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4">
        <ol>
            <li><a href="<?php echo e(url('/')); ?>"><i class="bi bi-house"></i> Home</a></li>
            <li><a href="<?php echo e(url('/cart.php')); ?>">Shopping Cart</a></li>
            <li>Secure Checkout</li>
        </ol>
    </nav>

    <div class="d-flex align-items-center justify-content-between mb-4 border-bottom pb-3">
        <h1 class="h3 fw-bold text-ss-primary mb-0">
            <i class="bi bi-shield-lock text-ss-accent me-2"></i>Secure Checkout
        </h1>
        <div class="text-ss-muted small d-none d-md-block">
            <i class="bi bi-lock-fill text-success me-1"></i> 256-bit Encrypted Checkout
        </div>
    </div>

    <?php if ($hasOutOfStock): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-3 me-3 flex-shrink-0"></i>
            <div>
                <strong>Stock Alert:</strong> Some items in your cart exceed available seller inventory or are currently unavailable. Please review items in Step 2 or adjust your cart before placing your order.
            </div>
        </div>
    <?php endif; ?>

    <form action="<?php echo e(url('/actions/checkout-actions.php')); ?>" method="POST" class="ss-prevent-double-submit" id="checkoutForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="idempotency_key" value="<?php echo e($idempotencyKey); ?>">

        <div class="row g-4">
            <!-- Left Column: Checkout Steps -->
            <div class="col-lg-8">

                <!-- STEP 1: Shipping Address Selection -->
                <div class="ss-card ss-checkout-section p-4 mb-4">
                    <h5 class="text-ss-primary">
                        <span class="step-number">1</span>
                        <span>Shipping Address</span>
                    </h5>

                    <?php if (!empty($savedAddresses)): ?>
                        <p class="text-ss-muted small mb-3">Choose a saved shipping destination or enter a new address:</p>

                        <div class="saved-addresses-list mb-3">
                            <?php foreach ($savedAddresses as $idx => $addr): ?>
                                <?php $isChecked = ($idx === 0); ?>
                                <label class="d-block border rounded p-3 mb-2 address-card-label" style="cursor: pointer; transition: background 0.2s;" for="addr_<?php echo $addr['id']; ?>">
                                    <div class="d-flex align-items-start">
                                        <div class="form-check me-2 mt-1">
                                            <input class="form-check-input" type="radio" name="address_id" id="addr_<?php echo $addr['id']; ?>" value="<?php echo $addr['id']; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <strong class="text-ss-primary"><?php echo e($addr['full_name']); ?></strong>
                                                <span class="badge bg-light text-ss-muted border small"><?php echo e($addr['label']); ?></span>
                                                <?php if (!empty($addr['is_default'])): ?>
                                                    <span class="badge bg-success-subtle text-success small border border-success-subtle">Default</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-ss-text small mb-1">
                                                <?php echo e($addr['address_line1']); ?><?php echo !empty($addr['address_line2']) ? ', ' . e($addr['address_line2']) : ''; ?>,
                                                <?php echo e($addr['city']); ?>, <?php echo e($addr['state']); ?> <?php echo e($addr['postal_code']); ?>, <?php echo e($addr['country']); ?>
                                            </div>
                                            <div class="text-ss-muted small">
                                                <i class="bi bi-telephone me-1"></i><?php echo e($addr['phone']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>

                            <!-- Add New Address Radio Option -->
                            <label class="d-block border rounded p-3 mb-2 address-card-label" style="cursor: pointer;" for="addr_new">
                                <div class="d-flex align-items-center">
                                    <div class="form-check me-2">
                                        <input class="form-check-input" type="radio" name="address_id" id="addr_new" value="new">
                                    </div>
                                    <div>
                                        <strong class="text-ss-accent"><i class="bi bi-plus-circle me-1"></i> Add a New Shipping Address</strong>
                                    </div>
                                </div>
                            </label>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="address_id" value="new">
                        <p class="text-ss-muted small mb-3">Please enter your shipping address details below:</p>
                    <?php endif; ?>

                    <!-- New Address Form Fields (toggled via main.js or displayed if no saved addresses) -->
                    <div class="ss-new-address-form border rounded p-3 bg-light mt-3" style="<?php echo empty($savedAddresses) ? 'display: block;' : 'display: none;'; ?>">
                        <h6 class="fw-bold mb-3 text-ss-primary">
                            <i class="bi bi-geo-alt-fill text-ss-accent me-1"></i> New Address Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="new_full_name" class="form-control form-control-sm" placeholder="e.g. Muhammad Ali">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" name="new_phone" class="form-control form-control-sm" placeholder="e.g. 03001234567">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Street Address <span class="text-danger">*</span></label>
                                <input type="text" name="new_address_line1" class="form-control form-control-sm" placeholder="House/Flat number, Building name, Street address">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Apartment, suite, unit (optional)</label>
                                <input type="text" name="new_address_line2" class="form-control form-control-sm" placeholder="Floor, Landmark, Near landmark">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">City <span class="text-danger">*</span></label>
                                <input type="text" name="new_city" class="form-control form-control-sm" placeholder="e.g. Karachi">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">State / Province <span class="text-danger">*</span></label>
                                <input type="text" name="new_state" class="form-control form-control-sm" placeholder="e.g. Sindh">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Postal Code <span class="text-danger">*</span></label>
                                <input type="text" name="new_postal_code" class="form-control form-control-sm" placeholder="e.g. 74200">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Country <span class="text-danger">*</span></label>
                                <input type="text" name="new_country" class="form-control form-control-sm" value="Pakistan" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Address Label</label>
                                <select name="new_label" class="form-select form-select-sm">
                                    <option value="Home">Home (Personal)</option>
                                    <option value="Office">Office / Work</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="save_as_default" value="1" id="saveDefCheck">
                                    <label class="form-check-label small text-ss-muted" for="saveDefCheck">
                                        Set as my default shipping address for future orders
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Items Review Grouped by Seller -->
                <div class="ss-card ss-checkout-section p-4 mb-4">
                    <h5 class="text-ss-primary">
                        <span class="step-number">2</span>
                        <span>Items Review (Grouped by Seller)</span>
                    </h5>

                    <p class="text-ss-muted small mb-3">
                        ShopSphere is a multi-vendor marketplace. Items will be fulfilled directly by their respective verified sellers:
                    </p>

                    <div class="seller-groups-container">
                        <?php foreach ($sellerGroups as $sId => $group): ?>
                            <div class="border rounded p-3 mb-3 bg-white shadow-sm">
                                <!-- Seller Header -->
                                <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="width: 32px; height: 32px; background: var(--ss-accent-light); border-radius: 6px; display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-shop text-ss-accent"></i>
                                        </div>
                                        <div>
                                            <strong class="text-ss-primary"><?php echo e($group['store_name']); ?></strong>
                                            <span class="badge bg-light text-ss-muted border ms-2 small">Verified Vendor</span>
                                        </div>
                                    </div>
                                    <div class="text-ss-muted small">
                                        <?php echo count($group['items']); ?> <?php echo count($group['items']) === 1 ? 'item' : 'items'; ?>
                                    </div>
                                </div>

                                <!-- Seller Line Items Table -->
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0" style="font-size: 0.875rem;">
                                        <thead>
                                            <tr class="text-ss-muted" style="border-bottom: 1px solid var(--ss-border);">
                                                <th colspan="2" style="font-weight: 600;">Product</th>
                                                <th class="text-center" style="font-weight: 600;">Qty</th>
                                                <th class="text-end" style="font-weight: 600;">Unit Price</th>
                                                <th class="text-end" style="font-weight: 600;">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($group['items'] as $item): ?>
                                                <tr>
                                                    <td style="width: 60px;">
                                                        <div style="width: 50px; height: 50px; border-radius: 6px; overflow: hidden; background: #f3f4f6;">
                                                            <img src="<?php echo e(!empty($item['primary_image']) ? asset($item['primary_image']) : asset('images/misc/placeholder.svg')); ?>" alt="<?php echo e($item['product_name']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <a href="<?php echo e(url('/product.php?slug=' . ($item['product_slug'] ?? ''))); ?>" class="fw-semibold text-ss-primary d-block text-decoration-none">
                                                            <?php echo e($item['product_name']); ?>
                                                        </a>
                                                        <?php if (!empty($item['variant_name'])): ?>
                                                            <span class="badge bg-light text-ss-muted border small me-1">
                                                                <?php echo e($item['variant_name']); ?>: <?php echo e($item['variant_value']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!$item['is_available']): ?>
                                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle small">
                                                                Out of Stock (Available: <?php echo (int) $item['available_stock']; ?>)
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center fw-semibold">
                                                        <?php echo (int) $item['quantity']; ?>
                                                    </td>
                                                    <td class="text-end text-ss-muted">
                                                        <?php echo format_price($item['unit_price']); ?>
                                                    </td>
                                                    <td class="text-end fw-bold text-ss-primary">
                                                        <?php echo format_price($item['line_total']); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="bg-light">
                                                <td colspan="4" class="text-end fw-semibold text-ss-muted pt-2 pb-2">Seller Subtotal:</td>
                                                <td class="text-end fw-bold text-ss-primary pt-2 pb-2"><?php echo format_price($group['subtotal']); ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- STEP 3: Payment Method Selection -->
                <div class="ss-card ss-checkout-section p-4 mb-4">
                    <h5 class="text-ss-primary">
                        <span class="step-number">3</span>
                        <span>Payment Method</span>
                    </h5>

                    <p class="text-ss-muted small mb-3">Select your preferred payment channel:</p>

                    <!-- COD Option -->
                    <div class="ss-payment-method selected" for="pay_cod">
                        <div class="form-check me-2">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_cod" value="cod" checked>
                        </div>
                        <i class="bi bi-cash-stack"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block text-ss-primary">Cash on Delivery (COD)</strong>
                            <small class="text-ss-muted">Pay securely with physical cash upon receiving your parcel at your doorstep.</small>
                        </div>
                    </div>

                    <!-- Simulated Card Option -->
                    <div class="ss-payment-method" for="pay_card">
                        <div class="form-check me-2">
                            <input class="form-check-input" type="radio" name="payment_method" id="pay_card" value="card">
                        </div>
                        <i class="bi bi-credit-card-2-front"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block text-ss-primary">Debit / Credit Card (Simulated Sandbox)</strong>
                            <small class="text-ss-muted">Instant card authorization simulation for Visa, Mastercard, and PayPak.</small>
                        </div>
                    </div>
                </div>

                <!-- STEP 4: Order Notes -->
                <div class="ss-card ss-checkout-section p-4 mb-4">
                    <h5 class="text-ss-primary">
                        <span class="step-number">4</span>
                        <span>Delivery Instructions & Notes</span>
                    </h5>

                    <label class="form-label small text-ss-muted">Special instructions for the seller or delivery courier (optional):</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="e.g. Call before delivery, landmark near gate, leave with security..."></textarea>
                </div>
            </div>

            <!-- Right Column: Order Summary Sidebar -->
            <div class="col-lg-4">
                <div class="ss-card ss-cart-summary p-4" style="position: sticky; top: 20px;">
                    <h5 class="fw-bold mb-3 pb-3 border-bottom text-ss-primary">Order Summary</h5>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-ss-muted">Subtotal (<?php echo count($cartItems); ?> items)</span>
                        <span class="fw-bold text-ss-primary"><?php echo format_price($subtotal); ?></span>
                    </div>

                    <?php if ($discount > 0.00): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-ss-muted">
                                Coupon Discount
                                <?php if ($couponCode): ?>
                                    <span class="badge bg-success-subtle text-success ms-1"><?php echo e($couponCode); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="text-danger fw-bold">-<?php echo format_price($discount); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-ss-muted">Estimated Shipping</span>
                        <span class="text-success fw-bold">Free</span>
                    </div>

                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-ss-muted">Sales Tax</span>
                        <span class="text-ss-muted">Included</span>
                    </div>

                    <hr class="my-3">

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <span class="h5 mb-0 fw-bold">Total Amount</span>
                        <span class="h4 mb-0 text-ss-primary fw-bold"><?php echo format_price($grandTotal); ?></span>
                    </div>

                    <!-- Place Order Button -->
                    <button type="submit" class="btn btn-ss-primary w-100 py-3 fw-bold fs-6 shadow-sm" <?php echo $hasOutOfStock ? 'disabled' : ''; ?>>
                        <i class="bi bi-lock-fill me-1"></i> Place Order Now
                    </button>

                    <div class="mt-4 pt-3 border-top text-center">
                        <div class="d-flex justify-content-center gap-3 text-ss-muted small mb-2">
                            <span><i class="bi bi-shield-check text-success"></i> Buyer Protection</span>
                            <span><i class="bi bi-arrow-repeat text-ss-accent"></i> 7-Day Returns</span>
                        </div>
                        <small class="text-ss-muted" style="font-size: 0.75rem;">
                            By placing this order you agree to ShopSphere's <a href="<?php echo e(url('/terms.php')); ?>" target="_blank">Terms of Service</a> and <a href="<?php echo e(url('/privacy.php')); ?>" target="_blank">Privacy Policy</a>.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
