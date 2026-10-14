<?php
/**
 * ShopSphere - Coupon Engine & Promotional Vouchers
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/helpers.php';

require_role('admin');
$db = get_db();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $type = in_array($_POST['type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['type'] : 'percentage';
        $value = (float)($_POST['value'] ?? 0);
        $minOrder = (float)($_POST['min_order_amount'] ?? 0);
        $maxDiscount = !empty($_POST['max_discount']) ? (float)$_POST['max_discount'] : null;
        $usageLimit = !empty($_POST['usage_limit']) ? (int)$_POST['usage_limit'] : null;
        $perUserLimit = max(1, (int)($_POST['per_user_limit'] ?? 1));
        $sellerId = !empty($_POST['seller_id']) ? (int)$_POST['seller_id'] : null;
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $firstOrderOnly = isset($_POST['first_order_only']) ? 1 : 0;
        $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : date('Y-m-d H:i:s');
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : date('Y-m-d H:i:s', strtotime('+30 days'));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($code === '' || $value <= 0) {
            flash('error', 'Valid coupon code and discount value are required.');
            redirect(url('/admin/coupons.php'));
        }

        // Unique Code Check
        $stmt = $db->prepare("SELECT COUNT(*) FROM coupons WHERE code = :code");
        $stmt->execute(['code' => $code]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash('error', "Coupon code '{$code}' already exists. Please choose a unique code.");
            redirect(url('/admin/coupons.php'));
        }

        $stmt = $db->prepare("
            INSERT INTO coupons (code, type, value, min_order_amount, max_discount, usage_limit, per_user_limit, seller_id, category_id, first_order_only, is_active, starts_at, expires_at, created_by, created_at, updated_at)
            VALUES (:code, :type, :value, :min_order, :max_discount, :usage_limit, :per_user_limit, :seller_id, :category_id, :first_order, :is_active, :starts_at, :expires_at, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            'code'           => $code,
            'type'           => $type,
            'value'          => $value,
            'min_order'      => $minOrder,
            'max_discount'   => $maxDiscount,
            'usage_limit'    => $usageLimit,
            'per_user_limit' => $perUserLimit,
            'seller_id'      => $sellerId,
            'category_id'    => $categoryId,
            'first_order'    => $firstOrderOnly,
            'is_active'      => $isActive,
            'starts_at'      => $startsAt,
            'expires_at'     => $expiresAt,
            'created_by'     => get_current_user_id()
        ]);
        $couponId = (int)$db->lastInsertId();

        log_audit($db, 'create_coupon', 'coupons', $couponId, null, ['code' => $code, 'value' => $value, 'type' => $type]);
        flash('success', "Coupon '{$code}' created successfully.");
        redirect(url('/admin/coupons.php'));

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, is_active, code FROM coupons WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $cp = $stmt->fetch();

        if ($cp) {
            $newActive = $cp['is_active'] ? 0 : 1;
            $db->prepare("UPDATE coupons SET is_active = :a, updated_at = NOW() WHERE id = :id")->execute(['a' => $newActive, 'id' => $id]);
            log_audit($db, 'toggle_coupon_active', 'coupons', $id, ['is_active' => $cp['is_active']], ['is_active' => $newActive]);
            flash('success', "Coupon '{$cp['code']}' status toggled.");
        }

        redirect(url('/admin/coupons.php'));

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM coupons WHERE id = :id");
        $stmt->execute(['id' => $id]);

        log_audit($db, 'delete_coupon', 'coupons', $id);
        flash('success', 'Coupon deleted successfully.');
        redirect(url('/admin/coupons.php'));
    }
}

// Fetch Coupons List with Metadata
$stmt = $db->query("
    SELECT c.*, 
        sp.store_name, 
        cat.name AS category_name,
        u.first_name, u.last_name
    FROM coupons c
    LEFT JOIN seller_profiles sp ON c.seller_id = sp.user_id
    LEFT JOIN categories cat ON c.category_id = cat.id
    LEFT JOIN users u ON c.created_by = u.id
    ORDER BY c.id DESC
");
$coupons = $stmt->fetchAll();

// Dropdowns for form
$sellersList = $db->query("SELECT user_id, store_name FROM seller_profiles WHERE status = 'approved' ORDER BY store_name ASC")->fetchAll();
$categoriesList = $db->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$page_title = 'Promo Coupons Engine';
$current_page = 'coupons';
require_once __DIR__ . '/header.php';
?>

<div class="row g-4 mb-4">
    <!-- Left Column: Create Coupon Form -->
    <div class="col-lg-4">
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-ticket-perforated text-primary me-2"></i> Create Promo Coupon</h5>
            </div>
            <div class="ss-card-body">
                <form method="POST" action="<?php echo url('/admin/coupons.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Coupon Code <span class="text-danger">*</span></label>
                        <input type="text" name="code" class="form-control text-uppercase" placeholder="e.g. SUMMER50, WELCOME10" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Discount Type</label>
                            <select name="type" class="form-select" required>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (PKR)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Value <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="value" class="form-control" placeholder="10 or 500" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Min Order (PKR)</label>
                            <input type="number" step="0.01" min="0" name="min_order_amount" class="form-control" value="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Max Discount (PKR)</label>
                            <input type="number" step="0.01" min="0" name="max_discount" class="form-control" placeholder="Optional cap">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Total Usage Limit</label>
                            <input type="number" min="1" name="usage_limit" class="form-control" placeholder="Unlimited">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Per User Limit</label>
                            <input type="number" min="1" name="per_user_limit" class="form-control" value="1">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Store Restriction (Optional)</label>
                        <select name="seller_id" class="form-select">
                            <option value="">— Entire Platform (All Sellers) —</option>
                            <?php foreach ($sellersList as $s): ?>
                                <option value="<?php echo $s['user_id']; ?>"><?php echo e($s['store_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Category Restriction (Optional)</label>
                        <select name="category_id" class="form-select">
                            <option value="">— All Categories —</option>
                            <?php foreach ($categoriesList as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo e($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Starts At</label>
                            <input type="datetime-local" name="starts_at" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Expires At</label>
                            <input type="datetime-local" name="expires_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime('+30 days')); ?>">
                        </div>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="first_order_only" id="firstOrderOnly" value="1">
                        <label class="form-check-label" for="firstOrderOnly">First order only for new customers</label>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="couponActive" checked value="1">
                        <label class="form-check-label" for="couponActive">Enable coupon immediately</label>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100">
                        <i class="bi bi-plus-circle"></i> Create Coupon
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Coupons Directory -->
    <div class="col-lg-8">
        <div class="ss-card">
            <div class="ss-card-header d-flex justify-content-between align-items-center">
                <h5>Active & Scheduled Coupons (<?php echo count($coupons); ?>)</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($coupons)): ?>
                    <div class="p-5 text-center text-ss-muted">
                        <i class="bi bi-ticket-perforated fs-1 d-block mb-2"></i>
                        No coupons found. Create one using the form on the left.
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Code & Value</th>
                                    <th>Scope / Scope</th>
                                    <th>Usage</th>
                                    <th>Validity</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coupons as $cp): ?>
                                    <?php 
                                    $isExpired = strtotime($cp['expires_at']) < time();
                                    $isPending = strtotime($cp['starts_at']) > time();
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-primary fs-6 px-2 py-1 font-monospace"><?php echo e($cp['code']); ?></span>
                                            </div>
                                            <strong class="text-success d-block mt-1">
                                                <?php echo $cp['type'] === 'percentage' ? number_format((float)$cp['value'], 1) . '% OFF' : format_price($cp['value']) . ' OFF'; ?>
                                            </strong>
                                            <div class="small text-ss-muted">Min: <?php echo format_price($cp['min_order_amount']); ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($cp['store_name'])): ?>
                                                <div class="small fw-semibold text-primary"><i class="bi bi-shop"></i> <?php echo e($cp['store_name']); ?></div>
                                            <?php elseif (!empty($cp['category_name'])): ?>
                                                <div class="small fw-semibold text-info"><i class="bi bi-tag"></i> <?php echo e($cp['category_name']); ?></div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">Platform Wide</span>
                                            <?php endif; ?>
                                            <?php if (!empty($cp['first_order_only'])): ?>
                                                <div class="small text-warning"><i class="bi bi-star"></i> 1st Order Only</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo (int)$cp['used_count']; ?></strong> 
                                            <span class="text-ss-muted">/ <?php echo $cp['usage_limit'] ? (int)$cp['usage_limit'] : '&infin;'; ?></span>
                                            <div class="small text-ss-muted">Limit: <?php echo (int)$cp['per_user_limit']; ?>/user</div>
                                        </td>
                                        <td>
                                            <div class="small text-dark"><?php echo format_date($cp['starts_at'], 'M d, Y'); ?></div>
                                            <div class="small text-ss-muted">to <?php echo format_date($cp['expires_at'], 'M d, Y'); ?></div>
                                            <?php if ($isExpired): ?>
                                                <span class="badge bg-danger mt-1">Expired</span>
                                            <?php elseif ($isPending): ?>
                                                <span class="badge bg-secondary mt-1">Scheduled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="<?php echo url('/admin/coupons.php'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?php echo (int)$cp['id']; ?>">
                                                <button type="submit" class="border-0 bg-transparent p-0" title="Click to toggle status">
                                                    <span class="ss-badge ss-badge-<?php echo ($cp['is_active'] && !$isExpired) ? 'success' : 'default'; ?>" style="cursor: pointer;">
                                                        <?php echo $cp['is_active'] ? 'Active' : 'Disabled'; ?>
                                                    </span>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="<?php echo url('/admin/coupons.php'); ?>" onsubmit="return confirm('Delete coupon <?php echo e($cp['code']); ?>?');" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$cp['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Coupon">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
