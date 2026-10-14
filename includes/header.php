<?php
/**
 * ShopSphere - Main Site Header Include
 *
 * Provides the global HTML head, meta definitions, navigation headers,
 * announcement bar, search, dynamic counts, and flash alert messages.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

// Require essential configuration and helper files
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/security.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

// Safe retrieval of site configuration settings with try-catch
$site_name = defined('APP_NAME') ? APP_NAME : 'ShopSphere';
$announcement_text = '';
$site_logo = '';
$contact_phone = '+92 300 1234567';
$contact_email = 'support@shopsphere.pk';

try {
    $settings = get_settings_batch(['site_name', 'announcement_text', 'logo', 'site_logo', 'contact_phone', 'contact_email']);
    if (!empty($settings['site_name'])) {
        $site_name = (string) $settings['site_name'];
    }
    if (!empty($settings['announcement_text'])) {
        $announcement_text = (string) $settings['announcement_text'];
    } else {
        $announcement_text = (string) get_setting('announcement_text', '');
    }
    $site_logo = (string) ($settings['site_logo'] ?? $settings['logo'] ?? get_setting('logo', ''));
    if (!empty($settings['contact_phone'])) {
        $contact_phone = (string) $settings['contact_phone'];
    }
    if (!empty($settings['contact_email'])) {
        $contact_email = (string) $settings['contact_email'];
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Header] Settings load error: ' . $e->getMessage());
}

// Page title setup
$page_title = isset($page_title) && trim((string) $page_title) !== '' ? trim((string) $page_title) : $site_name;
$doc_title = ($page_title !== $site_name && !str_contains($page_title, $site_name)) 
    ? $page_title . ' | ' . $site_name 
    : $page_title;

// Dynamic header counts for cart, wishlist, and unread notifications
$cart_count = 0;
$wishlist_count = 0;
$unread_notifications_count = 0;

try {
    $pdo = get_db_connection();
    $currentUserId = get_current_user_id();

    if ($currentUserId !== null) {
        // 1. Wishlist count
        try {
            $stmtWish = $pdo->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = :uid");
            $stmtWish->execute(['uid' => $currentUserId]);
            $wishlist_count = (int) $stmtWish->fetchColumn();
        } catch (PDOException) {
            try {
                $stmtWish = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE user_id = :uid");
                $stmtWish->execute(['uid' => $currentUserId]);
                $wishlist_count = (int) $stmtWish->fetchColumn();
            } catch (Throwable) {
                $wishlist_count = 0;
            }
        }

        // 2. Unread notification count
        try {
            $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND (is_read = 0 OR is_read IS FALSE)");
            $stmtNotif->execute(['uid' => $currentUserId]);
            $unread_notifications_count = (int) $stmtNotif->fetchColumn();
        } catch (Throwable) {
            $unread_notifications_count = 0;
        }

        // 3. Cart count for logged-in user
        try {
            $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE user_id = :uid");
            $stmtCart->execute(['uid' => $currentUserId]);
            $cart_count = (int) $stmtCart->fetchColumn();
        } catch (PDOException) {
            try {
                $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(ci.quantity), 0) FROM cart_items ci INNER JOIN carts c ON ci.cart_id = c.id WHERE c.user_id = :uid");
                $stmtCart->execute(['uid' => $currentUserId]);
                $cart_count = (int) $stmtCart->fetchColumn();
            } catch (PDOException) {
                try {
                    $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = :uid");
                    $stmtCart->execute(['uid' => $currentUserId]);
                    $cart_count = (int) $stmtCart->fetchColumn();
                } catch (Throwable) {
                    $cart_count = 0;
                }
            }
        }
    } else {
        // Guest user cart count from session
        if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $item) {
                $cart_count += isset($item['quantity']) ? (int) $item['quantity'] : 1;
            }
        }
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Header] Database counts error: ' . $e->getMessage());
}

// Active navigation categories (First 8 active categories, sorted by sort_order)
$nav_categories = [];
try {
    $pdo = get_db_connection();
    try {
        $stmtCat = $pdo->query("SELECT id, name, slug, icon, image FROM categories WHERE (status = 'active' OR is_active = 1) ORDER BY sort_order ASC, name ASC LIMIT 8");
        $nav_categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        try {
            $stmtCat = $pdo->query("SELECT id, name, slug FROM categories ORDER BY sort_order ASC, id ASC LIMIT 8");
            $nav_categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            $stmtCat = $pdo->query("SELECT id, name, slug FROM categories LIMIT 8");
            $nav_categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    error_log('[ShopSphere Header] Categories query error: ' . $e->getMessage());
    $nav_categories = [];
}

// Fallback categories if database table has no records or DB is offline
if (empty($nav_categories)) {
    $nav_categories = [
        ['name' => 'Electronics', 'slug' => 'electronics', 'icon' => 'bi-laptop'],
        ['name' => 'Fashion', 'slug' => 'fashion', 'icon' => 'bi-handbag'],
        ['name' => 'Home & Living', 'slug' => 'home-living', 'icon' => 'bi-house-heart'],
        ['name' => 'Beauty & Care', 'slug' => 'beauty-care', 'icon' => 'bi-stars'],
        ['name' => 'Sports & Outdoors', 'slug' => 'sports-outdoors', 'icon' => 'bi-bicycle'],
        ['name' => 'Books & Stationery', 'slug' => 'books-stationery', 'icon' => 'bi-book'],
        ['name' => 'Groceries', 'slug' => 'groceries', 'icon' => 'bi-basket'],
        ['name' => 'Toys & Baby', 'slug' => 'toys-baby', 'icon' => 'bi-controller'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= e($doc_title) ?></title>

    <!-- Meta Tags -->
    <meta name="description" content="<?= e(get_setting('meta_description', 'ShopSphere - Premium Multi-Vendor E-Commerce Marketplace in Pakistan')) ?>">
    <meta name="csrf-token" content="<?= e(generate_csrf_token()) ?>">

    <!-- Google Fonts: Inter & Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Bootstrap Icons CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Custom ShopSphere Stylesheet -->
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">

    <!-- Global Application JS Variables -->
    <script>
        const APP_URL = "<?= e(defined('APP_URL') ? APP_URL : '') ?>";
        const CURRENCY_SYMBOL = "<?= e(defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'Rs.') ?>";
    </script>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Announcement Bar -->
    <?php if (!empty($announcement_text)): ?>
        <aside class="ss-announcement-bar" role="complementary" aria-label="Announcement">
            <div class="container text-center">
                <span><?= e($announcement_text) ?></span>
            </div>
        </aside>
    <?php endif; ?>

    <!-- Top Header Bar -->
    <div class="ss-top-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="ss-top-header-left d-flex align-items-center gap-3">
                    <?php if (!empty($contact_phone)): ?>
                        <a href="tel:<?= e($contact_phone) ?>" class="d-inline-flex align-items-center">
                            <i class="bi bi-telephone"></i>
                            <span><?= e($contact_phone) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($contact_email)): ?>
                        <a href="mailto:<?= e($contact_email) ?>" class="d-inline-flex align-items-center">
                            <i class="bi bi-envelope"></i>
                            <span><?= e($contact_email) ?></span>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="ss-top-header-right d-flex align-items-center gap-3">
                    <?php if (is_logged_in() && is_seller()): ?>
                        <a href="<?= url('seller/dashboard.php') ?>" class="d-inline-flex align-items-center">
                            <i class="bi bi-shop"></i>
                            <span>Seller Dashboard</span>
                        </a>
                    <?php else: ?>
                        <a href="<?= url('become-seller.php') ?>" class="d-inline-flex align-items-center">
                            <i class="bi bi-shop"></i>
                            <span>Become a Seller</span>
                        </a>
                    <?php endif; ?>
                    <a href="<?= url('track-order.php') ?>" class="d-inline-flex align-items-center">
                        <i class="bi bi-truck"></i>
                        <span>Track Order</span>
                    </a>
                    <a href="<?= url('help.php') ?>" class="d-inline-flex align-items-center">
                        <i class="bi bi-question-circle"></i>
                        <span>Help & Support</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Header -->
    <header class="ss-main-header">
        <div class="container">
            <div class="row align-items-center gy-2">
                <!-- Logo -->
                <div class="col-6 col-md-3">
                    <a href="<?= url('/') ?>" class="ss-logo">
                        <?php if (!empty($site_logo)): ?>
                            <img src="<?= e(asset($site_logo)) ?>" alt="<?= e($site_name) ?>" style="max-height: 40px; width: auto;">
                        <?php else: ?>
                            <span class="ss-logo-icon">
                                <i class="bi bi-bag-check-fill"></i>
                            </span>
                            <span><?= e($site_name) ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Search Bar -->
                <div class="col-12 col-md-5 col-lg-6 order-3 order-md-2">
                    <form action="<?= url('search.php') ?>" method="GET" class="ss-search-form mx-auto" role="search">
                        <input type="search" name="q" class="ss-search-input" placeholder="Search for products, categories, or brands..." value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off" aria-label="Search query" required>
                        <button type="submit" aria-label="Submit search">
                            <i class="bi bi-search"></i>
                        </button>
                        <div class="ss-search-dropdown" style="display: none;"></div>
                    </form>
                </div>

                <!-- Header Action Icons -->
                <div class="col-6 col-md-4 col-lg-3 order-2 order-md-3">
                    <div class="ss-header-icons justify-content-end">
                        <!-- Wishlist Icon -->
                        <a href="<?= url(is_logged_in() ? 'account/wishlist.php' : 'login.php') ?>" class="ss-header-icon" title="Wishlist" aria-label="View Wishlist">
                            <i class="bi bi-heart"></i>
                            <?php if (is_logged_in()): ?>
                                <span class="badge ss-wishlist-count" style="<?= $wishlist_count > 0 ? '' : 'display: none;' ?>">
                                    <?= $wishlist_count ?>
                                </span>
                            <?php endif; ?>
                            <span class="ss-header-icon-label">Wishlist</span>
                        </a>

                        <!-- Cart Icon -->
                        <a href="<?= url('cart.php') ?>" class="ss-header-icon" title="Cart" aria-label="View Shopping Cart">
                            <i class="bi bi-cart3"></i>
                            <span class="badge ss-cart-count" style="<?= $cart_count > 0 ? '' : 'display: none;' ?>">
                                <?= $cart_count ?>
                            </span>
                            <span class="ss-header-icon-label">Cart</span>
                        </a>

                        <!-- Notifications (Logged in users) -->
                        <?php if (is_logged_in()): ?>
                            <div class="dropdown">
                                <a href="<?= url('account/notifications.php') ?>" class="ss-header-icon dropdown-toggle text-decoration-none" id="notifDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications" aria-label="View Notifications">
                                    <i class="bi bi-bell"></i>
                                    <span class="badge ss-notification-count" style="<?= $unread_notifications_count > 0 ? '' : 'display: none;' ?>">
                                        <?= $unread_notifications_count ?>
                                    </span>
                                    <span class="ss-header-icon-label">Alerts</span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="notifDropdown">
                                    <li><h6 class="dropdown-header">Notifications (<?= $unread_notifications_count ?> unread)</h6></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('account/notifications.php') ?>">
                                            <i class="bi bi-bell-fill text-ss-accent me-2"></i> View All Notifications
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- User Account Dropdown -->
                        <?php if (is_logged_in()): ?>
                            <div class="dropdown">
                                <a href="#" class="ss-header-icon dropdown-toggle text-decoration-none" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="User Account Menu">
                                    <i class="bi bi-person-circle"></i>
                                    <span class="ss-header-icon-label"><?= e(truncate($_SESSION['user_name'] ?? 'Account', 10)) ?></span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userMenuDropdown">
                                    <li>
                                        <div class="px-3 py-2 border-bottom">
                                            <div class="fw-semibold text-truncate" style="max-width: 180px;"><?= e($_SESSION['user_name'] ?? 'User') ?></div>
                                            <small class="text-muted text-truncate d-block" style="max-width: 180px;"><?= e($_SESSION['user_email'] ?? '') ?></small>
                                            <span class="badge bg-primary text-uppercase mt-1" style="font-size: 0.65rem;"><?= e(get_current_user_role() ?? 'Customer') ?></span>
                                        </div>
                                    </li>
                                    <?php if (is_admin()): ?>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center" href="<?= url('admin/dashboard.php') ?>">
                                                <i class="bi bi-speedometer2 text-danger me-2"></i> Admin Panel
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (is_seller()): ?>
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center" href="<?= url('seller/dashboard.php') ?>">
                                                <i class="bi bi-shop text-primary me-2"></i> Seller Dashboard
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('account/index.php') ?>">
                                            <i class="bi bi-person me-2"></i> My Profile
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('account/orders.php') ?>">
                                            <i class="bi bi-box-seam me-2"></i> My Orders
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('account/wishlist.php') ?>">
                                            <i class="bi bi-heart me-2"></i> My Wishlist
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('account/settings.php') ?>">
                                            <i class="bi bi-gear me-2"></i> Settings
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form action="<?= url('logout.php') ?>" method="POST" class="m-0 p-0">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="dropdown-item d-flex align-items-center text-danger border-0 bg-transparent w-100">
                                                <i class="bi bi-box-arrow-right me-2"></i> Logout
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <div class="dropdown">
                                <a href="#" class="ss-header-icon dropdown-toggle text-decoration-none" id="guestMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account Login Menu">
                                    <i class="bi bi-person"></i>
                                    <span class="ss-header-icon-label">Sign In</span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="guestMenuDropdown">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('login.php') ?>">
                                            <i class="bi bi-box-arrow-in-right text-primary me-2"></i> Sign In
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('register.php') ?>">
                                            <i class="bi bi-person-plus text-success me-2"></i> Create Account
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('become-seller.php') ?>">
                                            <i class="bi bi-shop text-info me-2"></i> Sell on ShopSphere
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center" href="<?= url('help.php') ?>">
                                            <i class="bi bi-question-circle me-2"></i> Help Center
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Category Navigation Bar -->
    <nav class="ss-category-nav" aria-label="Categories Navigation">
        <div class="container">
            <div class="d-flex align-items-center">
                <a href="<?= url('categories.php') ?>" class="nav-link ss-all-categories-btn me-2">
                    <i class="bi bi-grid-fill me-2"></i> All Categories
                </a>
                <div class="d-flex flex-row overflow-x-auto flex-grow-1">
                    <?php foreach ($nav_categories as $cat): ?>
                        <a href="<?= url('category.php?slug=' . urlencode((string) ($cat['slug'] ?? ''))) ?>" class="nav-link">
                            <?php if (!empty($cat['icon'])): ?>
                                <i class="bi <?= e($cat['icon']) ?>"></i>
                            <?php endif; ?>
                            <span><?= e($cat['name'] ?? '') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="ms-auto d-none d-xl-flex align-items-center">
                    <a href="<?= url('deals.php') ?>" class="nav-link text-danger fw-semibold">
                        <i class="bi bi-lightning-charge-fill me-1"></i> Hot Deals
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Flash Messages Display -->
    <?php
    $flashCategories = [
        'success' => ['class' => 'ss-alert-success', 'icon' => 'bi-check-circle-fill'],
        'error'   => ['class' => 'ss-alert-danger',  'icon' => 'bi-exclamation-circle-fill'],
        'danger'  => ['class' => 'ss-alert-danger',  'icon' => 'bi-exclamation-circle-fill'],
        'warning' => ['class' => 'ss-alert-warning', 'icon' => 'bi-exclamation-triangle-fill'],
        'info'    => ['class' => 'ss-alert-info',    'icon' => 'bi-info-circle-fill'],
    ];

    $renderedFlashes = [];
    foreach ($flashCategories as $flashKey => $meta) {
        $message = get_flash($flashKey);
        if ($message !== null && $message !== '') {
            $renderedFlashes[] = [
                'type'    => $flashKey,
                'message' => is_array($message) ? implode('<br>', array_map('e', $message)) : e((string) $message),
                'class'   => $meta['class'],
                'icon'    => $meta['icon'],
            ];
        }
    }
    ?>
    <?php if (!empty($renderedFlashes)): ?>
        <div class="container mt-3 mb-0">
            <?php foreach ($renderedFlashes as $fl): ?>
                <div class="ss-alert <?= $fl['class'] ?>" data-auto-dismiss role="alert">
                    <i class="bi <?= $fl['icon'] ?>"></i>
                    <div class="flex-grow-1"><?= $fl['message'] ?></div>
                    <button type="button" class="btn-close btn-close-sm ss-alert-dismiss" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Main Content Container Start -->
    <main class="flex-grow-1" id="main-content" role="main">
