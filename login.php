<?php
/**
 * ShopSphere - User Login Page
 *
 * Authenticates customers and vendors, establishing secure sessions
 * and redirecting to their respective dashboards or intended destinations.
 *
 * @package ShopSphere\Auth
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/classes/AuthService.php';

// If already logged in, redirect based on user role
if (is_logged_in()) {
    $currentRole = get_current_user_role();
    if ($currentRole === 'admin') {
        redirect(url('admin/dashboard.php'));
    } elseif ($currentRole === 'seller') {
        redirect(url('seller/dashboard.php'));
    } else {
        redirect(url('account.php'));
    }
}

$errors = [];
$email = '';
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Your session expired or invalid security token. Please try again.';
    } else {
        $email = sanitize_string($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        // Basic input validation
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
                // Initialize authenticated session
                login_user($user);

                // Set flash message
                $displayName = !empty($user['first_name']) ? $user['first_name'] : 'back';
                flash('success', "Welcome back, " . htmlspecialchars($displayName) . "!");

                // Check for intended destination URL
                $intendedUrl = $_SESSION['intended_url'] ?? null;
                unset($_SESSION['intended_url']);

                // Route based on role
                if ($user['role'] === 'admin') {
                    redirect(url('admin/dashboard.php'));
                } elseif ($user['role'] === 'seller') {
                    redirect(url('seller/dashboard.php'));
                } else {
                    // Customer redirect
                    if ($intendedUrl && !str_contains($intendedUrl, 'login.php') && !str_contains($intendedUrl, 'register.php')) {
                        redirect($intendedUrl);
                    }
                    redirect(url('account.php'));
                }
            } else {
                // Generic error to prevent account enumeration
                $generalError = 'Invalid email or password.';
            }
        }
    }
}

$pageTitle = 'Sign In | ShopSphere';
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
            <!-- Brand Logo -->
            <div class="ss-auth-logo">
                <a href="<?= url('/') ?>" class="ss-logo d-inline-flex text-decoration-none">
                    <div class="ss-logo-icon">
                        <i class="bi bi-bag-check-fill"></i>
                    </div>
                    <span>Shop</span>Sphere
                </a>
            </div>

            <h2>Welcome Back</h2>
            <p class="ss-auth-subtitle">Sign in to your account to continue shopping or managing orders</p>

            <!-- Flash & Error Notifications -->
            <?php if ($successFlash = get_flash('success')): ?>
                <div class="ss-alert ss-alert-success" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?= e($successFlash) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($infoFlash = get_flash('info')): ?>
                <div class="ss-alert ss-alert-info" role="alert">
                    <i class="bi bi-info-circle-fill"></i>
                    <div><?= e($infoFlash) ?></div>
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

            <!-- Login Form -->
            <form action="<?= url('login.php') ?>" method="POST" novalidate>
                <?= csrf_field() ?>

                <div class="ss-form-group">
                    <label for="email">Email Address <span class="required">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               class="form-control ss-form-control border-start-0 <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                               placeholder="name@example.com" 
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
                        <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted" id="togglePasswordBtn" aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-ss-primary w-100 py-2 mt-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </button>
            </form>

            <div class="ss-auth-divider">or</div>

            <div class="text-center">
                <a href="<?= url('seller/login.php') ?>" class="btn btn-ss-outline w-100 py-2 btn-sm mb-3">
                    <i class="bi bi-shop me-1"></i> Sign In as Vendor / Seller
                </a>
            </div>

            <!-- Footer Links -->
            <div class="ss-auth-footer">
                Don't have an account yet? 
                <a href="<?= url('register.php') ?>" class="fw-semibold text-ss-accent text-decoration-none">Create Account</a>
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
