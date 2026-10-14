<?php
/**
 * ShopSphere - Email Address Verification
 *
 * Confirms user ownership of their registered email address using
 * one-time verification tokens sent during registration or profile updates.
 *
 * @package ShopSphere\Auth
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/classes/AuthService.php';

$token = trim((string) ($_GET['token'] ?? ''));
$verified = false;
$errorMessage = '';

if ($token === '') {
    $errorMessage = 'No verification token was provided. Please follow the link sent to your email address.';
} else {
    $authService = new AuthService();
    $verified = $authService->verifyEmail($token);

    if ($verified) {
        if (is_logged_in()) {
            flash('success', 'Your email address has been verified successfully!');
            $role = get_current_user_role();
            if ($role === 'seller') {
                redirect(url('seller/dashboard.php'));
            } elseif ($role === 'admin') {
                redirect(url('admin/dashboard.php'));
            } else {
                redirect(url('account.php'));
            }
        } else {
            flash('success', 'Your email address has been verified successfully! Please sign in to your account.');
            redirect(url('login.php'));
        }
    } else {
        $errorMessage = 'This verification link is invalid or has expired. Please log in to request a fresh verification link.';
    }
}

$pageTitle = 'Email Verification | ShopSphere';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- ShopSphere Custom CSS -->
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body class="bg-ss-light">

    <div class="ss-auth-page">
        <div class="ss-auth-card text-center">
            <!-- Brand Logo -->
            <div class="ss-auth-logo">
                <a href="<?= url('/') ?>" class="ss-logo d-inline-flex text-decoration-none">
                    <div class="ss-logo-icon">
                        <i class="bi bi-bag-check-fill"></i>
                    </div>
                    <span>Shop</span>Sphere
                </a>
            </div>

            <div class="ss-empty-state p-0 mb-3">
                <i class="bi bi-x-circle-fill text-danger fs-1"></i>
            </div>
            
            <h2 class="text-danger">Verification Failed</h2>
            <p class="text-muted mt-2">
                <?= e($errorMessage) ?>
            </p>

            <div class="d-grid gap-2 mt-4">
                <a href="<?= url('login.php') ?>" class="btn btn-ss-primary py-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Sign In
                </a>
                <a href="<?= url('/') ?>" class="btn btn-ss-outline py-2">
                    <i class="bi bi-house me-1"></i> Return to Homepage
                </a>
            </div>

            <div class="ss-auth-footer">
                Having trouble? <a href="<?= url('contact.php') ?>" class="text-ss-accent text-decoration-none">Contact Support</a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
