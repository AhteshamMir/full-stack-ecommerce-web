<?php
/**
 * ShopSphere - Seller Store Settings
 */
$page_title = 'Store Profile & Branding';
$current_page = 'store-settings';
require_once __DIR__ . '/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $store_name = trim($_POST['store_name'] ?? '');
    $business_name = trim($_POST['business_name'] ?? '');
    $business_address = trim($_POST['business_address'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $store_description = trim($_POST['store_description'] ?? '');

    if (empty($store_name)) $errors[] = 'Store Name cannot be empty.';

    if (empty($errors)) {
        try {
            $logo_path = $seller['store_logo'];
            $banner_path = $seller['store_banner'];

            if (!empty($_FILES['store_logo']['name'])) {
                $upload = upload_image($_FILES['store_logo'], 'sellers');
                if ($upload['success']) $logo_path = $upload['path'];
            }

            if (!empty($_FILES['store_banner']['name'])) {
                $upload = upload_image($_FILES['store_banner'], 'sellers');
                if ($upload['success']) $banner_path = $upload['path'];
            }

            $stmt = $db->prepare("UPDATE seller_profiles SET 
                                  store_name = ?, business_name = ?, business_address = ?, tax_id = ?, store_description = ?, store_logo = ?, store_banner = ? 
                                  WHERE user_id = ?");
            $stmt->execute([$store_name, $business_name, $business_address, $tax_id, $store_description, $logo_path, $banner_path, $seller_id]);

            flash('success', 'Store settings and branding updated.');
            redirect(url('/seller/store-settings.php'));
        } catch (Exception $e) {
            $errors[] = 'Failed to update store settings: ' . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Merchant Store Profile</h4>
            <a href="<?php echo url('/seller-store.php?id=' . $seller_id); ?>" target="_blank" class="btn btn-ss-outline btn-sm">
                <i class="bi bi-box-arrow-up-right"></i> View Public Storefront
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="ss-alert ss-alert-danger mb-4">
                <i class="bi bi-exclamation-triangle"></i>
                <div>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo e($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="ss-card p-4">
            <form action="<?php echo url('/seller/store-settings.php'); ?>" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <div class="row g-3">
                    <div class="col-md-6 ss-form-group">
                        <label for="store_name">Store Name <span class="required">*</span></label>
                        <input type="text" id="store_name" name="store_name" class="ss-form-control" required value="<?php echo e($seller['store_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="business_name">Registered Legal Entity / Business Name</label>
                        <input type="text" id="business_name" name="business_name" class="ss-form-control" value="<?php echo e($seller['business_name'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="tax_id">NTN / Tax Registration #</label>
                        <input type="text" id="tax_id" name="tax_id" class="ss-form-control" value="<?php echo e($seller['tax_id'] ?? ''); ?>" placeholder="Optional NTN">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="business_address">Physical Business Location</label>
                        <input type="text" id="business_address" name="business_address" class="ss-form-control" value="<?php echo e($seller['business_address'] ?? ''); ?>">
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="store_description">Store About Summary & Policies</label>
                        <textarea id="store_description" name="store_description" rows="3" class="ss-form-control"><?php echo e($seller['store_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="store_logo">Store Logo Image</label>
                        <input type="file" id="store_logo" name="store_logo" class="ss-form-control ss-file-upload" accept="image/*">
                        <?php if (!empty($seller['store_logo'])): ?>
                            <div class="mt-2"><img src="<?php echo asset($seller['store_logo']); ?>" alt="" style="height: 50px;"></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="store_banner">Storefront Hero Banner</label>
                        <input type="file" id="store_banner" name="store_banner" class="ss-form-control ss-file-upload" accept="image/*">
                        <?php if (!empty($seller['store_banner'])): ?>
                            <div class="mt-2"><img src="<?php echo asset($seller['store_banner']); ?>" alt="" style="height: 50px;"></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-12 mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-ss-primary px-4">
                            <i class="bi bi-save"></i> Save Store Profile
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
