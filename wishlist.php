<?php
/**
 * ShopSphere - Persistent Customer Wishlist Page
 *
 * Displays saved favorite products, enables immediate transfer to shopping cart,
 * and allows fast item removal via standard POST or asynchronous AJAX requests.
 *
 * @package ShopSphere\Customer
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/classes/CartService.php';

// Enforce customer login
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();

// Determine whether table is `wishlists` or `wishlist`
$wishlistTable = 'wishlists';
try {
    $pdo->query('SELECT 1 FROM wishlists LIMIT 1');
    $wishlistTable = 'wishlists';
} catch (Throwable) {
    try {
        $pdo->query('SELECT 1 FROM wishlist LIMIT 1');
        $wishlistTable = 'wishlist';
    } catch (Throwable) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wishlists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            created_at DATETIME NULL,
            UNIQUE KEY user_product (user_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $wishlistTable = 'wishlists';
    }
}

// Handle POST actions (Remove, Add to Cart, Clear)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        if (is_ajax()) {
            json_response(['success' => false, 'error' => 'Security token expired. Please refresh the page.'], 403);
        }
        flash('error', 'Session expired. Please try again.');
        redirect(url('wishlist.php'));
    }

    $action = sanitize_string($_POST['action'] ?? '');
    $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

    // 1. REMOVE FROM WISHLIST
    if ($action === 'remove' && $productId) {
        try {
            $delStmt = $pdo->prepare("DELETE FROM {$wishlistTable} WHERE product_id = :pid AND user_id = :uid");
            $delStmt->execute([':pid' => $productId, ':uid' => $userId]);

            if (is_ajax()) {
                json_response(['success' => true, 'message' => 'Item removed from wishlist.']);
            }
            flash('success', 'Product removed from your wishlist.');
        } catch (Throwable $e) {
            error_log('[ShopSphere Wishlist Remove Error] ' . $e->getMessage());
            if (is_ajax()) {
                json_response(['success' => false, 'error' => 'Failed to remove item.'], 500);
            }
            flash('error', 'Could not remove product from wishlist.');
        }
        redirect(url('wishlist.php'));
    }

    // 2. ADD TO CART FROM WISHLIST
    if ($action === 'add_to_cart' && $productId) {
        try {
            $cartService = new CartService($pdo);
            $cart = $cartService->getCart($userId);
            $cartResult = $cartService->addItem((int) $cart['id'], $productId, null, 1);

            if ($cartResult['success']) {
                // Optionally remove from wishlist once added to cart
                if (!empty($_POST['remove_after_add'])) {
                    $delStmt = $pdo->prepare("DELETE FROM {$wishlistTable} WHERE product_id = :pid AND user_id = :uid");
                    $delStmt->execute([':pid' => $productId, ':uid' => $userId]);
                }

                if (is_ajax()) {
                    json_response([
                        'success' => true,
                        'message' => 'Item added to your shopping cart!',
                    ]);
                }
                flash('success', 'Product added to your cart.');
            } else {
                if (is_ajax()) {
                    json_response([
                        'success' => false,
                        'error'   => $cartResult['message'] ?? 'Unable to add item to cart.',
                    ], 400);
                }
                flash('error', $cartResult['message'] ?? 'Product could not be added to cart.');
            }
        } catch (Throwable $e) {
            error_log('[ShopSphere Wishlist Add to Cart Error] ' . $e->getMessage());
            if (is_ajax()) {
                json_response(['success' => false, 'error' => 'An error occurred.'], 500);
            }
            flash('error', 'Failed to add product to cart.');
        }
        redirect(url('wishlist.php'));
    }

    // 3. CLEAR ALL WISHLIST
    if ($action === 'clear_all') {
        try {
            $stmt = $pdo->prepare("DELETE FROM {$wishlistTable} WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            flash('success', 'Your wishlist has been cleared.');
        } catch (Throwable $e) {
            error_log('[ShopSphere Wishlist Clear Error] ' . $e->getMessage());
            flash('error', 'Failed to clear wishlist.');
        }
        redirect(url('wishlist.php'));
    }
}

// Fetch all wishlist products for logged-in user
$wishlistItems = [];
try {
    $sql = "SELECT 
        w.id AS wishlist_id,
        w.product_id,
        w.created_at AS added_at,
        p.name AS product_name,
        p.slug AS product_slug,
        p.price,
        p.compare_price,
        p.stock_quantity,
        p.status AS product_status,
        sp.store_name,
        (SELECT pi.image_path 
         FROM product_images pi 
         WHERE pi.product_id = p.id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC 
         LIMIT 1) AS primary_image
    FROM {$wishlistTable} w
    JOIN products p ON p.id = w.product_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE w.user_id = :uid
    ORDER BY w.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $wishlistItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Wishlist Fetch Error] ' . $e->getMessage());
    $wishlistItems = [];
}

$page_title = 'My Wishlist';
$active_page = 'wishlist';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li>My Wishlist</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Wishlist Content -->
        <div class="col-lg-9 col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-1">
                        <i class="bi bi-heart-fill text-danger me-2"></i> My Wishlist
                        <span class="badge bg-light text-muted border fs-6 ms-2"><?= count($wishlistItems) ?> Items</span>
                    </h4>
                    <p class="text-muted small mb-0">Products you've saved to purchase later or keep track of discounts.</p>
                </div>

                <?php if (!empty($wishlistItems)): ?>
                    <form action="<?= url('wishlist.php') ?>" method="POST" onsubmit="return confirm('Are you sure you want to clear your entire wishlist?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_all">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-trash me-1"></i> Clear Wishlist
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($wishlistItems)): ?>
                <div class="ss-card shadow-sm">
                    <div class="ss-empty-state py-5">
                        <i class="bi bi-heart text-muted opacity-50 display-3 mb-3 d-block"></i>
                        <h5>Your Wishlist is Empty</h5>
                        <p class="text-muted small mb-4">
                            Explore thousands of products and tap the heart icon on any item to save it here for later.
                        </p>
                        <a href="<?= url('/') ?>" class="btn btn-ss-primary px-4">
                            <i class="bi bi-shop me-1"></i> Explore Products
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Wishlist Items Grid -->
                <div class="row g-3" id="wishlistGrid">
                    <?php foreach ($wishlistItems as $item): ?>
                        <?php
                        $inStock = ((int) $item['stock_quantity'] > 0) && ($item['product_status'] === 'approved');
                        $hasDiscount = !empty($item['compare_price']) && ((float) $item['compare_price'] > (float) $item['price']);
                        ?>
                        <div class="col-sm-6 col-lg-4" id="wishlist-item-<?= $item['product_id'] ?>">
                            <div class="ss-card h-100 shadow-sm d-flex flex-column justify-content-between position-relative">
                                <!-- Remove Button (top right badge) -->
                                <button type="button" 
                                        class="btn btn-light btn-sm rounded-circle shadow-sm position-absolute top-0 end-0 m-2 remove-wishlist-btn" 
                                        data-product-id="<?= $item['product_id'] ?>"
                                        title="Remove from Wishlist" 
                                        style="width: 32px; height: 32px; z-index: 10; padding: 0;">
                                    <i class="bi bi-x-lg text-danger"></i>
                                </button>

                                <div>
                                    <!-- Product Image -->
                                    <div class="bg-light text-center p-3" style="height: 180px; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                                        <a href="<?= url('product.php?slug=' . urlencode((string) $item['product_slug'])) ?>">
                                            <?php if (!empty($item['primary_image'])): ?>
                                                <img src="<?= e(asset($item['primary_image'])) ?>" alt="<?= e($item['product_name']) ?>" style="max-height: 160px; max-width: 100%; object-fit: contain;">
                                            <?php else: ?>
                                                <div class="ss-img-placeholder" style="height: 160px; width: 160px; font-size: 2.5rem;">
                                                    <i class="bi bi-box"></i>
                                                </div>
                                            <?php endif; ?>
                                        </a>
                                    </div>

                                    <!-- Product Info -->
                                    <div class="p-3">
                                        <?php if (!empty($item['store_name'])): ?>
                                            <small class="text-muted d-block mb-1" style="font-size: 0.72rem;">
                                                <i class="bi bi-shop me-1"></i><?= e($item['store_name']) ?>
                                            </small>
                                        <?php endif; ?>

                                        <h6 class="fw-bold mb-2">
                                            <a href="<?= url('product.php?slug=' . urlencode((string) $item['product_slug'])) ?>" class="text-dark text-decoration-none text-truncate d-block" title="<?= e($item['product_name']) ?>">
                                                <?= e($item['product_name']) ?>
                                            </a>
                                        </h6>

                                        <!-- Price -->
                                        <div class="d-flex align-items-baseline gap-2 mb-2">
                                            <span class="fs-6 fw-bold text-ss-accent">
                                                <?= format_price($item['price']) ?>
                                            </span>
                                            <?php if ($hasDiscount): ?>
                                                <span class="text-muted text-decoration-line-through small" style="font-size: 0.75rem;">
                                                    <?= format_price($item['compare_price']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Stock status -->
                                        <div class="mb-2">
                                            <?php if ($inStock): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.68rem;">
                                                    <i class="bi bi-check-circle me-1"></i> In Stock
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.68rem;">
                                                    <i class="bi bi-x-circle me-1"></i> Out of Stock
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="p-3 pt-0 border-top mt-2">
                                    <div class="pt-2">
                                        <?php if ($inStock): ?>
                                            <form action="<?= url('wishlist.php') ?>" method="POST" class="add-to-cart-form m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="add_to_cart">
                                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                                <button type="submit" class="btn btn-ss-primary btn-sm w-100 py-2">
                                                    <i class="bi bi-cart-plus me-1"></i> Add to Cart
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary btn-sm w-100 py-2 disabled">
                                                Out of Stock
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- AJAX handling script for wishlist removal -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Delegate remove buttons
    document.querySelectorAll('.remove-wishlist-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const productId = this.getAttribute('data-product-id');
            if (!productId) return;

            if (!confirm('Remove this item from your wishlist?')) return;

            const cardCol = document.getElementById('wishlist-item-' + productId);
            if (cardCol) {
                cardCol.style.opacity = '0.5';
            }

            const formData = new FormData();
            formData.append('action', 'remove');
            formData.append('product_id', productId);
            formData.append('csrf_token', csrfToken);

            fetch('<?= url('wishlist.php') ?>', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (cardCol) {
                        cardCol.remove();
                    }
                    // If no items remain, reload to show empty state
                    const remaining = document.querySelectorAll('#wishlistGrid > div');
                    if (remaining.length === 0) {
                        window.location.reload();
                    }
                } else {
                    alert(data.error || 'Failed to remove item.');
                    if (cardCol) cardCol.style.opacity = '1';
                }
            })
            .catch(() => {
                // Fallback to standard form submission
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '<?= url('wishlist.php') ?>';
                form.innerHTML = `
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="${productId}">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                `;
                document.body.appendChild(form);
                form.submit();
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
