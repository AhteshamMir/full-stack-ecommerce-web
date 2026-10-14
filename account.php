<?php
/**
 * ShopSphere - Customer Account Overview Dashboard
 *
 * Provides a high-level customer portal dashboard featuring quick statistics,
 * recent order summaries, default address preview, and customer profile details.
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

// Enforce customer authentication
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();
$currentUser = current_user();

// 1. Fetch summary statistics
$stats = [
    'total_orders'   => 0,
    'pending_orders' => 0,
    'wishlist_items' => 0,
    'total_reviews'  => 0,
];

try {
    // Total Orders
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $stats['total_orders'] = (int) $stmt->fetchColumn();

    // Pending Orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND order_status IN ('pending', 'confirmed', 'processing')");
    $stmt->execute([':uid' => $userId]);
    $stats['pending_orders'] = (int) $stmt->fetchColumn();

    // Wishlist Items
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $stats['wishlist_items'] = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM wishlist WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            $stats['wishlist_items'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e2) {
            $stats['wishlist_items'] = 0;
        }
    }

    // Total Reviews
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $stats['total_reviews'] = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $stats['total_reviews'] = 0;
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Account Stats Error] ' . $e->getMessage());
}

// 2. Fetch Recent Orders (Last 5)
$recentOrders = [];
try {
    $stmtOrders = $pdo->prepare("SELECT 
        o.id, 
        o.order_number, 
        o.total_amount, 
        o.order_status, 
        o.payment_method, 
        o.payment_status, 
        o.created_at,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
    FROM orders o
    WHERE o.user_id = :uid
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT 5");
    $stmtOrders->execute([':uid' => $userId]);
    $recentOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Account Recent Orders Error] ' . $e->getMessage());
}

// 3. Fetch Default Shipping Address
$defaultAddress = null;
try {
    $stmtAddr = $pdo->prepare("SELECT * FROM addresses WHERE user_id = :uid AND is_default = 1 LIMIT 1");
    $stmtAddr->execute([':uid' => $userId]);
    $defaultAddress = $stmtAddr->fetch(PDO::FETCH_ASSOC);

    if (!$defaultAddress) {
        // Fallback to first registered address
        $stmtAddrFallback = $pdo->prepare("SELECT * FROM addresses WHERE user_id = :uid ORDER BY id ASC LIMIT 1");
        $stmtAddrFallback->execute([':uid' => $userId]);
        $defaultAddress = $stmtAddrFallback->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Account Address Error] ' . $e->getMessage());
}

// 4. Fetch Customer Profile details
$customerProfile = null;
try {
    $stmtProf = $pdo->prepare("SELECT * FROM customer_profiles WHERE user_id = :uid LIMIT 1");
    $stmtProf->execute([':uid' => $userId]);
    $customerProfile = $stmtProf->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('[ShopSphere Account Profile Error] ' . $e->getMessage());
}

$page_title = 'My Account';
$active_page = 'account';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li>My Account</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Account Content -->
        <div class="col-lg-9 col-md-8">
            <!-- Welcome Header Card -->
            <div class="ss-card mb-4 border-0 shadow-sm" style="background: linear-gradient(135deg, var(--ss-primary) 0%, var(--ss-primary-light) 100%); color: #fff;">
                <div class="ss-card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <span class="badge bg-white text-dark mb-2 fw-semibold px-2 py-1">Customer Portal</span>
                        <h3 class="text-white mb-1 fw-bold">
                            Welcome, <?= e(($currentUser['first_name'] ?? '') ?: 'Valued Shopper') ?>!
                        </h3>
                        <p class="text-white-50 mb-0 small">
                            Manage your orders, update delivery addresses, and track your wishlist.
                        </p>
                    </div>
                    <div>
                        <a href="<?= url('profile.php') ?>" class="btn btn-light btn-sm fw-semibold text-ss-primary shadow-sm px-3 py-2">
                            <i class="bi bi-pencil-square me-1"></i> Edit Profile
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row g-3 mb-4">
                <!-- Total Orders -->
                <div class="col-6 col-xl-3">
                    <a href="<?= url('orders.php') ?>" class="text-decoration-none text-reset">
                        <div class="ss-stat-card shadow-sm h-100">
                            <div class="ss-stat-icon blue">
                                <i class="bi bi-bag-check-fill"></i>
                            </div>
                            <div class="ss-stat-info">
                                <h3><?= $stats['total_orders'] ?></h3>
                                <p>Total Orders</p>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Pending Orders -->
                <div class="col-6 col-xl-3">
                    <a href="<?= url('orders.php?status=pending') ?>" class="text-decoration-none text-reset">
                        <div class="ss-stat-card shadow-sm h-100">
                            <div class="ss-stat-icon yellow">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="ss-stat-info">
                                <h3><?= $stats['pending_orders'] ?></h3>
                                <p>In Progress</p>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Wishlist Items -->
                <div class="col-6 col-xl-3">
                    <a href="<?= url('wishlist.php') ?>" class="text-decoration-none text-reset">
                        <div class="ss-stat-card shadow-sm h-100">
                            <div class="ss-stat-icon red">
                                <i class="bi bi-heart-fill"></i>
                            </div>
                            <div class="ss-stat-info">
                                <h3><?= $stats['wishlist_items'] ?></h3>
                                <p>Wishlist Items</p>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Reviews Given -->
                <div class="col-6 col-xl-3">
                    <a href="<?= url('reviews.php') ?>" class="text-decoration-none text-reset">
                        <div class="ss-stat-card shadow-sm h-100">
                            <div class="ss-stat-icon green">
                                <i class="bi bi-star-fill"></i>
                            </div>
                            <div class="ss-stat-info">
                                <h3><?= $stats['total_reviews'] ?></h3>
                                <p>Reviews</p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Recent Orders Section -->
            <div class="ss-card mb-4 shadow-sm">
                <div class="ss-card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history text-ss-accent me-2"></i> Recent Orders
                    </h5>
                    <a href="<?= url('orders.php') ?>" class="small fw-semibold text-ss-accent text-decoration-none">
                        View All (<?= $stats['total_orders'] ?>) <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="ss-card-body p-0">
                    <?php if (empty($recentOrders)): ?>
                        <div class="ss-empty-state py-5">
                            <i class="bi bi-bag-x"></i>
                            <h5>No Orders Found</h5>
                            <p>You haven't placed any orders yet. Discover our latest collections and best sellers!</p>
                            <a href="<?= url('/') ?>" class="btn btn-ss-primary btn-sm">
                                <i class="bi bi-shop me-1"></i> Start Shopping
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table ss-table mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Date</th>
                                        <th>Items</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <?php
                                        $statusClass = match (strtolower((string) $order['order_status'])) {
                                            'delivered'  => 'ss-badge-success',
                                            'shipped'    => 'ss-badge-info',
                                            'processing' => 'ss-badge-warning',
                                            'confirmed'  => 'ss-badge-info',
                                            'cancelled', 'returned' => 'ss-badge-danger',
                                            default      => 'ss-badge-warning',
                                        };
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?= url('order-details.php?id=' . $order['id']) ?>" class="fw-bold text-ss-accent text-decoration-none">
                                                    <?= e($order['order_number']) ?>
                                                </a>
                                            </td>
                                            <td class="text-muted small">
                                                <?= e(format_date($order['created_at'], 'M d, Y')) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <?= (int) ($order['item_count'] ?? 1) ?> item(s)
                                                </span>
                                            </td>
                                            <td class="fw-semibold">
                                                <?= format_price($order['total_amount']) ?>
                                            </td>
                                            <td>
                                                <span class="ss-badge <?= $statusClass ?>">
                                                    <?= e(ucfirst((string) $order['order_status'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="<?= url('order-details.php?id=' . $order['id']) ?>" class="btn btn-sm btn-outline-primary py-1 px-2" title="View Order Details">
                                                    <i class="bi bi-eye"></i> Details
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Summary and Default Address Cards -->
            <div class="row g-4">
                <!-- Customer Profile Summary -->
                <div class="col-md-6">
                    <div class="ss-card h-100 shadow-sm">
                        <div class="ss-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-person-badge text-ss-accent me-2"></i> Profile Summary
                            </h5>
                            <a href="<?= url('profile.php') ?>" class="small text-ss-accent text-decoration-none fw-semibold">
                                Edit
                            </a>
                        </div>
                        <div class="ss-card-body">
                            <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                                <div class="ss-account-avatar" style="width: 56px; height: 56px; font-size: 1.5rem;">
                                    <?= strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1)) ?>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold"><?= e(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></h6>
                                    <small class="text-muted d-block"><?= e($currentUser['email'] ?? '') ?></small>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size: 0.65rem;">
                                        Verified Account
                                    </span>
                                </div>
                            </div>
                            <ul class="list-unstyled mb-0 small">
                                <li class="d-flex justify-content-between py-1 text-muted">
                                    <span>Phone:</span>
                                    <strong class="text-dark"><?= e(($currentUser['phone'] ?? '') ?: 'Not provided') ?></strong>
                                </li>
                                <li class="d-flex justify-content-between py-1 text-muted">
                                    <span>Gender:</span>
                                    <strong class="text-dark"><?= e(ucfirst($customerProfile['gender'] ?? 'Not set')) ?></strong>
                                </li>
                                <li class="d-flex justify-content-between py-1 text-muted">
                                    <span>Date of Birth:</span>
                                    <strong class="text-dark">
                                        <?= !empty($customerProfile['date_of_birth']) ? e(format_date($customerProfile['date_of_birth'], 'M d, Y')) : 'Not set' ?>
                                    </strong>
                                </li>
                                <li class="d-flex justify-content-between py-1 text-muted">
                                    <span>Member Since:</span>
                                    <strong class="text-dark"><?= e(format_date($currentUser['created_at'] ?? 'now', 'M Y')) ?></strong>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Default Shipping Address -->
                <div class="col-md-6">
                    <div class="ss-card h-100 shadow-sm">
                        <div class="ss-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-geo-alt-fill text-ss-accent me-2"></i> Default Shipping Address
                            </h5>
                            <a href="<?= url('addresses.php') ?>" class="small text-ss-accent text-decoration-none fw-semibold">
                                Manage
                            </a>
                        </div>
                        <div class="ss-card-body">
                            <?php if ($defaultAddress): ?>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <h6 class="mb-0 fw-bold"><?= e($defaultAddress['full_name']) ?></h6>
                                    <?php if (!empty($defaultAddress['is_default'])): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;">Default</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-telephone text-muted me-1"></i> <?= e($defaultAddress['phone']) ?><br>
                                    <i class="bi bi-pin-map text-muted me-1"></i> <?= e($defaultAddress['address_line1']) ?><?= !empty($defaultAddress['address_line2']) ? ', ' . e($defaultAddress['address_line2']) : '' ?><br>
                                    <?= e($defaultAddress['city']) ?>, <?= e($defaultAddress['state'] ?? '') ?> <?= e($defaultAddress['postal_code'] ?? '') ?><br>
                                    <?= e($defaultAddress['country'] ?? 'Pakistan') ?>
                                </p>
                                <div class="mt-3 pt-2 border-top">
                                    <a href="<?= url('addresses.php?edit=' . $defaultAddress['id']) ?>" class="btn btn-outline-secondary btn-sm py-1 px-3">
                                        <i class="bi bi-pencil me-1"></i> Edit Address
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-geo-alt display-6 text-muted opacity-50 mb-2 d-block"></i>
                                    <p class="small mb-3">No saved addresses found. Add a delivery address for quick checkout!</p>
                                    <a href="<?= url('addresses.php?action=new') ?>" class="btn btn-ss-primary btn-sm">
                                        <i class="bi bi-plus-lg me-1"></i> Add New Address
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
