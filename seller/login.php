<?php
/**
 * ShopSphere - Seller Portal Login Page
 *
 * Dedicated sign-in portal for marketplace vendors and administrators,
 * enforcing role-based verification before granting dashboard access.
 *
 * @package ShopSphere\Seller
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/classes/AuthService.php';

// Redirect if already logged in with seller/admin role
if (is_logged_in()) {
    $currentRole = get_current_user_role();
    if ($currentRole === 'seller') {
        redirect(url('seller/dashboard.php'));
    } elseif ($currentRole === 'admin') {
        redirect(url('admin/dashboard.php'));
    } else {
        // Customer is logged in but trying to access seller portal
        flash('error', 'You are currently logged in as a customer. Please sign in with a registered vendor account.');
        // Don't auto-redirect, allow them to switch accounts or proceed
    }
}

$errors = [];
$email = '';
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Check
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Your session expired or invalid security token. Please try again.';
    } else {
        $email = sanitize_string($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        // Validation
        if (!validate_required($email)) {
            $errors['email'] = 'Email address is required.';
        } elseif (!validate_email($email)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if (!validate_required($password)) {
            $errors['password'] = 'Password is required.';
        }

        if (empty($errors)) {
            $authService = new AuthService();
            $user = $authService->login($email, $password);

            if ($user !== false) {
                // Role enforcement: only seller and admin permitted
                if (!in_array($user['role'], ['seller', 'admin'], true)) {
                    $generalError = 'Access denied. This portal is reserved for registered sellers and marketplace administrators.';
                } else {
                    // Check vendor profile status if role is seller
                    if ($user['role'] === 'seller') {
                        $sellerProfile = $user['seller_profile'] ?? null;
                        $sellerStatus = $sellerProfile['status'] ?? 'pending';

                        if ($sellerStatus === 'pending') {
                            $generalError = 'Your seller application is currently pending approval by the marketplace administration. You will be notified once reviewed.';
                        } elseif ($sellerStatus === 'rejected') {
                            $reason = !empty($sellerProfile['rejection_reason']) ? ' Reason: ' . $sellerProfile['rejection_reason'] : '';
                            $generalError = 'Your seller application was declined.' . $reason;
                        } elseif ($sellerStatus === 'suspended') {
                            $reason = !empty($sellerProfile['suspension_reason']) ? ' Reason: ' . $sellerProfile['suspension_reason'] : '';
                            $generalError = 'Your vendor account is currently suspended.' . $reason;
                        } else {
                            // Approved seller
                            login_user($user);
                            flash('success', 'Welcome to your Seller Dashboard!');
                            redirect(url('seller/dashboard.php'));
                        }
                    } else {
                        // Admin user logging in via seller portal
                        login_user($user);
                        flash('success', 'Welcome back, Administrator!');
                        redirect(url('admin/dashboard.php'));
                    }
                }
            } else {
                $generalError = 'Invalid email or password.';
            }
        }
    }
}

$pageTitle = 'Seller Portal Sign In | ShopSphere';
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
        <div class="ss-auth-card">
            <!-- Brand Logo & Seller Badge -->
            <div class="ss-auth-logo">
                <a href="<?= url('/') ?>" class="ss-logo d-inline-flex text-decoration-none justify-content-center">
                    <div class="ss-logo-icon bg-warning text-dark">
                        <i class="bi bi-shop"></i>
                    </div>
                    <span>Shop</span>Sphere
                </a>
                <div class="mt-2">
                    <span class="badge bg-primary-subtle text-primary border px-2 py-1 small fw-semibold">
                        <i class="bi bi-shield-check me-1"></i> Vendor & Merchant Center
                    </span>
                </div>
            </div>

            <h2>Merchant Sign In</h2>
            <p class="ss-auth-subtitle">Manage your catalog, fulfill customer orders, and track your business earnings</p>

            <!-- Alerts -->
            <?php if ($successFlash = get_flash('success')): ?>
                <div class="ss-alert ss-alert-success" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?= e($successFlash) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($errorFlash = get_flash('error')): ?>
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <div><?= e($errorFlash) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($generalError)): ?>
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <div><?= e($generalError) ?></div>
                </div>
            <?php endif; ?>

            <!-- Seller Login Form -->
            <form action="<?= url('seller/login.php') ?>" method="POST" novalidate>
                <?= csrf_field() ?>

                <div class="ss-form-group">
                    <label for="email">Seller Email Address <span class="required">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               class="form-control ss-form-control border-start-0 <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                               placeholder="merchant@example.com" 
                               value="<?= e($email) ?>" 
                               required 
                               autocomplete="email" 
                               autofocus>
                    </div>
                    <?php if (isset($errors['email'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="ss-form-group">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="mb-0">Password <span class="required">*</span></label>
                        <a href="<?= url('forgot-password.php') ?>" class="small text-ss-accent text-decoration-none">Forgot password?</a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                               placeholder="Enter your password" 
                               required 
                               autocomplete="current-password">
                        <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted" id="togglePasswordBtn">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-ss-primary w-100 py-2 mt-2">
                    <i class="bi bi-speedometer2 me-1"></i> Access Seller Dashboard
                </button>
            </form>

            <div class="ss-auth-divider">New to selling on ShopSphere?</div>

            <div class="text-center">
                <a href="<?= url('seller/register.php') ?>" class="btn btn-ss-outline w-100 py-2">
                    <i class="bi bi-shop-window me-1"></i> Apply for a Seller Account
                </a>
            </div>

            <!-- Footer Links -->
            <div class="ss-auth-footer">
                Looking to shop? 
                <a href="<?= url('login.php') ?>" class="fw-semibold text-ss-accent text-decoration-none">Customer Sign In</a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#togglePasswordBtn').on('click', function() {
                var passwordField = $('#password');
                var icon = $('#togglePasswordIcon');
                var isPassword = passwordField.attr('type') === 'password';
                
                passwordField.attr('type', isPassword ? 'text' : 'password');
                icon.toggleClass('bi-eye bi-eye-slash');
            });
        });
    </script>
</body>
</html>
