<?php
/** ShopSphere - Seller Coupons */
$page_title = 'Promotional Coupons'; $current_page = 'coupons';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/CouponService.php';

$couponService = new CouponService();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $type = $_POST['type'] ?? 'percentage';
        $value = (float)($_POST['value'] ?? 0);
        $min_order = (float)($_POST['min_order_amount'] ?? 0);
        $max_discount = !empty($_POST['max_discount']) ? (float)$_POST['max_discount'] : null;
        $starts_at = $_POST['starts_at'] ?? date('Y-m-d');
        $expires_at = $_POST['expires_at'] ?? null;

        if (empty($code)) $errors[] = 'Coupon code is required.';
        if ($value <= 0) $errors[] = 'Discount value must be positive.';

        if (empty($errors)) {
            try {
                $data = [
                    'code' => $code, 'type' => $type, 'value' => $value,
                    'min_order_amount' => $min_order, 'max_discount' => $max_discount,
                    'starts_at' => $starts_at, 'expires_at' => $expires_at,
                    'seller_id' => $seller_id, 'is_active' => 1
                ];
                $couponService->createCoupon($data, $seller_id);
                flash('success', 'Coupon "' . $code . '" created.');
                redirect(url('/seller/coupons.php'));
            } catch (Exception $e) { $errors[] = $e->getMessage(); }
        }
    } elseif ($action === 'toggle') {
        $coupon_id = (int)($_POST['coupon_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $couponService->toggleCoupon($coupon_id, $is_active);
        flash('success', 'Coupon status updated.');
        redirect(url('/seller/coupons.php'));
    }
}

$coupons = $couponService->getSellerCoupons($seller_id);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Store Discount Coupons</h4>
    <button class="btn btn-ss-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#createCouponForm">
        <i class="bi bi-plus-lg"></i> Create Coupon
    </button>
</div>

<?php if (!empty($errors)): ?>
    <div class="ss-alert ss-alert-danger mb-4"><i class="bi bi-exclamation-triangle"></i><div><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<!-- Create Coupon Form -->
<div class="collapse mb-4" id="createCouponForm">
    <div class="ss-card p-4">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="row g-3">
                <div class="col-md-4 ss-form-group">
                    <label>Coupon Code</label>
                    <input type="text" name="code" class="ss-form-control" required placeholder="e.g. SUMMER20">
                </div>
                <div class="col-md-4 ss-form-group">
                    <label>Discount Type</label>
                    <select name="type" class="ss-form-control">
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount (Rs.)</option>
                    </select>
                </div>
                <div class="col-md-4 ss-form-group">
                    <label>Discount Value</label>
                    <input type="number" step="0.01" name="value" class="ss-form-control" required placeholder="10">
                </div>
                <div class="col-md-4 ss-form-group">
                    <label>Minimum Order (Rs.)</label>
                    <input type="number" step="0.01" name="min_order_amount" class="ss-form-control" value="0">
                </div>
                <div class="col-md-4 ss-form-group">
                    <label>Max Discount Cap (Rs.)</label>
                    <input type="number" step="0.01" name="max_discount" class="ss-form-control" placeholder="Optional">
                </div>
                <div class="col-md-4 ss-form-group">
                    <label>Expires At</label>
                    <input type="date" name="expires_at" class="ss-form-control">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-ss-primary btn-sm"><i class="bi bi-check"></i> Create Coupon</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="ss-card">
    <div class="ss-card-body p-0">
        <?php if (empty($coupons)): ?>
            <div class="py-5 text-center text-ss-muted"><i class="bi bi-ticket-perforated fs-1 d-block mb-2"></i><h5>No coupons created</h5></div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Min Order</th><th>Used</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($coupons as $c): ?>
                            <tr>
                                <td><code class="fw-bold"><?php echo e($c['code']); ?></code></td>
                                <td><?php echo ucfirst($c['type']); ?></td>
                                <td><?php echo $c['type'] === 'percentage' ? $c['value'] . '%' : format_price($c['value']); ?></td>
                                <td><?php echo format_price($c['min_order_amount']); ?></td>
                                <td><?php echo $c['used_count']; ?></td>
                                <td><?php echo $c['expires_at'] ? format_date($c['expires_at'], 'M d, Y') : 'No Expiry'; ?></td>
                                <td><span class="ss-badge ss-badge-<?php echo $c['is_active'] ? 'success' : 'danger'; ?>"><?php echo $c['is_active'] ? 'Active' : 'Disabled'; ?></span></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="coupon_id" value="<?php echo $c['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $c['is_active'] ? 0 : 1; ?>">
                                        <button type="submit" class="btn btn-sm btn-light border"><?php echo $c['is_active'] ? 'Disable' : 'Enable'; ?></button>
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

<?php require_once __DIR__ . '/footer.php'; ?>
