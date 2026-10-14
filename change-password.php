<?php
/**
 * ShopSphere - Change Password Page
 *
 * Allows authenticated users to safely update their login credentials
 * by confirming their existing password and choosing a new compliant password.
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

// Enforce authentication
require_login();

$userId = (int) get_current_user_id();
$userRole = get_current_user_role();
$currentUser = current_user();

$errors = [];
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Your session expired or invalid security token. Please try again.';
    } else {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        // Validation: current password
        if (!validate_required($currentPassword)) {
            $errors['current_password'] = 'Please enter your current password.';
        }

        // Validation: new password
        if (!validate_required($newPassword)) {
            $errors['new_password'] = 'Please enter your new password.';
        } elseif (strlen($newPassword) < 8) {
            $errors['new_password'] = 'New password must be at least 8 characters long.';
        } elseif (!validate_password_strength($newPassword)) {
            $errors['new_password'] = 'New password must include uppercase, lowercase, and numeric characters.';
        } elseif ($currentPassword !== '' && $currentPassword === $newPassword) {
            $errors['new_password'] = 'New password must be different from your current password.';
        }

        // Validation: confirm password
        if (!validate_required($confirmPassword)) {
            $errors['confirm_password'] = 'Please confirm your new password.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            $authService = new AuthService();
            $result = $authService->changePassword($userId, $currentPassword, $newPassword);

            if ($result['success']) {
                flash('success', 'Your password has been changed successfully.');
                redirect(url('profile.php'));
            } else {
                if (isset($result['error'])) {
                    if (str_contains(strtolower($result['error']), 'current password')) {
                        $errors['current_password'] = $result['error'];
                    } else {
                        $generalError = $result['error'];
                    }
                } else {
                    $generalError = 'Failed to change password. Please verify your current password.';
                }
            }
        }
    }
}

$pageTitle = 'Change Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <?php if ($userRole === 'seller'): ?>
                <li><a href="<?= url('seller/dashboard.php') ?>">Vendor Portal</a></li>
            <?php elseif ($userRole === 'admin'): ?>
                <li><a href="<?= url('admin/dashboard.php') ?>">Admin Portal</a></li>
            <?php else: ?>
                <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <?php endif; ?>
            <li>Change Password</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Account Sidebar -->
        <div class="col-lg-3 col-md-4">
            <?php 
            $active_page = 'change-password';
            require __DIR__ . '/includes/account-sidebar.php'; 
            ?>
        </div>

        <!-- Main Password Change Form Card -->
        <div class="col-lg-9 col-md-8">
            <div class="ss-card">
                <div class="ss-card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-key-fill text-ss-accent me-2"></i> Change Password</h5>
                    <span class="badge bg-light text-muted border">Security</span>
                </div>
                <div class="ss-card-body p-4">
                    <p class="text-muted small mb-4">
                        Ensure your account remains safe by choosing a strong password with a combination of uppercase letters, lowercase letters, numbers, and symbols.
                    </p>

                    <?php if (!empty($generalError)): ?>
                        <div class="ss-alert ss-alert-danger" role="alert">
                            <i class="bi bi-exclamation-octagon-fill"></i>
                            <div><?= e($generalError) ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="<?= url('change-password.php') ?>" method="POST" novalidate>
                        <?= csrf_field() ?>

                        <!-- Current Password -->
                        <div class="ss-form-group">
                            <label for="current_password">Current Password <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" 
                                       id="current_password" 
                                       name="current_password" 
                                       class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" 
                                       placeholder="Enter your current password" 
                                       required 
                                       autocomplete="current-password" 
                                       autofocus>
                                <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted toggle-pwd-btn" data-target="#current_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['current_password'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['current_password']) ?></div>
                            <?php endif; ?>
                        </div>

                        <hr class="my-4 text-muted opacity-25">

                        <!-- New Password -->
                        <div class="ss-form-group">
                            <label for="new_password">New Password <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-key"></i></span>
                                <input type="password" 
                                       id="new_password" 
                                       name="new_password" 
                                       class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" 
                                       placeholder="Minimum 8 characters" 
                                       required 
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted toggle-pwd-btn" data-target="#new_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1" style="font-size:0.75rem;">
                                Must contain at least 8 characters with at least one uppercase letter, one lowercase letter, and one number.
                            </small>
                            <?php if (isset($errors['new_password'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['new_password']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Confirm New Password -->
                        <div class="ss-form-group">
                            <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-shield-check"></i></span>
                                <input type="password" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                       placeholder="Repeat your new password" 
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

                        <div class="d-flex justify-content-between align-items-center mt-4 pt-2">
                            <a href="<?= url('profile.php') ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-ss-primary px-4">
                                <i class="bi bi-check2-circle me-1"></i> Save New Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.toggle-pwd-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                const target = document.querySelector(this.getAttribute('data-target'));
                const icon = this.querySelector('i');
                if (target) {
                    const isPwd = target.type === 'password';
                    target.type = isPwd ? 'text' : 'password';
                    icon.classList.toggle('bi-eye');
                    icon.classList.toggle('bi-eye-slash');
                }
            });
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
