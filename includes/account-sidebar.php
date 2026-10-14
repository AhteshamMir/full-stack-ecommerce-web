<?php
/**
 * ShopSphere - Customer Account Sidebar Navigation Component
 *
 * Renders consistent navigation links, badges, and user identity
 * across all customer account portal pages.
 *
 * @package ShopSphere\Includes
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$currentUser = current_user();
$userId = get_current_user_id();
$pdo = get_db_connection();

$sidebarPendingOrders = 0;
$sidebarWishlistCount = 0;
$sidebarUnreadNotifs = 0;

if ($userId !== null) {
    // 1. Pending orders count
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid AND order_status IN ('pending', 'confirmed', 'processing')");
        $stmt->execute([':uid' => $userId]);
        $sidebarPendingOrders = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $sidebarPendingOrders = 0;
    }

    // 2. Wishlist count
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM wishlists WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $sidebarWishlistCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM wishlist WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
            $sidebarWishlistCount = (int) $stmt->fetchColumn();
        } catch (Throwable $e2) {
            $sidebarWishlistCount = 0;
        }
    }

    // 3. Unread notifications count
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND (is_read = 0 OR is_read IS FALSE)");
        $stmt->execute([':uid' => $userId]);
        $sidebarUnreadNotifs = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $sidebarUnreadNotifs = 0;
    }
}

$initials = strtoupper(substr($currentUser['first_name'] ?? 'U', 0, 1));
$fullName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
if ($fullName === '') {
    $fullName = $currentUser['name'] ?? 'Customer Account';
}
$userEmail = $currentUser['email'] ?? '';
$activePage = $active_page ?? 'account';
?>
<div class="ss-account-nav shadow-sm mb-4">
    <div class="ss-account-nav-header bg-white">
        <div class="ss-account-avatar">
            <?= e($initials) ?>
        </div>
        <div class="text-truncate" style="max-width: 180px;">
            <h6 class="mb-0 fw-bold text-truncate" title="<?= e($fullName) ?>"><?= e($fullName) ?></h6>
            <small class="text-muted text-truncate d-block" title="<?= e($userEmail) ?>"><?= e($userEmail) ?></small>
            <span class="badge bg-light text-primary border mt-1" style="font-size: 0.65rem;">Customer</span>
        </div>
    </div>
    <div class="list-group list-group-flush">
        <a href="<?= url('account.php') ?>" class="list-group-item <?= $activePage === 'account' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Overview</span>
        </a>
        <a href="<?= url('profile.php') ?>" class="list-group-item <?= $activePage === 'profile' ? 'active' : '' ?>">
            <i class="bi bi-person"></i>
            <span>Profile Details</span>
        </a>
        <a href="<?= url('orders.php') ?>" class="list-group-item <?= $activePage === 'orders' ? 'active' : '' ?>">
            <i class="bi bi-bag"></i>
            <span>My Orders</span>
            <?php if ($sidebarPendingOrders > 0): ?>
                <span class="badge bg-warning text-dark ms-auto"><?= $sidebarPendingOrders ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url('addresses.php') ?>" class="list-group-item <?= $activePage === 'addresses' ? 'active' : '' ?>">
            <i class="bi bi-geo-alt"></i>
            <span>Saved Addresses</span>
        </a>
        <a href="<?= url('wishlist.php') ?>" class="list-group-item <?= $activePage === 'wishlist' ? 'active' : '' ?>">
            <i class="bi bi-heart"></i>
            <span>My Wishlist</span>
            <?php if ($sidebarWishlistCount > 0): ?>
                <span class="badge bg-danger ms-auto"><?= $sidebarWishlistCount ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url('reviews.php') ?>" class="list-group-item <?= $activePage === 'reviews' ? 'active' : '' ?>">
            <i class="bi bi-star"></i>
            <span>My Reviews</span>
        </a>
        <a href="<?= url('notifications.php') ?>" class="list-group-item <?= $activePage === 'notifications' ? 'active' : '' ?>">
            <i class="bi bi-bell"></i>
            <span>Notifications</span>
            <?php if ($sidebarUnreadNotifs > 0): ?>
                <span class="badge bg-primary ms-auto"><?= $sidebarUnreadNotifs ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url('change-password.php') ?>" class="list-group-item <?= $activePage === 'change-password' ? 'active' : '' ?>">
            <i class="bi bi-shield-lock"></i>
            <span>Change Password</span>
        </a>
        <form action="<?= url('logout.php') ?>" method="POST" class="m-0 p-0">
            <?= csrf_field() ?>
            <button type="submit" class="list-group-item text-danger border-0 bg-transparent w-100 text-start">
                <i class="bi bi-box-arrow-right"></i>
                <span>Sign Out</span>
            </button>
        </form>
    </div>
</div>
