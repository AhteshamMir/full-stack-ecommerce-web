<?php
/**
 * ShopSphere - Customer Saved Addresses Manager
 *
 * Allows authenticated customers to manage multiple shipping and billing addresses,
 * specify default delivery addresses, and create or update contact details securely.
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

// Ensure addresses table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        address_line1 VARCHAR(255) NOT NULL,
        address_line2 VARCHAR(255) NULL,
        city VARCHAR(100) NOT NULL,
        state VARCHAR(100) NULL,
        postal_code VARCHAR(20) NULL,
        country VARCHAR(100) NOT NULL DEFAULT 'Pakistan',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('[ShopSphere Addresses Table Check] ' . $e->getMessage());
}

$errors = [];
$generalError = '';
$editAddress = null;

// Handle Edit Mode via GET query param ?edit=ID
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($editId) {
    try {
        $stmtEdit = $pdo->prepare('SELECT * FROM addresses WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmtEdit->execute([':id' => $editId, ':uid' => $userId]);
        $editAddress = $stmtEdit->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editAddress) {
            flash('error', 'Address not found or permission denied.');
            redirect(url('addresses.php'));
        }
    } catch (Throwable $e) {
        error_log('[ShopSphere Address Edit Fetch] ' . $e->getMessage());
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf_token(is_string($token) ? $token : null)) {
        flash('error', 'Session expired. Please try again.');
        redirect(url('addresses.php'));
    }

    $action = sanitize_string($_POST['address_action'] ?? '');

    // 1. SET AS DEFAULT
    if ($action === 'set_default') {
        $targetId = filter_input(INPUT_POST, 'address_id', FILTER_VALIDATE_INT);
        if ($targetId) {
            try {
                // Verify ownership
                $stmtCheck = $pdo->prepare('SELECT id FROM addresses WHERE id = :id AND user_id = :uid LIMIT 1');
                $stmtCheck->execute([':id' => $targetId, ':uid' => $userId]);
                if ($stmtCheck->fetch()) {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE addresses SET is_default = 0, updated_at = UTC_TIMESTAMP() WHERE user_id = :uid')
                        ->execute([':uid' => $userId]);
                    $pdo->prepare('UPDATE addresses SET is_default = 1, updated_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :uid')
                        ->execute([':id' => $targetId, ':uid' => $userId]);
                    $pdo->commit();

                    flash('success', 'Default delivery address updated.');
                } else {
                    flash('error', 'Unable to set default address. Ownership verification failed.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[ShopSphere Set Default Address Error] ' . $e->getMessage());
                flash('error', 'Failed to update default address.');
            }
        }
        redirect(url('addresses.php'));
    }

    // 2. DELETE ADDRESS
    if ($action === 'delete') {
        $targetId = filter_input(INPUT_POST, 'address_id', FILTER_VALIDATE_INT);
        if ($targetId) {
            try {
                // Fetch target address to see if it's default
                $stmtTarget = $pdo->prepare('SELECT is_default FROM addresses WHERE id = :id AND user_id = :uid LIMIT 1');
                $stmtTarget->execute([':id' => $targetId, ':uid' => $userId]);
                $wasDefault = (int) $stmtTarget->fetchColumn();

                if ($wasDefault !== false) {
                    $pdo->beginTransaction();
                    $delStmt = $pdo->prepare('DELETE FROM addresses WHERE id = :id AND user_id = :uid');
                    $delStmt->execute([':id' => $targetId, ':uid' => $userId]);

                    // If deleted address was default, promote another address if one exists
                    if ($wasDefault === 1) {
                        $promoteStmt = $pdo->prepare('UPDATE addresses SET is_default = 1, updated_at = UTC_TIMESTAMP() WHERE user_id = :uid ORDER BY id ASC LIMIT 1');
                        $promoteStmt->execute([':uid' => $userId]);
                    }

                    $pdo->commit();
                    flash('success', 'Address deleted successfully.');
                } else {
                    flash('error', 'Address could not be found or you do not have permission to delete it.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[ShopSphere Delete Address Error] ' . $e->getMessage());
                flash('error', 'Failed to delete address.');
            }
        }
        redirect(url('addresses.php'));
    }

    // 3. CREATE OR UPDATE ADDRESS
    if ($action === 'save') {
        $addressId    = filter_input(INPUT_POST, 'address_id', FILTER_VALIDATE_INT) ?: null;
        $fullName     = sanitize_string($_POST['full_name'] ?? '');
        $phone        = sanitize_string($_POST['phone'] ?? '');
        $addressLine1 = sanitize_string($_POST['address_line1'] ?? '');
        $addressLine2 = sanitize_string($_POST['address_line2'] ?? '');
        $city         = sanitize_string($_POST['city'] ?? '');
        $state        = sanitize_string($_POST['state'] ?? '');
        $postalCode   = sanitize_string($_POST['postal_code'] ?? '');
        $country      = sanitize_string($_POST['country'] ?? 'Pakistan');
        $isDefault    = !empty($_POST['is_default']) ? 1 : 0;

        // Validations
        if (!validate_required($fullName)) {
            $errors['full_name'] = 'Full name is required.';
        } elseif (!validate_max_length($fullName, 100)) {
            $errors['full_name'] = 'Full name must not exceed 100 characters.';
        }

        if (!validate_required($phone)) {
            $errors['phone'] = 'Contact phone number is required.';
        } elseif (!validate_phone($phone)) {
            $errors['phone'] = 'Please enter a valid phone number (e.g., 03001234567).';
        }

        if (!validate_required($addressLine1)) {
            $errors['address_line1'] = 'Street address line 1 is required.';
        } elseif (!validate_max_length($addressLine1, 255)) {
            $errors['address_line1'] = 'Address cannot exceed 255 characters.';
        }

        if (!validate_required($city)) {
            $errors['city'] = 'City is required.';
        } elseif (!validate_max_length($city, 100)) {
            $errors['city'] = 'City cannot exceed 100 characters.';
        }

        if (!validate_required($country)) {
            $country = 'Pakistan';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // If marked as default, or if this is the user's very first address, unset previous defaults
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM addresses WHERE user_id = :uid');
                $countStmt->execute([':uid' => $userId]);
                $existingCount = (int) $countStmt->fetchColumn();

                if ($existingCount === 0) {
                    $isDefault = 1;
                }

                if ($isDefault === 1) {
                    $pdo->prepare('UPDATE addresses SET is_default = 0, updated_at = UTC_TIMESTAMP() WHERE user_id = :uid')
                        ->execute([':uid' => $userId]);
                }

                if ($addressId) {
                    // Update existing address (ensuring user_id match)
                    $updSql = 'UPDATE addresses SET 
                        full_name = :full_name,
                        phone = :phone,
                        address_line1 = :addr1,
                        address_line2 = :addr2,
                        city = :city,
                        state = :state,
                        postal_code = :postal,
                        country = :country,
                        is_default = :is_default,
                        updated_at = UTC_TIMESTAMP()
                        WHERE id = :id AND user_id = :uid';

                    $stmtUpd = $pdo->prepare($updSql);
                    $stmtUpd->execute([
                        ':full_name'  => $fullName,
                        ':phone'      => $phone,
                        ':addr1'      => $addressLine1,
                        ':addr2'      => $addressLine2 !== '' ? $addressLine2 : null,
                        ':city'       => $city,
                        ':state'      => $state !== '' ? $state : null,
                        ':postal'     => $postalCode !== '' ? $postalCode : null,
                        ':country'    => $country,
                        ':is_default' => $isDefault,
                        ':id'         => $addressId,
                        ':uid'        => $userId,
                    ]);

                    flash('success', 'Address updated successfully.');
                } else {
                    // Insert new address
                    $insSql = 'INSERT INTO addresses (
                        user_id, full_name, phone, address_line1, address_line2,
                        city, state, postal_code, country, is_default, created_at, updated_at
                    ) VALUES (
                        :uid, :full_name, :phone, :addr1, :addr2,
                        :city, :state, :postal, :country, :is_default, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                    )';

                    $stmtIns = $pdo->prepare($insSql);
                    $stmtIns->execute([
                        ':uid'        => $userId,
                        ':full_name'  => $fullName,
                        ':phone'      => $phone,
                        ':addr1'      => $addressLine1,
                        ':addr2'      => $addressLine2 !== '' ? $addressLine2 : null,
                        ':city'       => $city,
                        ':state'      => $state !== '' ? $state : null,
                        ':postal'     => $postalCode !== '' ? $postalCode : null,
                        ':country'    => $country,
                        ':is_default' => $isDefault,
                    ]);

                    flash('success', 'New address added successfully.');
                }

                $pdo->commit();
                redirect(url('addresses.php'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[ShopSphere Address Save Error] ' . $e->getMessage());
                $generalError = 'An error occurred while saving the address. Please try again.';
            }
        }
    }
}

// Fetch all addresses for current user
$addresses = [];
try {
    $stmtAddresses = $pdo->prepare('SELECT * FROM addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC');
    $stmtAddresses->execute([':uid' => $userId]);
    $addresses = $stmtAddresses->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[ShopSphere Addresses Query Error] ' . $e->getMessage());
}

$page_title = 'Saved Addresses';
$active_page = 'addresses';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li>Saved Addresses</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Addresses Content -->
        <div class="col-lg-9 col-md-8">
            <!-- Header bar with Add Address Button -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-ss-accent me-2"></i>Saved Delivery Addresses</h4>
                    <p class="text-muted small mb-0">Manage and customize your shipping destinations for fast checkout.</p>
                </div>
                <button type="button" class="btn btn-ss-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addressModal" id="addNewAddressBtn">
                    <i class="bi bi-plus-lg me-1"></i> Add New Address
                </button>
            </div>

            <?php if (!empty($generalError)): ?>
                <div class="ss-alert ss-alert-danger" role="alert">
                    <i class="bi bi-exclamation-octagon-fill"></i>
                    <div><?= e($generalError) ?></div>
                </div>
            <?php endif; ?>

            <!-- Inline Edit Form if ?edit=ID requested -->
            <?php if ($editAddress): ?>
                <div class="ss-card mb-4 border-primary shadow-sm">
                    <div class="ss-card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="text-white mb-0"><i class="bi bi-pencil-square me-2"></i> Edit Address #<?= $editAddress['id'] ?></h5>
                        <a href="<?= url('addresses.php') ?>" class="btn btn-sm btn-light text-primary">Cancel</a>
                    </div>
                    <div class="ss-card-body p-4">
                        <form action="<?= url('addresses.php') ?>" method="POST" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="address_action" value="save">
                            <input type="hidden" name="address_id" value="<?= $editAddress['id'] ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="ss-form-group">
                                        <label for="edit_full_name">Full Name / Recipient <span class="required">*</span></label>
                                        <input type="text" id="edit_full_name" name="full_name" class="form-control ss-form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" value="<?= e(old('full_name', $editAddress['full_name'])) ?>" required>
                                        <?php if (isset($errors['full_name'])): ?>
                                            <div class="ss-invalid-feedback d-block"><?= e($errors['full_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="ss-form-group">
                                        <label for="edit_phone">Contact Phone Number <span class="required">*</span></label>
                                        <input type="tel" id="edit_phone" name="phone" class="form-control ss-form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" value="<?= e(old('phone', $editAddress['phone'])) ?>" required>
                                        <?php if (isset($errors['phone'])): ?>
                                            <div class="ss-invalid-feedback d-block"><?= e($errors['phone']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="ss-form-group">
                                        <label for="edit_address_line1">Street Address Line 1 <span class="required">*</span></label>
                                        <input type="text" id="edit_address_line1" name="address_line1" class="form-control ss-form-control <?= isset($errors['address_line1']) ? 'is-invalid' : '' ?>" value="<?= e(old('address_line1', $editAddress['address_line1'])) ?>" required>
                                        <?php if (isset($errors['address_line1'])): ?>
                                            <div class="ss-invalid-feedback d-block"><?= e($errors['address_line1']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="ss-form-group">
                                        <label for="edit_address_line2">Apartment, Suite, Unit, Building (Optional)</label>
                                        <input type="text" id="edit_address_line2" name="address_line2" class="form-control ss-form-control" value="<?= e(old('address_line2', $editAddress['address_line2'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="ss-form-group">
                                        <label for="edit_city">City <span class="required">*</span></label>
                                        <input type="text" id="edit_city" name="city" class="form-control ss-form-control <?= isset($errors['city']) ? 'is-invalid' : '' ?>" value="<?= e(old('city', $editAddress['city'])) ?>" required>
                                        <?php if (isset($errors['city'])): ?>
                                            <div class="ss-invalid-feedback d-block"><?= e($errors['city']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="ss-form-group">
                                        <label for="edit_state">Province / State</label>
                                        <input type="text" id="edit_state" name="state" class="form-control ss-form-control" value="<?= e(old('state', $editAddress['state'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="ss-form-group">
                                        <label for="edit_postal_code">Postal Code</label>
                                        <input type="text" id="edit_postal_code" name="postal_code" class="form-control ss-form-control" value="<?= e(old('postal_code', $editAddress['postal_code'] ?? '')) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="ss-form-group">
                                        <label for="edit_country">Country</label>
                                        <input type="text" id="edit_country" name="country" class="form-control ss-form-control" value="<?= e(old('country', $editAddress['country'] ?? 'Pakistan')) ?>">
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" name="is_default" value="1" id="edit_is_default" <?= (!empty($editAddress['is_default']) || old('is_default')) ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold small" for="edit_is_default">
                                            Make this my default shipping address
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-2 border-top d-flex justify-content-end gap-2">
                                <a href="<?= url('addresses.php') ?>" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-ss-primary px-4">Update Address</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Addresses Grid -->
            <?php if (empty($addresses)): ?>
                <div class="ss-card shadow-sm">
                    <div class="ss-empty-state py-5">
                        <i class="bi bi-geo-alt"></i>
                        <h5>No Addresses Found</h5>
                        <p>You have not saved any shipping addresses yet. Add your address to speed up checkout!</p>
                        <button type="button" class="btn btn-ss-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addressModal">
                            <i class="bi bi-plus-lg me-1"></i> Add Your First Address
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($addresses as $addr): ?>
                        <div class="col-md-6">
                            <div class="ss-card h-100 shadow-sm border <?= !empty($addr['is_default']) ? 'border-primary' : '' ?>">
                                <div class="ss-card-body p-4 d-flex flex-column justify-content-between h-100">
                                    <div>
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="fw-bold mb-0 text-truncate" title="<?= e($addr['full_name']) ?>">
                                                <?= e($addr['full_name']) ?>
                                            </h6>
                                            <?php if (!empty($addr['is_default'])): ?>
                                                <span class="badge bg-primary text-white px-2 py-1" style="font-size: 0.65rem;">
                                                    <i class="bi bi-star-fill me-1"></i> Default Address
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <p class="text-muted small mb-3">
                                            <i class="bi bi-telephone me-1 text-muted"></i> <?= e($addr['phone']) ?><br>
                                            <i class="bi bi-building me-1 text-muted"></i> <?= e($addr['address_line1']) ?>
                                            <?php if (!empty($addr['address_line2'])): ?>
                                                , <?= e($addr['address_line2']) ?>
                                            <?php endif; ?><br>
                                            <?= e($addr['city']) ?><?= !empty($addr['state']) ? ', ' . e($addr['state']) : '' ?><?= !empty($addr['postal_code']) ? ' ' . e($addr['postal_code']) : '' ?><br>
                                            <?= e($addr['country'] ?? 'Pakistan') ?>
                                        </p>
                                    </div>

                                    <div class="border-top pt-3 mt-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div class="d-flex gap-2">
                                            <a href="<?= url('addresses.php?edit=' . $addr['id']) ?>" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Edit Address">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <!-- Delete Form -->
                                            <form action="<?= url('addresses.php') ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this address?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="address_action" value="delete">
                                                <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Delete Address">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <?php if (empty($addr['is_default'])): ?>
                                            <form action="<?= url('addresses.php') ?>" method="POST" class="m-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="address_action" value="set_default">
                                                <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-link text-decoration-none text-ss-accent p-0 small">
                                                    Set as Default
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Address Bootstrap 5 Modal -->
<div class="modal fade" id="addressModal" tabindex="-1" aria-labelledby="addressModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="addressModalLabel">
                    <i class="bi bi-geo-alt-fill text-ss-accent me-2"></i> Add New Address
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= url('addresses.php') ?>" method="POST" novalidate id="newAddressForm">
                <div class="modal-body p-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="address_action" value="save">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="ss-form-group">
                                <label for="modal_full_name">Recipient Full Name <span class="required">*</span></label>
                                <input type="text" id="modal_full_name" name="full_name" class="form-control ss-form-control" placeholder="e.g. Muhammad Ali" required maxlength="100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="ss-form-group">
                                <label for="modal_phone">Contact Phone Number <span class="required">*</span></label>
                                <input type="tel" id="modal_phone" name="phone" class="form-control ss-form-control" placeholder="03001234567" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="ss-form-group">
                                <label for="modal_address_line1">Street Address Line 1 <span class="required">*</span></label>
                                <input type="text" id="modal_address_line1" name="address_line1" class="form-control ss-form-control" placeholder="House #, Street name, Area" required maxlength="255">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="ss-form-group">
                                <label for="modal_address_line2">Apartment / Suite / Landmark (Optional)</label>
                                <input type="text" id="modal_address_line2" name="address_line2" class="form-control ss-form-control" placeholder="Near landmark or apartment number">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="ss-form-group">
                                <label for="modal_city">City <span class="required">*</span></label>
                                <input type="text" id="modal_city" name="city" class="form-control ss-form-control" placeholder="e.g. Lahore, Karachi" required maxlength="100">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="ss-form-group">
                                <label for="modal_state">Province / State</label>
                                <input type="text" id="modal_state" name="state" class="form-control ss-form-control" placeholder="e.g. Punjab, Sindh">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="ss-form-group">
                                <label for="modal_postal_code">Postal Code</label>
                                <input type="text" id="modal_postal_code" name="postal_code" class="form-control ss-form-control" placeholder="54000">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="ss-form-group">
                                <label for="modal_country">Country</label>
                                <input type="text" id="modal_country" name="country" class="form-control ss-form-control" value="Pakistan" readonly>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="modal_is_default" <?= empty($addresses) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold small" for="modal_is_default">
                                    Set as default shipping address
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ss-primary px-4">Save Address</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
