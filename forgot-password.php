<?php
/**
 * ShopSphere - Password Recovery Request Page
 *
 * Allows users to request time-sensitive password recovery tokens via email,
 * displaying simulated recovery links in development environments.
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

$email = '';
$errors = [];
$successMessage = '';
$debugResetUrl = null;
$isDebug = defined('APP_DEBUG') && APP_DEBUG === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $errors['csrf'] = 'Your session expired or invalid security token. Please try again.';
    } else {
        $email = sanitize_string($_POST['email'] ?? '');

        if (!validate_required($email)) {
            $errors['email'] = 'Email address is required.';
        } elseif (!validate_email($email)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if (empty($errors)) {
            $authService = new AuthService();
            $user = $authService->getUserByEmail($email);

            if ($user && $user['status'] !== 'suspended') {
                $resetToken = $authService->createPasswordResetToken((int) $user['id']);
                
                // In development mode, provide the direct link since SMTP may not be configured
                if ($isDebug) {
                    $debugResetUrl = url('reset-password.php?token=' . urlencode($resetToken));
                }
            }

            // Always display generic message to avoid email enumeration
            $successMessage = 'If an account exists with that email, a password reset link has been sent.';
        }
    }
}

$pageTitle = 'Forgot Password | ShopSphere';
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

            <h2>Reset Password</h2>
            <p class="ss-auth-subtitle">Enter your registered email address and we'll send you instructions to reset your password.</p>

            <?php if (!empty($errors['csrf'])): ?>
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <div><?= e($errors['csrf']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMessage)): ?>
                <div class="ss-alert ss-alert-success" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?= e($successMessage) ?></div>
                </div>

                <?php if ($isDebug && !empty($debugResetUrl)): ?>
                    <div class="alert alert-warning border border-warning my-3" role="alert">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-cone-striped fs-5 text-warning"></i>
                            <strong class="text-dark">Development Mode (APP_DEBUG=true)</strong>
                        </div>
                        <p class="small text-muted mb-2">SMTP email delivery is bypassed in development. Use the link below to reset the password directly:</p>
                        <a href="<?= e($debugResetUrl) ?>" class="btn btn-sm btn-ss-primary text-break w-100">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Open Password Reset Link
                        </a>
                    </div>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <a href="<?= url('login.php') ?>" class="btn btn-ss-outline w-100 py-2">
                        <i class="bi bi-arrow-left me-1"></i> Back to Sign In
                    </a>
                </div>
            <?php else: ?>
                <!-- Password Reset Request Form -->
                <form action="<?= url('forgot-password.php') ?>" method="POST" novalidate>
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

                    <button type="submit" class="btn btn-ss-primary w-100 py-2 mt-2">
                        <i class="bi bi-send me-1"></i> Send Reset Link
                    </button>
                </form>

                <div class="ss-auth-footer">
                    Remember your password? 
                    <a href="<?= url('login.php') ?>" class="fw-semibold text-ss-accent text-decoration-none">Back to Sign In</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
