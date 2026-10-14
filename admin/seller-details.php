<?php
/**
 * ShopSphere - Seller Details & Commission Control
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

$sellerId = (int)($_GET['id'] ?? $_POST['seller_id'] ?? 0);

if ($sellerId <= 0) {
    flash('error', 'A valid seller ID is required.');
    redirect(url('/admin/sellers.php'));
}

// Fetch seller profile with user details
$stmt = $db->prepare("
    SELECT sp.*, u.first_name, u.last_name, u.email, u.phone, u.status AS user_status, u.created_at AS user_registered_at
    FROM seller_profiles sp
    JOIN users u ON sp.user_id = u.id
    WHERE sp.user_id = :id
");
$stmt->execute(['id' => $sellerId]);
$seller = $stmt->fetch();

if (!$seller) {
    flash('error', 'Seller profile not found.');
    redirect(url('/admin/sellers.php'));
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_commission') {
        $rateInput = trim($_POST['commission_rate'] ?? '');
        $newRate = ($rateInput === '' || $rateInput === null) ? null : (float)$rateInput;

        if ($newRate !== null && ($newRate < 0 || $newRate > 100)) {
            flash('error', 'Commission rate must be between 0% and 100%.');
            redirect(url('/admin/seller-details.php?id=' . $sellerId));
        }

        $stmt = $db->prepare("UPDATE seller_profiles SET commission_rate = :rate, updated_at = NOW() WHERE user_id = :id");
        $stmt->execute(['rate' => $newRate, 'id' => $sellerId]);

        log_audit(
            $db,
            'update_seller_commission',
            'seller_profiles',
            $sellerId,
            ['commission_rate' => $seller['commission_rate']],
            ['commission_rate' => $newRate]
        );

        flash('success', 'Custom seller commission override updated successfully.');
        redirect(url('/admin/seller-details.php?id=' . $sellerId));

    } elseif ($action === 'set_status') {
        $status = $_POST['status'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if (!in_array($status, ['approved', 'rejected', 'suspended', 'pending'], true)) {
            flash('error', 'Invalid status specified.');
            redirect(url('/admin/seller-details.php?id=' . $sellerId));
        }

        $db->beginTransaction();
        try {
            $updateSql = "UPDATE seller_profiles SET status = :status, updated_at = NOW()";
            $params = ['status' => $status, 'id' => $sellerId];

            if ($status === 'approved') {
                $updateSql .= ", approved_at = NOW(), verified = 1, rejection_reason = NULL, suspension_reason = NULL";
                $db->prepare("UPDATE users SET role = 'seller', status = 'active' WHERE id = :id")->execute(['id' => $sellerId]);
            } elseif ($status === 'rejected') {
                $updateSql .= ", rejection_reason = :reason";
                $params['reason'] = $reason;
            } elseif ($status === 'suspended') {
                $updateSql .= ", suspension_reason = :reason";
                $params['reason'] = $reason;
                $db->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $sellerId]);
            }

            $updateSql .= " WHERE user_id = :id";
            $stmt = $db->prepare($updateSql);
            $stmt->execute($params);

            // Audit Log
            log_audit(
                $db,
                'change_seller_status',
                'seller_profiles',
                $sellerId,
                ['status' => $seller['status']],
                ['status' => $status, 'reason' => $reason]
            );

            // Notification
            $notif = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, created_at)
                VALUES (:user_id, 'account', :title, :msg, '/seller/dashboard.php', NOW())
            ");
            $notif->execute([
                'user_id' => $sellerId,
                'title' => 'Store Status Updated: ' . ucfirst($status),
                'msg' => "Your store status has been updated to {$status}. " . ($reason ? "Note: {$reason}" : "")
            ]);

            $db->commit();
            flash('success', "Store status has been set to {$status}.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Error updating seller status: ' . $e->getMessage());
        }

        redirect(url('/admin/seller-details.php?id=' . $sellerId));

    } elseif ($action === 'toggle_verified') {
        $newVerified = empty($seller['verified']) ? 1 : 0;
        $db->prepare("UPDATE seller_profiles SET verified = :v, updated_at = NOW() WHERE user_id = :id")
           ->execute(['v' => $newVerified, 'id' => $sellerId]);

        log_audit(
            $db,
            'toggle_seller_verified',
            'seller_profiles',
            $sellerId,
            ['verified' => $seller['verified']],
            ['verified' => $newVerified]
        );

        flash('success', 'Seller verification badge ' . ($newVerified ? 'enabled.' : 'removed.'));
        redirect(url('/admin/seller-details.php?id=' . $sellerId));
    }
}

// Seller Financial Aggregates
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(subtotal), 0) AS gross_sales,
        COALESCE(SUM(commission_amount), 0) AS platform_commission,
        COALESCE(SUM(seller_earning), 0) AS net_earnings,
        COUNT(*) AS total_orders
    FROM seller_orders
    WHERE seller_id = :id AND status != 'cancelled'
");
$stmt->execute(['id' => $sellerId]);
$financials = $stmt->fetch();

$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS paid_out,
        COALESCE(SUM(CASE WHEN status IN ('pending', 'approved', 'processing') THEN amount ELSE 0 END), 0) AS pending_payout
    FROM payouts
    WHERE seller_id = :id
");
$stmt->execute(['id' => $sellerId]);
$payoutStats = $stmt->fetch();

$availableBalance = max(0, (float)$financials['net_earnings'] - (float)$payoutStats['paid_out'] - (float)$payoutStats['pending_payout']);

// Products List (Top 10)
$stmt = $db->prepare("
    SELECT p.*, c.name AS category_name,
        (SELECT image_path FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS primary_image
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.seller_id = :id
    ORDER BY p.id DESC
    LIMIT 10
");
$stmt->execute(['id' => $sellerId]);
$sellerProducts = $stmt->fetchAll();

// Total Products Count
$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE seller_id = :id");
$stmt->execute(['id' => $sellerId]);
$totalProductCount = (int)$stmt->fetchColumn();

// Recent Orders (Top 10)
$stmt = $db->prepare("
    SELECT so.*, o.order_number, o.created_at AS order_date, u.first_name, u.last_name
    FROM seller_orders so
    JOIN orders o ON so.order_id = o.id
    JOIN users u ON o.user_id = u.id
    WHERE so.seller_id = :id
    ORDER BY so.id DESC
    LIMIT 10
");
$stmt->execute(['id' => $sellerId]);
$sellerOrders = $stmt->fetchAll();

// Recent Payout Requests (Top 10)
$stmt = $db->prepare("
    SELECT * FROM payouts WHERE seller_id = :id ORDER BY id DESC LIMIT 10
");
$stmt->execute(['id' => $sellerId]);
$sellerPayouts = $stmt->fetchAll();

$page_title = 'Seller Profile: ' . $seller['store_name'];
$current_page = 'sellers';
require_once __DIR__ . '/header.php';
?>

<!-- Header with Back Button & Status Actions -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <a href="<?php echo url('/admin/sellers.php'); ?>" class="btn btn-light border btn-sm mb-2">
            <i class="bi bi-arrow-left"></i> Back to All Sellers
        </a>
        <h2 class="h4 mb-1 d-flex align-items-center gap-2">
            <?php echo e($seller['store_name']); ?>
            <?php if (!empty($seller['verified'])): ?>
                <span class="badge bg-primary fs-6" title="Verified Badge"><i class="bi bi-patch-check-fill"></i> Verified</span>
            <?php endif; ?>
            <span class="ss-badge ss-badge-<?php echo match($seller['status']) {
                'approved' => 'success',
                'pending' => 'warning',
                'suspended' => 'danger',
                'rejected' => 'default',
                default => 'default'
            }; ?> fs-6">
                <?php echo ucfirst($seller['status']); ?>
            </span>
        </h2>
        <div class="text-ss-muted small">
            Store Slug: <code><?php echo e($seller['store_slug']); ?></code> &bull; Member since <?php echo format_date($seller['created_at'], 'M d, Y'); ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <!-- Verified Toggle Form -->
        <form method="POST" action="<?php echo url('/admin/seller-details.php?id=' . $sellerId); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="toggle_verified">
            <button type="submit" class="btn btn-light border btn-sm">
                <i class="bi bi-patch-check"></i> <?php echo !empty($seller['verified']) ? 'Revoke Verified' : 'Mark Verified'; ?>
            </button>
        </form>

        <!-- Status Change Modal Trigger -->
        <button type="button" class="btn btn-ss-primary btn-sm" data-bs-toggle="modal" data-bs-target="#statusModal">
            <i class="bi bi-sliders"></i> Change Status
        </button>
    </div>
</div>

<!-- Financial Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-currency-dollar"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($financials['gross_sales']); ?></h3>
                <p>Gross Sales GMV</p>
                <div class="small text-ss-muted"><?php echo (int)$financials['total_orders']; ?> packages shipped</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-wallet2"></i></div>
            <div class="ss-stat-info">
                <h3 class="text-primary"><?php echo format_price($financials['net_earnings']); ?></h3>
                <p>Total Net Earnings</p>
                <div class="small text-ss-muted">After platform commission</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-percent"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($financials['platform_commission']); ?></h3>
                <p>Platform Commission</p>
                <div class="small text-ss-muted">Retained platform fee</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-cash-stack"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($availableBalance); ?></h3>
                <p>Available Balance</p>
                <div class="small text-ss-muted"><?php echo format_price($payoutStats['paid_out']); ?> disbursed</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Store Profile & Business Details -->
    <div class="col-lg-7">
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5>Business & Store Profile</h5>
            </div>
            <div class="ss-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Legal Business Name</label>
                        <p class="fw-bold mb-0"><?php echo e($seller['business_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Tax Registration / NTN</label>
                        <p class="fw-bold mb-0"><?php echo e($seller['tax_id'] ?: 'N/A'); ?></p>
                    </div>
                    <div class="col-12">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Business Address</label>
                        <p class="mb-0 text-dark"><?php echo nl2br(e($seller['business_address'])); ?></p>
                    </div>
                    <hr class="my-2">
                    <div class="col-md-6">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Applicant Name</label>
                        <p class="fw-bold mb-0"><?php echo e($seller['first_name'] . ' ' . $seller['last_name']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Account Email</label>
                        <p class="mb-0"><a href="mailto:<?php echo e($seller['email']); ?>"><?php echo e($seller['email']); ?></a></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Phone Contact</label>
                        <p class="mb-0"><?php echo e($seller['phone'] ?: 'Not provided'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <label class="text-ss-muted small text-uppercase fw-semibold">Store Rating</label>
                        <p class="mb-0 text-warning fw-bold">
                            <i class="bi bi-star-fill"></i> <?php echo number_format((float)$seller['rating_avg'], 1); ?> / 5.0
                        </p>
                    </div>
                    <?php if (!empty($seller['store_description'])): ?>
                        <div class="col-12">
                            <label class="text-ss-muted small text-uppercase fw-semibold">Store Description</label>
                            <p class="text-ss-muted small mb-0"><?php echo nl2br(e($seller['store_description'])); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($seller['rejection_reason'])): ?>
                        <div class="col-12">
                            <div class="alert alert-danger mb-0 small">
                                <strong>Rejection Reason:</strong> <?php echo e($seller['rejection_reason']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($seller['suspension_reason'])): ?>
                        <div class="col-12">
                            <div class="alert alert-warning mb-0 small">
                                <strong>Suspension Reason:</strong> <?php echo e($seller['suspension_reason']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Commission Rate Override Form -->
    <div class="col-lg-5">
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5>Commission Rate Override</h5>
            </div>
            <div class="ss-card-body">
                <p class="small text-ss-muted">
                    Set a merchant-specific commission percentage. If set, this overrides default category and platform commission rates on all orders for this vendor.
                </p>
                <form method="POST" action="<?php echo url('/admin/seller-details.php?id=' . $sellerId); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_commission">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Custom Commission Rate (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" name="commission_rate" class="form-control" 
                                placeholder="Leave empty for category default" 
                                value="<?php echo $seller['commission_rate'] !== null ? e((string)$seller['commission_rate']) : ''; ?>">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text small">
                            Current effective status: 
                            <strong>
                                <?php echo $seller['commission_rate'] !== null ? number_format((float)$seller['commission_rate'], 2) . '% (Vendor Override)' : 'Using Category Defaults'; ?>
                            </strong>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-ss-primary w-100">
                        <i class="bi bi-check2"></i> Save Commission Rate
                    </button>
                </form>
            </div>
        </div>

        <div class="ss-card">
            <div class="ss-card-header">
                <h5>Storefront Link</h5>
            </div>
            <div class="ss-card-body">
                <p class="small text-ss-muted mb-3">View the public customer-facing store page.</p>
                <a href="<?php echo url('/seller-store.php?slug=' . urlencode($seller['store_slug'])); ?>" target="_blank" class="btn btn-ss-outline w-100">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Visit Public Store Page
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabs: Products, Orders, Payouts -->
<div class="ss-card">
    <div class="ss-card-header p-0">
        <ul class="nav nav-tabs border-0 px-3 pt-2" id="sellerTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" id="products-tab" data-bs-toggle="tab" data-bs-target="#products-pane" type="button" role="tab">
                    <i class="bi bi-box-seam me-1"></i> Catalog Products (<?php echo $totalProductCount; ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="orders-tab" data-bs-toggle="tab" data-bs-target="#orders-pane" type="button" role="tab">
                    <i class="bi bi-bag-check me-1"></i> Recent Orders (<?php echo count($sellerOrders); ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" id="payouts-tab" data-bs-toggle="tab" data-bs-target="#payouts-pane" type="button" role="tab">
                    <i class="bi bi-cash-coin me-1"></i> Payout History (<?php echo count($sellerPayouts); ?>)
                </button>
            </li>
        </ul>
    </div>
    <div class="tab-content" id="sellerTabContent">
        <!-- Products Tab Pane -->
        <div class="tab-pane fade show active p-0" id="products-pane" role="tabpanel">
            <?php if (empty($sellerProducts)): ?>
                <div class="p-4 text-center text-ss-muted">
                    No products listed by this seller yet.
                </div>
            <?php else: ?>
                <div class="ss-table-responsive">
                    <table class="ss-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Sales</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sellerProducts as $p): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded border bg-light d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; overflow: hidden;">
                                                <?php if (!empty($p['primary_image'])): ?>
                                                    <img src="<?php echo asset($p['primary_image']); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                                <?php else: ?>
                                                    <i class="bi bi-image text-muted"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong class="d-block text-dark"><?php echo e($p['name']); ?></strong>
                                                <span class="small text-ss-muted">SKU: <?php echo e($p['sku'] ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo e($p['category_name']); ?></td>
                                    <td><strong><?php echo format_price($p['price']); ?></strong></td>
                                    <td>
                                        <?php if ($p['stock_quantity'] <= $p['low_stock_threshold']): ?>
                                            <span class="badge bg-danger"><?php echo $p['stock_quantity']; ?> left</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border"><?php echo $p['stock_quantity']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$p['sales_count']; ?> units</td>
                                    <td>
                                        <span class="ss-badge ss-badge-<?php echo match($p['status']) {
                                            'approved' => 'success',
                                            'pending' => 'warning',
                                            'rejected' => 'danger',
                                            default => 'default'
                                        }; ?>">
                                            <?php echo ucfirst($p['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?php echo url('/product.php?slug=' . urlencode($p['slug'])); ?>" target="_blank" class="btn btn-light border btn-sm">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Orders Tab Pane -->
        <div class="tab-pane fade p-0" id="orders-pane" role="tabpanel">
            <?php if (empty($sellerOrders)): ?>
                <div class="p-4 text-center text-ss-muted">
                    No order packages recorded for this seller.
                </div>
            <?php else: ?>
                <div class="ss-table-responsive">
                    <table class="ss-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Package Subtotal</th>
                                <th>Platform Fee</th>
                                <th>Net Earning</th>
                                <th>Package Status</th>
                                <th class="text-end">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sellerOrders as $so): ?>
                                <tr>
                                    <td><strong><?php echo e($so['order_number']); ?></strong></td>
                                    <td><?php echo e($so['first_name'] . ' ' . $so['last_name']); ?></td>
                                    <td><?php echo format_date($so['order_date'], 'M d, Y'); ?></td>
                                    <td><strong><?php echo format_price($so['subtotal']); ?></strong></td>
                                    <td class="text-danger">-<?php echo format_price($so['commission_amount']); ?></td>
                                    <td class="text-success fw-bold"><?php echo format_price($so['seller_earning']); ?></td>
                                    <td>
                                        <span class="ss-badge ss-badge-<?php echo match($so['status']) {
                                            'delivered' => 'success',
                                            'shipped' => 'info',
                                            'processing' => 'warning',
                                            'cancelled' => 'danger',
                                            default => 'default'
                                        }; ?>">
                                            <?php echo ucfirst($so['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?php echo url('/admin/order-details.php?id=' . $so['order_id']); ?>" class="btn btn-light border btn-sm">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payouts Tab Pane -->
        <div class="tab-pane fade p-0" id="payouts-pane" role="tabpanel">
            <?php if (empty($sellerPayouts)): ?>
                <div class="p-4 text-center text-ss-muted">
                    No payout withdrawal requests found.
                </div>
            <?php else: ?>
                <div class="ss-table-responsive">
                    <table class="ss-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Payout ID</th>
                                <th>Requested Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Status</th>
                                <th>Completed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sellerPayouts as $pay): ?>
                                <tr>
                                    <td><strong>#<?php echo $pay['id']; ?></strong></td>
                                    <td><?php echo format_date($pay['requested_at'], 'M d, Y h:i A'); ?></td>
                                    <td><strong class="text-dark"><?php echo format_price($pay['amount']); ?></strong></td>
                                    <td><?php echo strtoupper(str_replace('_', ' ', $pay['method'])); ?></td>
                                    <td><code><?php echo e($pay['reference'] ?? 'Pending'); ?></code></td>
                                    <td>
                                        <span class="ss-badge ss-badge-<?php echo match($pay['status']) {
                                            'completed' => 'success',
                                            'approved', 'processing' => 'info',
                                            'pending' => 'warning',
                                            'rejected' => 'danger',
                                            default => 'default'
                                        }; ?>">
                                            <?php echo ucfirst($pay['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $pay['completed_at'] ? format_date($pay['completed_at'], 'M d, Y') : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Change Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('/admin/seller-details.php?id=' . $sellerId); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="set_status">
                <div class="modal-header">
                    <h5 class="modal-title">Change Store Status: <?php echo e($seller['store_name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select New Status</label>
                        <select name="status" class="form-select" required>
                            <option value="approved" <?php echo $seller['status'] === 'approved' ? 'selected' : ''; ?>>Approved (Active Trading)</option>
                            <option value="pending" <?php echo $seller['status'] === 'pending' ? 'selected' : ''; ?>>Pending (Under Review)</option>
                            <option value="suspended" <?php echo $seller['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended (Halted)</option>
                            <option value="rejected" <?php echo $seller['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected (Declined)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason / Administrative Note</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Optional for approval, recommended for suspension or rejection..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ss-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
