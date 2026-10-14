<?php
/**
 * ShopSphere - Vendor Application & Registration
 *
 * Collects vendor identity, storefront credentials, and commercial details.
 * Submits vendor profile into a pending state requiring administrator vetting.
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

// Redirect if already logged in as seller or admin
if (is_logged_in()) {
    $currentRole = get_current_user_role();
    if ($currentRole === 'seller') {
        redirect(url('seller/dashboard.php'));
    } elseif ($currentRole === 'admin') {
        redirect(url('admin/dashboard.php'));
    }
}

$errors = [];
$firstName         = '';
$lastName          = '';
$email             = '';
$phone             = '';
$storeName         = '';
$businessName      = '';
$businessAddress   = '';
$storeDescription  = '';
$generalError      = '';
$applicationSubmitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Your session expired or invalid security token. Please try again.';
    } else {
        $firstName        = sanitize_string($_POST['first_name'] ?? '');
        $lastName         = sanitize_string($_POST['last_name'] ?? '');
        $email            = sanitize_string($_POST['email'] ?? '');
        $phone            = sanitize_string($_POST['phone'] ?? '');
        $storeName        = sanitize_string($_POST['store_name'] ?? '');
        $businessName     = sanitize_string($_POST['business_name'] ?? '');
        $businessAddress  = sanitize_string($_POST['business_address'] ?? '');
        $storeDescription = sanitize_string($_POST['store_description'] ?? '');
        $password         = (string) ($_POST['password'] ?? '');
        $confirmPassword  = (string) ($_POST['confirm_password'] ?? '');
        $termsAccepted    = isset($_POST['terms']) && $_POST['terms'] === '1';

        // 1. First & Last Name
        if (!validate_required($firstName)) {
            $errors['first_name'] = 'First name is required.';
        }
        if (!validate_required($lastName)) {
            $errors['last_name'] = 'Last name is required.';
        }

        // 2. Email
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

        // 3. Phone
        if (!validate_required($phone)) {
            $errors['phone'] = 'Contact phone number is required.';
        } elseif (!validate_phone($phone)) {
            $errors['phone'] = 'Please enter a valid phone number (e.g. 03001234567 or +923001234567).';
        }

        // 4. Store & Business Data
        if (!validate_required($storeName)) {
            $errors['store_name'] = 'Public store name is required.';
        } elseif (!validate_min_length($storeName, 3)) {
            $errors['store_name'] = 'Store name must be at least 3 characters.';
        }

        if (!validate_required($businessName)) {
            $errors['business_name'] = 'Registered company or business legal name is required.';
        }

        if (!validate_required($businessAddress)) {
            $errors['business_address'] = 'Registered business address is required.';
        }

        // 5. Password Strength
        if (!validate_required($password)) {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters long.';
        } elseif (!validate_password_strength($password)) {
            $errors['password'] = 'Password must include uppercase, lowercase, and numbers.';
        }

        // 6. Confirm Password
        if (!validate_required($confirmPassword)) {
            $errors['confirm_password'] = 'Please confirm your password.';
        } elseif ($password !== $confirmPassword) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        // 7. Terms
        if (!$termsAccepted) {
            $errors['terms'] = 'You must agree to the Marketplace Vendor Agreement and Terms.';
        }

        if (empty($errors)) {
            $authService = new AuthService();
            $result = $authService->register([
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $email,
                'phone'             => $phone,
                'password'          => $password,
                'role'              => 'seller',
                'store_name'        => $storeName,
                'business_name'     => $businessName,
                'business_address'  => $businessAddress,
                'store_description' => $storeDescription,
            ]);

            if ($result['success']) {
                // Application submitted into pending state. DO NOT auto-login.
                $applicationSubmitted = true;
            } else {
                if (!empty($result['errors'])) {
                    $errors = array_merge($errors, $result['errors']);
                } else {
                    $generalError = 'Failed to submit seller application. Please check your inputs and try again.';
                }
            }
        }
    }
}

$pageTitle = 'Sell on ShopSphere | Vendor Application';
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

    <div class="ss-auth-page py-5">
        <div class="ss-auth-card" style="max-width: 640px;">
            <!-- Brand Logo -->
            <div class="ss-auth-logo">
                <a href="<?= url('/') ?>" class="ss-logo d-inline-flex text-decoration-none justify-content-center">
                    <div class="ss-logo-icon bg-warning text-dark">
                        <i class="bi bi-shop"></i>
                    </div>
                    <span>Shop</span>Sphere
                </a>
                <div class="mt-2">
                    <span class="badge bg-warning-subtle text-warning border px-2 py-1 small fw-semibold">
                        Marketplace Vendor Application
                    </span>
                </div>
            </div>

            <?php if ($applicationSubmitted): ?>
                <!-- Application Received Success Screen -->
                <div class="text-center py-3">
                    <div class="ss-order-success p-0 mb-4">
                        <div class="success-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>
                    </div>

                    <h2 class="text-success mb-2">Application Submitted!</h2>
                    <p class="lead fs-6 text-dark fw-semibold mb-3">
                        Your seller application has been submitted. You will be notified once it is approved.
                    </p>
                    <p class="text-muted small mb-4">
                        Our compliance team typically evaluates new merchant applications within 24 to 48 business hours. Once verified, you will receive full access to your vendor dashboard to list inventory and fulfill orders.
                    </p>

                    <div class="d-grid gap-2">
                        <a href="<?= url('seller/login.php') ?>" class="btn btn-ss-primary py-2">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Seller Sign In
                        </a>
                        <a href="<?= url('/') ?>" class="btn btn-ss-outline py-2">
                            <i class="bi bi-house me-1"></i> Return to Homepage
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <h2>Apply as a Vendor</h2>
                <p class="ss-auth-subtitle">Expand your business reach to millions of verified Pakistani online buyers</p>

                <?php if (!empty($generalError)): ?>
                    <div class="ss-alert ss-alert-danger" role="alert">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                        <div><?= e($generalError) ?></div>
                    </div>
                <?php endif; ?>

                <form action="<?= url('seller/register.php') ?>" method="POST" novalidate>
                    <?= csrf_field() ?>

                    <!-- Section: Store Information -->
                    <div class="border-bottom pb-3 mb-4">
                        <h6 class="text-ss-primary fw-bold mb-3">
                            <i class="bi bi-shop text-ss-accent me-1"></i> Store & Business Details
                        </h6>

                        <div class="ss-form-group">
                            <label for="store_name">Store Display Name <span class="required">*</span></label>
                            <input type="text" 
                                   id="store_name" 
                                   name="store_name" 
                                   class="form-control ss-form-control <?= isset($errors['store_name']) ? 'is-invalid' : '' ?>" 
                                   placeholder="e.g., Karachi Tech Hub" 
                                   value="<?= e($storeName) ?>" 
                                   required 
                                   autofocus>
                            <small class="text-muted" style="font-size:0.75rem;">This name is shown to shoppers on product pages.</small>
                            <?php if (isset($errors['store_name'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['store_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-form-group">
                            <label for="business_name">Legal Company / Business Name <span class="required">*</span></label>
                            <input type="text" 
                                   id="business_name" 
                                   name="business_name" 
                                   class="form-control ss-form-control <?= isset($errors['business_name']) ? 'is-invalid' : '' ?>" 
                                   placeholder="e.g., TechHub Private Limited" 
                                   value="<?= e($businessName) ?>" 
                                   required>
                            <?php if (isset($errors['business_name'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['business_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-form-group">
                            <label for="business_address">Registered Business Address <span class="required">*</span></label>
                            <textarea id="business_address" 
                                      name="business_address" 
                                      rows="2" 
                                      class="form-control ss-form-control <?= isset($errors['business_address']) ? 'is-invalid' : '' ?>" 
                                      placeholder="Suite #, Building, Street, City, Province" 
                                      required><?= e($businessAddress) ?></textarea>
                            <?php if (isset($errors['business_address'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['business_address']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-form-group mb-0">
                            <label for="store_description">Store Description <small class="text-muted">(Optional)</small></label>
                            <textarea id="store_description" 
                                      name="store_description" 
                                      rows="2" 
                                      class="form-control ss-form-control <?= isset($errors['store_description']) ? 'is-invalid' : '' ?>" 
                                      placeholder="Brief overview of the merchandise and products you offer..."><?= e($storeDescription) ?></textarea>
                            <?php if (isset($errors['store_description'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['store_description']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Section: Contact Person & Credentials -->
                    <div class="border-bottom pb-3 mb-4">
                        <h6 class="text-ss-primary fw-bold mb-3">
                            <i class="bi bi-person-badge text-ss-accent me-1"></i> Authorized Representative & Credentials
                        </h6>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="first_name">First Name <span class="required">*</span></label>
                                    <input type="text" 
                                           id="first_name" 
                                           name="first_name" 
                                           class="form-control ss-form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" 
                                           value="<?= e($firstName) ?>" 
                                           required 
                                           autocomplete="given-name">
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
                                           value="<?= e($lastName) ?>" 
                                           required 
                                           autocomplete="family-name">
                                    <?php if (isset($errors['last_name'])): ?>
                                        <div class="ss-invalid-feedback d-block"><?= e($errors['last_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="ss-form-group">
                            <label for="email">Business Email Address <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                                <input type="email" 
                                       id="email" 
                                       name="email" 
                                       class="form-control ss-form-control border-start-0 <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                       placeholder="vendor@company.com" 
                                       value="<?= e($email) ?>" 
                                       required 
                                       autocomplete="email">
                            </div>
                            <?php if (isset($errors['email'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-form-group">
                            <label for="phone">Primary Phone Number <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-telephone"></i></span>
                                <input type="tel" 
                                       id="phone" 
                                       name="phone" 
                                       class="form-control ss-form-control border-start-0 <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" 
                                       placeholder="03001234567" 
                                       value="<?= e($phone) ?>" 
                                       required 
                                       autocomplete="tel">
                            </div>
                            <?php if (isset($errors['phone'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-form-group">
                            <label for="password">Portal Password <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                                <input type="password" 
                                       id="password" 
                                       name="password" 
                                       class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                       placeholder="Minimum 8 characters" 
                                       required 
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary border-start-0 bg-white text-muted toggle-pwd-btn" data-target="#password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1" style="font-size:0.75rem;">Must contain at least 8 characters with uppercase, lowercase, and numbers.</small>
                            <?php if (isset($errors['password'])): ?>
                                <div class="ss-invalid-feedback d-block"><?= e($errors['password']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-form-group mb-0">
                            <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-shield-check"></i></span>
                                <input type="password" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       class="form-control ss-form-control border-start-0 border-end-0 <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                       placeholder="Confirm portal password" 
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
                    </div>

                    <!-- Terms Acceptance -->
                    <div class="form-check ss-form-group mb-4">
                        <input class="form-check-input <?= isset($errors['terms']) ? 'is-invalid' : '' ?>" 
                               type="checkbox" 
                               id="terms" 
                               name="terms" 
                               value="1" 
                               <?= (isset($_POST['terms']) && $_POST['terms'] === '1') ? 'checked' : '' ?> 
                               required>
                        <label class="form-check-label small" for="terms">
                            I accept the <a href="<?= url('page.php?slug=vendor-terms') ?>" target="_blank" class="text-ss-accent text-decoration-none">Marketplace Vendor Agreement</a>, commission policies, and <a href="<?= url('page.php?slug=privacy') ?>" target="_blank" class="text-ss-accent text-decoration-none">Privacy Policy</a> <span class="required">*</span>
                        </label>
                        <?php if (isset($errors['terms'])): ?>
                            <div class="ss-invalid-feedback d-block"><?= e($errors['terms']) ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100 py-2">
                        <i class="bi bi-send-check me-1"></i> Submit Vendor Application
                    </button>
                </form>

                <div class="ss-auth-footer">
                    Already an approved vendor? 
                    <a href="<?= url('seller/login.php') ?>" class="fw-semibold text-ss-accent text-decoration-none">Sign In Here</a>
                </div>
            <?php endif; ?>
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
