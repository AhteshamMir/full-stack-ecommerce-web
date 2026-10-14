<?php
/**
 * ShopSphere - Customer Profile Editor
 *
 * Allows authenticated customers to review and update personal details,
 * contact numbers, gender, and date of birth with full CSRF and input validation.
 *
 * @package ShopSphere\Customer
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';

// Enforce customer login
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();
$currentUser = current_user(true); // Fetch fresh user data

// Ensure customer_profiles table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        phone VARCHAR(30) NULL,
        date_of_birth DATE NULL,
        gender VARCHAR(20) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('[ShopSphere Profile Table Check] ' . $e->getMessage());
}

// Fetch existing customer_profiles record
$profile = null;
try {
    $stmtProf = $pdo->prepare('SELECT * FROM customer_profiles WHERE user_id = :uid LIMIT 1');
    $stmtProf->execute([':uid' => $userId]);
    $profile = $stmtProf->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('[ShopSphere Profile Load Error] ' . $e->getMessage());
}

$errors = [];
$generalError = '';

// Handle Profile Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        $generalError = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $firstName   = sanitize_string($_POST['first_name'] ?? '');
        $lastName    = sanitize_string($_POST['last_name'] ?? '');
        $phone       = sanitize_string($_POST['phone'] ?? '');
        $dateOfBirth = sanitize_string($_POST['date_of_birth'] ?? '');
        $gender      = strtolower(sanitize_string($_POST['gender'] ?? ''));

        // Validate First Name
        if (!validate_required($firstName)) {
            $errors['first_name'] = 'First name is required.';
        } elseif (!validate_max_length($firstName, 50)) {
            $errors['first_name'] = 'First name cannot exceed 50 characters.';
        }

        // Validate Last Name
        if (!validate_required($lastName)) {
            $errors['last_name'] = 'Last name is required.';
        } elseif (!validate_max_length($lastName, 50)) {
            $errors['last_name'] = 'Last name cannot exceed 50 characters.';
        }

        // Validate Phone (optional, but format check if provided)
        if ($phone !== '' && !validate_phone($phone)) {
            $errors['phone'] = 'Please enter a valid phone number (e.g., 03001234567 or +923001234567).';
        }

        // Validate Gender
        if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
            $errors['gender'] = 'Please select a valid gender option.';
        }

        // Validate Date of Birth (optional)
        $cleanDob = null;
        if ($dateOfBirth !== '') {
            $dobTimestamp = strtotime($dateOfBirth);
            if ($dobTimestamp === false) {
                $errors['date_of_birth'] = 'Please enter a valid date of birth (YYYY-MM-DD).';
            } elseif ($dobTimestamp > time()) {
                $errors['date_of_birth'] = 'Date of birth cannot be in the future.';
            } else {
                $cleanDob = date('Y-m-d', $dobTimestamp);
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1. Update users table (first_name, last_name, phone)
                $updUser = $pdo->prepare('UPDATE users SET 
                    first_name = :fname,
                    last_name = :lname,
                    phone = :phone,
                    updated_at = UTC_TIMESTAMP()
                    WHERE id = :id');
                $updUser->execute([
                    ':fname' => $firstName,
                    ':lname' => $lastName,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':id'    => $userId,
                ]);

                // 2. Upsert customer_profiles table (date_of_birth, gender)
                if ($profile) {
                    $updProfile = $pdo->prepare('UPDATE customer_profiles SET 
                        date_of_birth = :dob,
                        gender = :gender,
                        updated_at = UTC_TIMESTAMP()
                        WHERE user_id = :uid');
                    $updProfile->execute([
                        ':dob'    => $cleanDob,
                        ':gender' => $gender !== '' ? $gender : null,
                        ':uid'    => $userId,
                    ]);
                } else {
                    $insProfile = $pdo->prepare('INSERT INTO customer_profiles (
                        user_id, phone, date_of_birth, gender, created_at, updated_at
                    ) VALUES (
                        :uid, :phone, :dob, :gender, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                    )');
                    $insProfile->execute([
                        ':uid'    => $userId,
                        ':phone'  => $phone !== '' ? $phone : null,
                        ':dob'    => $cleanDob,
                        ':gender' => $gender !== '' ? $gender : null,
                    ]);
                }

                $pdo->commit();

                // Update session display name
                $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);

                flash('success', 'Your profile details have been saved successfully.');
                redirect(url('profile.php'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[ShopSphere Profile Update Error] ' . $e->getMessage());
                $generalError = 'An error occurred while saving your profile. Please try again.';
            }
        }
    }
}

// Current field values (default to DB, fallback to POST values on validation error)
$valFirstName   = old('first_name', $currentUser['first_name'] ?? '');
$valLastName    = old('last_name', $currentUser['last_name'] ?? '');
$valPhone       = old('phone', $currentUser['phone'] ?? ($profile['phone'] ?? ''));
$valDateOfBirth = old('date_of_birth', $profile['date_of_birth'] ?? '');
$valGender      = old('gender', $profile['gender'] ?? '');
$valEmail       = $currentUser['email'] ?? '';

$page_title = 'Profile Details';
$active_page = 'profile';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li>Profile Details</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Profile Form -->
        <div class="col-lg-9 col-md-8">
            <div class="ss-card shadow-sm">
                <div class="ss-card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-person-gear text-ss-accent me-2"></i> Edit Profile Information
                    </h5>
                    <span class="badge bg-light text-muted border">Account Settings</span>
                </div>
                <div class="ss-card-body p-4">
                    <p class="text-muted small mb-4">
                        Keep your contact information up to date so we can reach you regarding order status, delivery notifications, and special offers.
                    </p>

                    <?php if (!empty($generalError)): ?>
                        <div class="ss-alert ss-alert-danger" role="alert">
                            <i class="bi bi-exclamation-octagon-fill"></i>
                            <div><?= e($generalError) ?></div>
                        </div>
                    <?php endif; ?>

                    <form action="<?= url('profile.php') ?>" method="POST" novalidate>
                        <?= csrf_field() ?>

                        <div class="row g-3">
                            <!-- First Name -->
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="first_name">First Name <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-person"></i></span>
                                        <input type="text" 
                                               id="first_name" 
                                               name="first_name" 
                                               class="form-control ss-form-control border-start-0 <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" 
                                               value="<?= e($valFirstName) ?>" 
                                               required 
                                               maxlength="50"
                                               autocomplete="given-name">
                                    </div>
                                    <?php if (isset($errors['first_name'])): ?>
                                        <div class="ss-invalid-feedback d-block"><?= e($errors['first_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Last Name -->
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="last_name">Last Name <span class="required">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-person"></i></span>
                                        <input type="text" 
                                               id="last_name" 
                                               name="last_name" 
                                               class="form-control ss-form-control border-start-0 <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" 
                                               value="<?= e($valLastName) ?>" 
                                               required 
                                               maxlength="50"
                                               autocomplete="family-name">
                                    </div>
                                    <?php if (isset($errors['last_name'])): ?>
                                        <div class="ss-invalid-feedback d-block"><?= e($errors['last_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Email Address (Read-Only) -->
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="email">Email Address <span class="text-muted">(Primary Identifier)</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-envelope"></i></span>
                                        <input type="email" 
                                               id="email" 
                                               class="form-control ss-form-control border-start-0 bg-light text-muted" 
                                               value="<?= e($valEmail) ?>" 
                                               readonly 
                                               disabled>
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-info-circle me-1"></i> Email cannot be modified directly. Contact support if you need to update it.
                                    </small>
                                </div>
                            </div>

                            <!-- Phone Number -->
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="phone">Phone / Mobile Number</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-telephone"></i></span>
                                        <input type="tel" 
                                               id="phone" 
                                               name="phone" 
                                               class="form-control ss-form-control border-start-0 <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" 
                                               placeholder="03001234567" 
                                               value="<?= e($valPhone) ?>" 
                                               autocomplete="tel">
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">Used for courier delivery alerts & SMS updates.</small>
                                    <?php if (isset($errors['phone'])): ?>
                                        <div class="ss-invalid-feedback d-block"><?= e($errors['phone']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Date of Birth -->
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="date_of_birth">Date of Birth</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-calendar-event"></i></span>
                                        <input type="date" 
                                               id="date_of_birth" 
                                               name="date_of_birth" 
                                               class="form-control ss-form-control border-start-0 <?= isset($errors['date_of_birth']) ? 'is-invalid' : '' ?>" 
                                               value="<?= e($valDateOfBirth) ?>" 
                                               max="<?= date('Y-m-d') ?>">
                                    </div>
                                    <?php if (isset($errors['date_of_birth'])): ?>
                                        <div class="ss-invalid-feedback d-block"><?= e($errors['date_of_birth']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Gender -->
                            <div class="col-md-6">
                                <div class="ss-form-group">
                                    <label for="gender">Gender</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-gender-ambiguous"></i></span>
                                        <select id="gender" 
                                                name="gender" 
                                                class="form-control ss-form-control border-start-0 <?= isset($errors['gender']) ? 'is-invalid' : '' ?>">
                                            <option value="">-- Select Gender (Optional) --</option>
                                            <option value="male" <?= $valGender === 'male' ? 'selected' : '' ?>>Male</option>
                                            <option value="female" <?= $valGender === 'female' ? 'selected' : '' ?>>Female</option>
                                            <option value="other" <?= $valGender === 'other' ? 'selected' : '' ?>>Other / Prefer not to say</option>
                                        </select>
                                    </div>
                                    <?php if (isset($errors['gender'])): ?>
                                        <div class="ss-invalid-feedback d-block"><?= e($errors['gender']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4 text-muted opacity-25">

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= url('account.php') ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
                            </a>
                            <button type="submit" class="btn btn-ss-primary px-4 py-2">
                                <i class="bi bi-check2-circle me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Password Security Shortcut Card -->
            <div class="ss-card mt-4 shadow-sm border-0 bg-light">
                <div class="ss-card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h6 class="mb-1 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Account Security</h6>
                        <p class="text-muted small mb-0">Need to change your account password or update your security credentials?</p>
                    </div>
                    <a href="<?= url('change-password.php') ?>" class="btn btn-outline-primary btn-sm px-3">
                        Change Password
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
