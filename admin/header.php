<?php
/**
 * ShopSphere - Admin Control Panel Layout Header
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/helpers.php';

// Strictly require administrator authentication
require_role('admin');

$admin_id = get_current_user_id();
$db = get_db();
$admin = current_user();

$current_page = $current_page ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo generate_csrf_token(); ?>">
    <title><?php echo e($page_title ?? 'Admin Console'); ?> — ShopSphere Control Panel</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com">
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
    <div class="ss-sidebar-overlay"></div>

    <!-- Admin Sidebar -->
    <aside class="ss-sidebar">
        <div class="ss-sidebar-header">
            <a href="<?php echo url('/admin/dashboard.php'); ?>" class="ss-logo">
                <div class="ss-logo-icon bg-danger"><i class="bi bi-shield-lock"></i></div>
                <span>Admin</span>Core
            </a>
        </div>
        <div class="ss-sidebar-nav">
            <div class="nav-section">Overview</div>
            <a href="<?php echo url('/admin/dashboard.php'); ?>" class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="<?php echo url('/admin/reports.php'); ?>" class="nav-link <?php echo $current_page === 'reports' ? 'active' : ''; ?>">
                <i class="bi bi-bar-chart-line"></i> Financial Reports
            </a>

            <div class="nav-section">Marketplace Users</div>
            <a href="<?php echo url('/admin/customers.php'); ?>" class="nav-link <?php echo $current_page === 'customers' ? 'active' : ''; ?>">
                <i class="bi bi-people"></i> Customers
            </a>
            <a href="<?php echo url('/admin/sellers.php'); ?>" class="nav-link <?php echo $current_page === 'sellers' ? 'active' : ''; ?>">
                <i class="bi bi-shop"></i> Sellers & Stores
            </a>

            <div class="nav-section">Catalog & Orders</div>
            <a href="<?php echo url('/admin/categories.php'); ?>" class="nav-link <?php echo $current_page === 'categories' ? 'active' : ''; ?>">
                <i class="bi bi-tags"></i> Categories
            </a>
            <a href="<?php echo url('/admin/products.php'); ?>" class="nav-link <?php echo $current_page === 'products' ? 'active' : ''; ?>">
                <i class="bi bi-box-seam"></i> Products Oversight
            </a>
            <a href="<?php echo url('/admin/orders.php'); ?>" class="nav-link <?php echo $current_page === 'orders' ? 'active' : ''; ?>">
                <i class="bi bi-bag-check"></i> Orders & Packages
            </a>
            <a href="<?php echo url('/admin/inventory.php'); ?>" class="nav-link <?php echo $current_page === 'inventory' ? 'active' : ''; ?>">
                <i class="bi bi-stack"></i> Stock Oversight
            </a>
            <a href="<?php echo url('/admin/reviews.php'); ?>" class="nav-link <?php echo $current_page === 'reviews' ? 'active' : ''; ?>">
                <i class="bi bi-star"></i> Reviews Moderation
            </a>

            <div class="nav-section">Finance & Rules</div>
            <a href="<?php echo url('/admin/commissions.php'); ?>" class="nav-link <?php echo $current_page === 'commissions' ? 'active' : ''; ?>">
                <i class="bi bi-percent"></i> Commission Rules
            </a>
            <a href="<?php echo url('/admin/payouts.php'); ?>" class="nav-link <?php echo $current_page === 'payouts' ? 'active' : ''; ?>">
                <i class="bi bi-cash-coin"></i> Seller Payouts
            </a>
            <a href="<?php echo url('/admin/coupons.php'); ?>" class="nav-link <?php echo $current_page === 'coupons' ? 'active' : ''; ?>">
                <i class="bi bi-ticket-perforated"></i> Promo Coupons
            </a>

            <div class="nav-section">CMS & Settings</div>
            <a href="<?php echo url('/admin/banners.php'); ?>" class="nav-link <?php echo $current_page === 'banners' ? 'active' : ''; ?>">
                <i class="bi bi-image"></i> Banners CMS
            </a>
            <a href="<?php echo url('/admin/contact-messages.php'); ?>" class="nav-link <?php echo $current_page === 'messages' ? 'active' : ''; ?>">
                <i class="bi bi-envelope"></i> Inquiries
            </a>
            <a href="<?php echo url('/admin/settings.php'); ?>" class="nav-link <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                <i class="bi bi-sliders"></i> Site Settings
            </a>
            <a href="<?php echo url('/admin/activity-logs.php'); ?>" class="nav-link <?php echo $current_page === 'logs' ? 'active' : ''; ?>">
                <i class="bi bi-journal-text"></i> Audit Logs
            </a>

            <a href="<?php echo url('/'); ?>" target="_blank" class="nav-link mt-3">
                <i class="bi bi-box-arrow-up-right"></i> Open Storefront
            </a>
            <a href="<?php echo url('/logout.php'); ?>" class="nav-link text-danger">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="ss-main-content">
        <header class="ss-topbar">
            <div class="ss-topbar-left">
                <button type="button" class="ss-sidebar-toggle"><i class="bi bi-list"></i></button>
                <h1 class="ss-page-title"><?php echo e($page_title ?? 'Admin Dashboard'); ?></h1>
            </div>
            <div class="ss-topbar-right">
                <span class="badge bg-danger px-3 py-2"><i class="bi bi-shield-check"></i> Super Administrator</span>
                <span class="small text-ss-muted"><?php echo e($admin['first_name'] ?? 'Admin'); ?></span>
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
