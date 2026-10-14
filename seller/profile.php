<?php
/** ShopSphere - Seller Profile */
$page_title = 'My Profile'; $current_page = 'profile';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/validation.php';

$stmt = $db->prepare("SELECT u.*, sp.* FROM users u LEFT JOIN seller_profiles sp ON u.id = sp.user_id WHERE u.id = ?");
$stmt->execute([$seller_id]);
$seller = $stmt->fetch();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($first_name)) $errors[] = 'First name is required.';
    if (empty($last_name)) $errors[] = 'Last name is required.';

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$first_name, $last_name, $phone, $seller_id]);
            flash('success', 'Profile updated successfully.');
            redirect(url('/seller/profile.php'));
        } catch (Exception $e) {
            $errors[] = 'Update failed. Please try again.';
        }
    }
}
?>

<h4 class="mb-4">My Profile</h4>

<?php if (!empty($errors)): ?>
    <div class="ss-alert ss-alert-danger mb-4"><i class="bi bi-exclamation-triangle"></i><div><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="ss-card text-center p-4">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #4f6df5, #1e2a4a); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 700; margin: 0 auto 1rem;">
                <?php echo strtoupper(substr($seller['first_name'] ?? 'S', 0, 1) . substr($seller['last_name'] ?? 'S', 0, 1)); ?>
            </div>
            <h5 class="mb-1"><?php echo e(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')); ?></h5>
            <p class="small text-ss-muted mb-2"><?php echo e($seller['email'] ?? ''); ?></p>
            <span class="ss-badge ss-badge-<?php echo ($seller['status'] ?? '') === 'approved' ? 'success' : 'warning'; ?>"><?php echo ucfirst($seller['status'] ?? 'unknown'); ?></span>
            <hr>
            <p class="small text-ss-muted mb-0">Member since <?php echo format_date($seller['created_at'] ?? date('Y-m-d'), 'F Y'); ?></p>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="ss-card p-4">
            <h5 class="mb-3">Personal Information</h5>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="row g-3">
                    <div class="col-md-6 ss-form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" class="ss-form-control" value="<?php echo e($seller['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 ss-form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" class="ss-form-control" value="<?php echo e($seller['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 ss-form-group">
                        <label>Email</label>
                        <input type="email" class="ss-form-control" value="<?php echo e($seller['email'] ?? ''); ?>" readonly>
                        <small class="text-ss-muted">Email cannot be changed.</small>
                    </div>
                    <div class="col-md-6 ss-form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" class="ss-form-control" value="<?php echo e($seller['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-12 mt-3 pt-3 border-top">
                        <button type="submit" class="btn btn-ss-primary px-4 py-2"><i class="bi bi-save"></i> Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
