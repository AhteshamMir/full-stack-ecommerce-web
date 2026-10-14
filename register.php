<?php
/**
 * ShopSphere - Customer Registration Page
 *
 * Allows shoppers to create accounts with validation, password strength checks,
 * terms acceptance, automated session initialization, and redirection.
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

$errors = [];
$firstName = '';
$lastName = '';
$email = '';
$phone = '';
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Your session expired or invalid security token. Please try again.';
    } else {
        $firstName       = sanitize_string($_POST['first_name'] ?? '');
        $lastName        = sanitize_string($_POST['last_name'] ?? '');
        $email           = sanitize_string($_POST['email'] ?? '');
        $phone           = sanitize_string($_POST['phone'] ?? '');
        $password        = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        $termsAccepted   = isset($_POST['terms']) && $_POST['terms'] === '1';

        // 1. First Name Validation
        if (!validate_required($firstName)) {
            $errors['first_name'] = 'First name is required.';
        } elseif (!validate_min_length($firstName, 2)) {
            $errors['first_name'] = 'First name must be at least 2 characters.';
        }

        // 2. Last Name Validation
        if (!validate_required($lastName)) {
            $errors['last_name'] = 'Last name is required.';
        } elseif (!validate_min_length($lastName, 2)) {
            $errors['last_name'] = 'Last name must be at least 2 characters.';
        }

        // 3. Email Validation
        if (!validate_required($email)) {
            $errors['email'] = 'Email address is required.';
        } elseif (!validate_email($email)) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            $authService = new AuthService();
            if ($authService->getUserByEmail($email) !== null) {
                $errors['email'] = 'An account with this email address already exists.';
            }
        }

        // 4. Phone Validation (optional, but validated if provided)
        if ($phone !== '' && !validate_phone($phone)) {
            $errors['phone'] = 'Please enter a valid phone number (e.g., 03001234567 or +923001234567).';
        }

        // 5. Password Strength Validation
        if (!validate_required($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters long.';
        } elseif (!validate_password_strength($password)) {
            $errors['password'] = 'Password must include uppercase, lowercase, and numeric characters.';
        }

        // 6. Confirm Password Match
        if (!validate_required($confirmPassword)) {
            $errors['confirm_password'] = 'Please confirm your password.';
        } elseif ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        // 7. Terms and Conditions Check
        if (!$termsAccepted) {
            $errors['terms'] = 'You must agree to the Terms of Service and Privacy Policy to continue.';
        }

        // If validation passed, register user
        if (empty($errors)) {
            $authService = new AuthService();
            $result = $authService->register([
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => $email,
                'phone'      => $phone,
                'password'   => $password,
                'role'       => 'customer',
            ]);

            if ($result['success'] && !empty($result['user_id'])) {
                $user = $authService->getUserById((int) $result['user_id']);
                if ($user) {
                    login_user($user);
                    flash('success', 'Your account has been created successfully! Welcome to ShopSphere.');
                    redirect(url('account.php'));
                } else {
                    flash('success', 'Registration successful! Please log in to continue.');
                    redirect(url('login.php'));
                }
            } else {
                if (!empty($result['errors'])) {
                    $errors = array_merge($errors, $result['errors']);
                } else {
                    $generalError = 'Registration failed due to a system error. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Create Account | ShopSphere';
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

    <div class="ss-auth-page py-4">
        <div class="ss-auth-card" style="max-width: 520px;">
            <!-- Brand Logo -->
            <div class="ss-auth-logo">
                <a href="<?= url('/') ?>" class="ss-logo d-inline-flex text-decoration-none">
                    <div class="ss-logo-icon">
                        <i class="bi bi-bag-check-fill"></i>
                    </div>
                    <span>Shop</span>Sphere
                </a>
            </div>

            <h2>Create Customer Account</h2>
            <p class="ss-auth-subtitle">Join thousands of shoppers and discover exclusive marketplace deals</p>

            <?php if (!empty($generalError)): ?>
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <div><?= e($generalError) ?></div>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <form action="<?= url('register.php') ?>" method="POST" novalidate>
                <?= csrf_field() ?>

                <!-- Name Fields -->
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="ss-form-group">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" 
                                   id="first_name" 
                                   name="first_name" 
                                   class="form-control ss-form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" 
                                   placeholder="First name" 
                                   value="<?= e($firstName) ?>" 
                                   required 
                                   autocomplete="given-name" 
                                   autofocus>
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['first_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="ss-form-group">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" 
                                   id="last_name" 
                                   name="last_name" 
                                   class="form-control ss-form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" 
                                   placeholder="Last name" 
                                   value="<?= e($lastName) ?>" 
                                   required 
                                   autocomplete="family-name">
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['last_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Email Field -->
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
                               autocomplete="email">
                    </div>
                    <?php if (isset($errors['email'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Phone Field -->
                <div class="ss-form-group">
                    <label for="phone">Phone Number <small class="text-muted">(Optional)</small></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-telephone"></i></span>
                        <input type="tel" 
                               id="phone" 
                               name="phone" 
                               class="form-control ss-form-control border-start-0 <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" 
                               placeholder="03001234567" 
                               value="<?= e($phone) ?>" 
                               autocomplete="tel">
                    </div>
                    <?php if (isset($errors['phone'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['phone']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Password Fields -->
                <div class="ss-form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                               placeholder="At least 8 characters with upper, lower & numbers" 
                               required 
                               autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted toggle-pwd-btn" data-target="#password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block mt-1" style="font-size:0.75rem;">Minimum 8 characters with at least one uppercase letter, one lowercase letter, and one number.</small>
                    <?php if (isset($errors['password'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="ss-form-group">
                    <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-shield-check"></i></span>
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                               placeholder="Re-type your password" 
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

                <!-- Terms Checkbox -->
                <div class="form-check ss-form-group mb-3">
                    <input class="form-check-input <?= isset($errors['terms']) ? 'is-invalid' : '' ?>" 
                           type="checkbox" 
                           id="terms" 
                           name="terms" 
                           value="1" 
                           <?= (isset($_POST['terms']) && $_POST['terms'] === '1') ? 'checked' : '' ?> 
                           required>
                    <label class="form-check-label small" for="terms">
                        I agree to the ShopSphere <a href="<?= url('page.php?slug=terms') ?>" target="_blank" class="text-ss-accent text-decoration-none">Terms of Service</a> and <a href="<?= url('page.php?slug=privacy') ?>" target="_blank" class="text-ss-accent text-decoration-none">Privacy Policy</a> <span class="required">*</span>
                    </label>
                    <?php if (isset($errors['terms'])): ?>
                        <div class="ss-invalid-feedback d-block"><?= e($errors['terms']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-ss-primary w-100 py-2">
                    <i class="bi bi-person-check-fill me-1"></i> Complete Registration
                </button>
            </form>

            <div class="ss-auth-divider">Looking to sell products?</div>

            <div class="text-center">
                <a href="<?= url('seller/register.php') ?>" class="btn btn-ss-outline w-100 py-2 btn-sm mb-3">
                    <i class="bi bi-shop me-1"></i> Apply as a Vendor / Seller
                </a>
            </div>

            <!-- Footer Links -->
            <div class="ss-auth-footer">
                Already have an account? 
                <a href="<?= url('login.php') ?>" class="fw-semibold text-ss-accent text-decoration-none">Sign In</a>
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
