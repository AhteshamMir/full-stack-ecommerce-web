<?php
/**
 * ShopSphere - Administrative Console Sign In
 *
 * Dedicated hardened authentication gateway strictly restricted
 * to authorized system administrators.
 *
 * @package ShopSphere\Admin
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/classes/AuthService.php';

// Redirect if already logged in as administrator
if (is_logged_in() && is_admin()) {
    redirect(url('admin/dashboard.php'));
}

$errors = [];
$email = '';
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Verification
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Security validation failed or session expired. Please refresh and try again.';
    } else {
        $email = sanitize_string($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if (!validate_required($email)) {
            $errors['email'] = 'Administrator email is required.';
        } elseif (!validate_email($email)) {
            $errors['email'] = 'Please enter a valid administrator email address.';
        }

        if (!validate_required($password)) {
            $errors['password'] = 'Administrator password is required.';
        }

        if (empty($errors)) {
            $authService = new AuthService();
            $user = $authService->login($email, $password);

            // Strictest role check: strictly admin role
            if ($user !== false && $user['role'] === 'admin') {
                login_user($user);
                flash('success', 'Authenticated successfully as Administrator.');
                redirect(url('admin/dashboard.php'));
            } else {
                // Return generic error so roles or credential validity are not leaked
                $generalError = 'Invalid email or password.';
            }
        }
    }
}

$pageTitle = 'Admin Console Login | ShopSphere';
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
    <style>
        .ss-admin-auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            padding: 2rem 1rem;
        }
        .ss-admin-card {
            width: 100%;
            max-width: 420px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: var(--ss-radius-lg);
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5), 0 8px 10px -6px rgba(0,0,0,0.5);
            padding: 2.5rem;
            color: #f8fafc;
        }
        .ss-admin-card h2 {
            color: #f8fafc;
            font-size: 1.35rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 0.25rem;
        }
        .ss-admin-subtitle {
            color: #94a3b8;
            font-size: 0.8125rem;
            text-align: center;
            margin-bottom: 1.75rem;
        }
        .ss-admin-card .form-control {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }
        .ss-admin-card .form-control:focus {
            background: #0f172a;
            border-color: var(--ss-accent);
            color: #f8fafc;
            box-shadow: 0 0 0 3px rgba(79,109,245,0.25);
        }
        .ss-admin-card .input-group-text {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #64748b !important;
        }
        .ss-admin-card label {
            color: #cbd5e1;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        .ss-admin-card .btn-admin-submit {
            background: var(--ss-accent);
            color: #fff;
            border: none;
            font-weight: 600;
            transition: var(--ss-transition);
        }
        .ss-admin-card .btn-admin-submit:hover {
            background: var(--ss-accent-hover);
            color: #fff;
        }
    </style>
</head>
<body>

    <div class="ss-admin-auth-page">
        <div class="ss-admin-card">
            <!-- Admin Brand Shield -->
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle" style="background: rgba(79,109,245,0.15); border: 1px solid rgba(79,109,245,0.3);">
                    <i class="bi bi-shield-lock-fill fs-2" style="color: var(--ss-accent);"></i>
                </div>
                <div class="mt-2">
                    <span class="fw-bold tracking-wider text-uppercase" style="font-size: 0.75rem; letter-spacing: 1.5px; color: #94a3b8;">
                        ShopSphere Security Gateway
                    </span>
                </div>
            </div>

            <h2>Admin Console</h2>
            <p class="ss-admin-subtitle">Provide verified administrative credentials to access system controls</p>

            <!-- Error Alerts -->
            <?php if (!empty($generalError)): ?>
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <div><?= e($generalError) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($successFlash = get_flash('success')): ?>
                <div class="ss-alert ss-alert-success" role="alert">
                    <i class="bi bi-check-circle-fill"></i>
                    <div><?= e($successFlash) ?></div>
                </div>
            <?php endif; ?>

            <!-- Admin Login Form -->
            <form action="<?= url('admin/login.php') ?>" method="POST" novalidate>
                <?= csrf_field() ?>

                <div class="ss-form-group">
                    <label for="email">Admin Email <span class="required text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-person-fill-lock"></i></span>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               class="form-control border-start-0 <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                               placeholder="admin@shopsphere.local" 
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
                    <label for="password">Password <span class="required text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-key-fill"></i></span>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control border-start-0 border-end-0 <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                               placeholder="Enter root credentials" 
                               required 
                               autocomplete="current-password">
                        <button type="button" class="btn btn-outline-secondary border-start-0 bg-transparent text-muted" id="togglePasswordBtn" style="border-color:#334155;">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-admin-submit w-100 py-2 mt-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Authenticate Console
                </button>
            </form>

            <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-25">
                <a href="<?= url('/') ?>" class="small text-muted text-decoration-none">
                    <i class="bi bi-arrow-left me-1"></i> Return to Main Storefront
                </a>
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
