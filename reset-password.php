<?php
/**
 * ShopSphere - Reset Password Page
 *
 * Verifies time-sensitive password recovery tokens and allows users
 * to securely define a new account password.
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

// Redirect if already logged in
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

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$authService = new AuthService();
$tokenData = $token !== '' ? $authService->validateResetToken($token) : false;
$isTokenValid = ($tokenData !== false);

$errors = [];
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isTokenValid) {
        $generalError = 'This password reset link is invalid or has expired. Please request a new one.';
    } else {
        // Validate CSRF
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validate_csrf_token(is_string($csrfToken) ? $csrfToken : null)) {
            $generalError = 'Your session expired or invalid security token. Please try again.';
        } else {
            $password        = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            // Password strength validation
            if (!validate_required($password)) {
                $errors['password'] = 'New password is required.';
            } elseif (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters long.';
            } elseif (!validate_password_strength($password)) {
                $errors['password'] = 'Password must include uppercase, lowercase, and numeric characters.';
            }

            // Confirm password
            if (!validate_required($confirmPassword)) {
                $errors['confirm_password'] = 'Please confirm your new password.';
            } elseif ($password !== $confirmPassword) {
                $errors['confirm_password'] = 'Passwords do not match.';
            }

            if (empty($errors)) {
                $resetSuccess = $authService->resetPassword($token, $password);

                if ($resetSuccess) {
                    flash('success', 'Your password has been reset successfully. Please sign in with your new password.');
                    redirect(url('login.php'));
                } else {
                    $generalError = 'Failed to reset password. The reset link may have expired. Please request a new one.';
                    $isTokenValid = false;
                }
            }
        }
    }
}

$pageTitle = 'Choose New Password | ShopSphere';
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

            <?php if ($isTokenValid): ?>
                <h2>Create New Password</h2>
                <p class="ss-auth-subtitle">Please create a strong password containing letters, numbers, and symbols.</p>

                <?php if (!empty($generalError)): ?>
                    <div class="ss-alert ss-alert-danger" role="alert">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                        <div><?= e($generalError) ?></div>
                    </div>
                <?php endif; ?>

                <form action="<?= url('reset-password.php?token=' . urlencode($token)) ?>" method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <div class="ss-form-group">
                        <label for="password">New Password <span class="required">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                   placeholder="Minimum 8 characters" 
                                   required 
                                   autocomplete="new-password" 
                                   autofocus>
                            <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted toggle-pwd-btn" data-target="#password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1" style="font-size:0.75rem;">Minimum 8 characters with uppercase, lowercase, and numbers.</small>
                        <?php if (isset($errors['password'])): ?>
                            <div class="ss-invalid-feedback d-block"><?= e($errors['password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="ss-form-group">
                        <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-shield-check"></i></span>
                            <input type="password" 
                                   id="confirm_password" 
                                   name="confirm_password" 
                                   class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                   placeholder="Re-enter your new password" 
                                   required 
                                   autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted toggle-pwd-btn" data-target="#confirm_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['confirm_password'])): ?>
                            <div class="ss-invalid-feedback d-block"><?= e($errors['confirm_password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100 py-2 mt-2">
                        <i class="bi bi-check2-circle me-1"></i> Update Password
                    </button>
                </form>

            <?php else: ?>
                <!-- Invalid or Expired Token State -->
                <div class="text-center py-2">
                    <div class="ss-empty-state p-0 mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-danger fs-1"></i>
                    </div>
                    <h2 class="text-danger">Invalid or Expired Link</h2>
                    <p class="text-muted">
                        This password reset link is invalid, has already been used, or has expired. Password reset links are time-sensitive for your security.
                    </p>

                    <div class="d-grid gap-2 mt-4">
                        <a href="<?= url('forgot-password.php') ?>" class="btn btn-ss-primary py-2">
                            <i class="bi bi-arrow-repeat me-1"></i> Request a New Reset Link
                        </a>
                        <a href="<?= url('login.php') ?>" class="btn btn-ss-outline py-2">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Back to Sign In
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ss-auth-footer">
                Need assistance? <a href="<?= url('contact.php') ?>" class="text-ss-accent text-decoration-none">Contact Support</a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.toggle-pwd-btn').on('click', function() {
                var targetId = $(this).data('target');
                var field = $(targetId);
                var icon = $(this).find('i');
                var isPassword = field.attr('type') === 'password';

                field.attr('type', isPassword ? 'text' : 'password');
                icon.toggleClass('bi-eye bi-eye-slash');
            });
        });
    </script>
</body>
</html>
