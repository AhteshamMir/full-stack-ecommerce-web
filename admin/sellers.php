<?php
/**
 * ShopSphere - Seller Management
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

// Handle Quick Approve / Reject / Suspend Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $action = $_POST['action'] ?? '';
    $sellerId = (int)($_POST['seller_id'] ?? 0);

    if ($sellerId <= 0) {
        flash('error', 'Invalid seller identifier.');
        redirect(url('/admin/sellers.php'));
    }

    // Verify seller exists
    $stmt = $db->prepare("SELECT sp.*, u.first_name, u.last_name, u.email FROM seller_profiles sp JOIN users u ON sp.user_id = u.id WHERE sp.user_id = :id");
    $stmt->execute(['id' => $sellerId]);
    $seller = $stmt->fetch();

    if (!$seller) {
        flash('error', 'Seller profile not found.');
        redirect(url('/admin/sellers.php'));
    }

    if ($action === 'approve') {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE seller_profiles 
                SET status = 'approved', approved_at = NOW(), verified = 1, rejection_reason = NULL, suspension_reason = NULL, updated_at = NOW() 
                WHERE user_id = :id
            ");
            $stmt->execute(['id' => $sellerId]);

            $stmtUser = $db->prepare("UPDATE users SET role = 'seller', status = 'active', updated_at = NOW() WHERE id = :id");
            $stmtUser->execute(['id' => $sellerId]);

            // Create notification for seller
            $notif = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, created_at)
                VALUES (:user_id, 'account', 'Store Application Approved!', 'Congratulations! Your merchant store has been approved. You can now start listing products.', '/seller/dashboard.php', NOW())
            ");
            $notif->execute(['user_id' => $sellerId]);

            log_audit(
                $db,
                'approve_seller',
                'seller_profiles',
                $sellerId,
                ['status' => $seller['status']],
                ['status' => 'approved', 'verified' => 1]
            );

            $db->commit();
            flash('success', "Store \"{$seller['store_name']}\" successfully approved.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Failed to approve seller: ' . $e->getMessage());
        }
        redirect(url('/admin/sellers.php'));

    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? 'Application does not meet platform requirements.');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE seller_profiles 
                SET status = 'rejected', rejection_reason = :reason, updated_at = NOW() 
                WHERE user_id = :id
            ");
            $stmt->execute(['reason' => $reason, 'id' => $sellerId]);

            $notif = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, created_at)
                VALUES (:user_id, 'account', 'Store Application Rejected', :reason, '/seller/onboarding.php', NOW())
            ");
            $notif->execute(['user_id' => $sellerId, 'reason' => "Your store application was rejected. Reason: {$reason}"]);

            log_audit(
                $db,
                'reject_seller',
                'seller_profiles',
                $sellerId,
                ['status' => $seller['status']],
                ['status' => 'rejected', 'reason' => $reason]
            );

            $db->commit();
            flash('success', "Store \"{$seller['store_name']}\" has been marked as rejected.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Failed to reject seller: ' . $e->getMessage());
        }
        redirect(url('/admin/sellers.php'));

    } elseif ($action === 'suspend') {
        $reason = trim($_POST['suspension_reason'] ?? 'Violation of marketplace terms.');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE seller_profiles 
                SET status = 'suspended', suspension_reason = :reason, updated_at = NOW() 
                WHERE user_id = :id
            ");
            $stmt->execute(['reason' => $reason, 'id' => $sellerId]);

            $stmtUser = $db->prepare("UPDATE users SET status = 'suspended', updated_at = NOW() WHERE id = :id");
            $stmtUser->execute(['id' => $sellerId]);

            $notif = $db->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, created_at)
                VALUES (:user_id, 'account', 'Store Suspended', :reason, NULL, NOW())
            ");
            $notif->execute(['user_id' => $sellerId, 'reason' => "Your seller store has been suspended: {$reason}"]);

            log_audit(
                $db,
                'suspend_seller',
                'seller_profiles',
                $sellerId,
                ['status' => $seller['status']],
                ['status' => 'suspended', 'reason' => $reason]
            );

            $db->commit();
            flash('success', "Store \"{$seller['store_name']}\" suspended.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Failed to suspend seller: ' . $e->getMessage());
        }
        redirect(url('/admin/sellers.php'));
    }
}

// Search and Filter
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(sp.store_name LIKE :search OR sp.business_name LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)";
    $params['search'] = "%{$search}%";
}

if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'suspended'], true)) {
    $where[] = "sp.status = :status";
    $params['status'] = $status;
}

$whereClause = implode(' AND ', $where);

// Total Counts
$countStmt = $db->prepare("SELECT COUNT(*) FROM seller_profiles sp JOIN users u ON sp.user_id = u.id WHERE {$whereClause}");
$countStmt->execute($params);
$totalSellers = (int)$countStmt->fetchColumn();

$pagination = paginate($totalSellers, $perPage, $page);

// Fetch Sellers
$query = "
    SELECT 
        sp.*, 
        u.first_name, u.last_name, u.email, u.phone, u.status AS user_status,
        (SELECT COUNT(*) FROM products p WHERE p.seller_id = sp.user_id) AS total_products,
        (SELECT COUNT(*) FROM seller_orders so WHERE so.seller_id = sp.user_id) AS total_orders,
        (SELECT COALESCE(SUM(so.subtotal), 0) FROM seller_orders so WHERE so.seller_id = sp.user_id) AS gross_sales
    FROM seller_profiles sp
    JOIN users u ON sp.user_id = u.id
    WHERE {$whereClause}
    ORDER BY CASE WHEN sp.status = 'pending' THEN 0 ELSE 1 END, sp.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$sellers = $stmt->fetchAll();

// Overview Metrics
$statPending = (int)$db->query("SELECT COUNT(*) FROM seller_profiles WHERE status = 'pending'")->fetchColumn();
$statApproved = (int)$db->query("SELECT COUNT(*) FROM seller_profiles WHERE status = 'approved'")->fetchColumn();
$statSuspended = (int)$db->query("SELECT COUNT(*) FROM seller_profiles WHERE status = 'suspended'")->fetchColumn();
$statRejected = (int)$db->query("SELECT COUNT(*) FROM seller_profiles WHERE status = 'rejected'")->fetchColumn();

$page_title = 'Seller & Store Management';
$current_page = 'sellers';
require_once __DIR__ . '/header.php';
?>

<!-- Metric Badges -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-clock-history"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statPending); ?></h3>
                <p>Pending Approvals</p>
                <?php if ($statPending > 0): ?>
                    <div class="small text-warning fw-semibold"><i class="bi bi-exclamation-circle"></i> Requires admin review</div>
                <?php else: ?>
                    <div class="small text-ss-muted">No pending queues</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-patch-check"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statApproved); ?></h3>
                <p>Approved Merchants</p>
                <div class="trend up"><i class="bi bi-shop"></i> Actively trading</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-slash-circle"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statSuspended); ?></h3>
                <p>Suspended Stores</p>
                <div class="small text-danger"><i class="bi bi-shield-slash"></i> Trading halted</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-x-octagon"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statRejected); ?></h3>
                <p>Rejected Applications</p>
                <div class="small text-ss-muted">Declined requests</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/sellers.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by store name, owner name, business or email..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Seller Statuses</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $statPending; ?>)</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved (<?php echo $statApproved; ?>)</option>
                    <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended (<?php echo $statSuspended; ?>)</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected (<?php echo $statRejected; ?>)</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply Filter</button>
                <?php if ($search !== '' || $status !== ''): ?>
                    <a href="<?php echo url('/admin/sellers.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Sellers Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Merchant Directory (<?php echo number_format($totalSellers); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($sellers)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-shop fs-1 d-block mb-2"></i>
                <p class="mb-0">No sellers found matching the criteria.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Store & Applicant</th>
                            <th>Contact</th>
                            <th>Products</th>
                            <th>Gross Sales</th>
                            <th>Commission Override</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sellers as $s): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-circle bg-light text-ss-accent fw-bold d-flex align-items-center justify-content-center rounded-3 border" style="width: 44px; height: 44px; overflow: hidden;">
                                            <?php if (!empty($s['store_logo'])): ?>
                                                <img src="<?php echo asset($s['store_logo']); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                <i class="bi bi-shop fs-5"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="<?php echo url('/admin/seller-details.php?id=' . $s['user_id']); ?>" class="fw-bold text-dark d-block">
                                                <?php echo e($s['store_name']); ?>
                                                <?php if (!empty($s['verified'])): ?>
                                                    <i class="bi bi-patch-check-fill text-primary" title="Verified Merchant"></i>
                                                <?php endif; ?>
                                            </a>
                                            <span class="small text-ss-muted"><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?> &bull; <?php echo e($s['business_name']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><i class="bi bi-envelope text-ss-muted me-1"></i> <?php echo e($s['email']); ?></div>
                                    <?php if (!empty($s['phone'])): ?>
                                        <div class="small text-ss-muted"><i class="bi bi-telephone text-ss-muted me-1"></i> <?php echo e($s['phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo (int)$s['total_products']; ?> products</span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?php echo format_price($s['gross_sales']); ?></strong>
                                    <div class="small text-ss-muted"><?php echo (int)$s['total_orders']; ?> orders</div>
                                </td>
                                <td>
                                    <?php if ($s['commission_rate'] !== null): ?>
                                        <span class="badge bg-info text-dark"><?php echo number_format((float)$s['commission_rate'], 1); ?>% Custom</span>
                                    <?php else: ?>
                                        <span class="small text-ss-muted">Category Default</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($s['status']) {
                                        'approved' => 'success',
                                        'pending' => 'warning',
                                        'suspended' => 'danger',
                                        'rejected' => 'default',
                                        default => 'default'
                                    }; ?>">
                                        <?php echo ucfirst($s['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <a href="<?php echo url('/admin/seller-details.php?id=' . $s['user_id']); ?>" class="btn btn-ss-outline btn-sm" title="View Profile & Manage">
                                            <i class="bi bi-eye"></i> Details
                                        </a>

                                        <?php if ($s['status'] === 'pending'): ?>
                                            <form method="POST" action="<?php echo url('/admin/sellers.php'); ?>" class="d-inline" onsubmit="return confirm('Approve this seller application?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="seller_id" value="<?php echo (int)$s['user_id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" title="Approve Seller">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $s['user_id']; ?>" title="Reject Application">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php elseif ($s['status'] === 'approved'): ?>
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#suspendModal<?php echo $s['user_id']; ?>" title="Suspend Seller">
                                                <i class="bi bi-slash-circle"></i>
                                            </button>
                                        <?php elseif ($s['status'] === 'suspended' || $s['status'] === 'rejected'): ?>
                                            <form method="POST" action="<?php echo url('/admin/sellers.php'); ?>" class="d-inline" onsubmit="return confirm('Reinstate and approve this seller?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="seller_id" value="<?php echo (int)$s['user_id']; ?>">
                                                <button type="submit" class="btn btn-outline-success btn-sm" title="Reinstate / Approve">
                                                    <i class="bi bi-arrow-repeat"></i> Reinstate
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Reject Modal -->
                                    <div class="modal fade text-start" id="rejectModal<?php echo $s['user_id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/sellers.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="seller_id" value="<?php echo (int)$s['user_id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reject Seller: <?php echo e($s['store_name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label">Rejection Reason</label>
                                                            <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="Specify why this seller application is being rejected..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Suspend Modal -->
                                    <div class="modal fade text-start" id="suspendModal<?php echo $s['user_id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/sellers.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="suspend">
                                                    <input type="hidden" name="seller_id" value="<?php echo (int)$s['user_id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Suspend Seller: <?php echo e($s['store_name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Suspending this store will prevent the seller from receiving new orders and hides active listings.</p>
                                                        <div class="mb-3">
                                                            <label class="form-label">Suspension Reason</label>
                                                            <textarea name="suspension_reason" class="form-control" rows="3" required placeholder="Explain violation or justification for suspension..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger">Suspend Store</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 pb-3">
                <?php echo render_admin_pagination($pagination, url('/admin/sellers.php'), ['search' => $search, 'status' => $status]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
