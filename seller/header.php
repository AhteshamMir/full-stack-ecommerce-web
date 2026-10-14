<?php
/**
 * ShopSphere - Seller Dashboard Header & Sidebar
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

// Enforce seller role
require_role('seller');

$seller_id = get_current_user_id();
$db = get_db();

// Fetch seller profile
$stmt = $db->prepare("SELECT sp.*, u.first_name, u.last_name, u.email FROM seller_profiles sp 
                      JOIN users u ON sp.user_id = u.id 
                      WHERE sp.user_id = ? LIMIT 1");
$stmt->execute([$seller_id]);
$seller = $stmt->fetch();

// Check if current page variable set
$current_page = $current_page ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo generate_csrf_token(); ?>">
    <title><?php echo e($page_title ?? 'Seller Portal'); ?> — ShopSphere</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <script>
        const APP_URL = "<?php echo rtrim(APP_URL, '/'); ?>";
        const CURRENCY_SYMBOL = "<?php echo CURRENCY_SYMBOL; ?>";
    </script>
</head>
<body>

<div class="ss-dashboard">
    <!-- Sidebar Overlay for Mobile -->
    <div class="ss-sidebar-overlay"></div>

    <!-- Seller Sidebar Navigation -->
    <aside class="ss-sidebar">
        <div class="ss-sidebar-header">
            <a href="<?php echo url('/seller/dashboard.php'); ?>" class="ss-logo">
                <div class="ss-logo-icon"><i class="bi bi-shop"></i></div>
                <span>Seller</span>Hub
            </a>
        </div>
        <div class="ss-sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="<?php echo url('/seller/dashboard.php'); ?>" class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <div class="nav-section">Inventory & Products</div>
            <a href="<?php echo url('/seller/products.php'); ?>" class="nav-link <?php echo $current_page === 'products' ? 'active' : ''; ?>">
                <i class="bi bi-box-seam"></i> Products
            </a>
            <a href="<?php echo url('/seller/add-product.php'); ?>" class="nav-link <?php echo $current_page === 'add-product' ? 'active' : ''; ?>">
                <i class="bi bi-plus-circle"></i> Add Product
            </a>
            <a href="<?php echo url('/seller/inventory.php'); ?>" class="nav-link <?php echo $current_page === 'inventory' ? 'active' : ''; ?>">
                <i class="bi bi-stack"></i> Stock Levels
            </a>

            <div class="nav-section">Sales & Orders</div>
            <a href="<?php echo url('/seller/orders.php'); ?>" class="nav-link <?php echo $current_page === 'orders' ? 'active' : ''; ?>">
                <i class="bi bi-bag-check"></i> Orders
            </a>
            <a href="<?php echo url('/seller/returns.php'); ?>" class="nav-link <?php echo $current_page === 'returns' ? 'active' : ''; ?>">
                <i class="bi bi-arrow-return-left"></i> Returns
            </a>
            <a href="<?php echo url('/seller/reviews.php'); ?>" class="nav-link <?php echo $current_page === 'reviews' ? 'active' : ''; ?>">
                <i class="bi bi-star"></i> Reviews
            </a>
            <a href="<?php echo url('/seller/coupons.php'); ?>" class="nav-link <?php echo $current_page === 'coupons' ? 'active' : ''; ?>">
                <i class="bi bi-ticket-perforated"></i> Coupons
            </a>

            <div class="nav-section">Finances</div>
            <a href="<?php echo url('/seller/earnings.php'); ?>" class="nav-link <?php echo $current_page === 'earnings' ? 'active' : ''; ?>">
                <i class="bi bi-cash-stack"></i> Earnings
            </a>
            <a href="<?php echo url('/seller/payouts.php'); ?>" class="nav-link <?php echo $current_page === 'payouts' ? 'active' : ''; ?>">
                <i class="bi bi-wallet2"></i> Payouts
            </a>
            <a href="<?php echo url('/seller/analytics.php'); ?>" class="nav-link <?php echo $current_page === 'analytics' ? 'active' : ''; ?>">
                <i class="bi bi-graph-up"></i> Analytics
            </a>

            <div class="nav-section">Settings</div>
            <a href="<?php echo url('/seller/store-settings.php'); ?>" class="nav-link <?php echo $current_page === 'store-settings' ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i> Store Settings
            </a>
            <a href="<?php echo url('/seller/profile.php'); ?>" class="nav-link <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                <i class="bi bi-person"></i> My Account
            </a>
            <a href="<?php echo url('/seller/notifications.php'); ?>" class="nav-link <?php echo $current_page === 'notifications' ? 'active' : ''; ?>">
                <i class="bi bi-bell"></i> Notifications
            </a>
            <a href="<?php echo url('/'); ?>" target="_blank" class="nav-link">
                <i class="bi bi-box-arrow-up-right"></i> View Marketplace
            </a>
            <a href="<?php echo url('/logout.php'); ?>" class="nav-link text-danger mt-3">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="ss-main-content">
        <!-- Top Navbar -->
        <header class="ss-topbar">
            <div class="ss-topbar-left">
                <button type="button" class="ss-sidebar-toggle"><i class="bi bi-list"></i></button>
                <h1 class="ss-page-title"><?php echo e($page_title ?? 'Seller Portal'); ?></h1>
            </div>
            <div class="ss-topbar-right">
                <span class="badge <?php echo ($seller['status'] ?? '') === 'approved' ? 'bg-success' : 'bg-warning text-dark'; ?> px-3 py-2">
                    Status: <?php echo ucfirst($seller['status'] ?? 'Pending'); ?>
                </span>
                <span class="text-ss-muted small d-none d-md-inline">
                    <?php echo e($seller['store_name'] ?? 'My Store'); ?>
                </span>
            </div>
        </header>

        <div class="ss-dashboard-content">
            <?php 
            $flash_success = get_flash('success');
            $flash_error = get_flash('error');
            if ($flash_success): ?>
                <div class="ss-alert ss-alert-success"><i class="bi bi-check-circle"></i> <?php echo e($flash_success); ?></div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="ss-alert ss-alert-danger"><i class="bi bi-exclamation-triangle"></i> <?php echo e($flash_error); ?></div>
            <?php endif; ?>
