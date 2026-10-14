<?php
/**
 * ShopSphere - Seller Payout Disbursements
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

// Handle Actions (Approve, Complete, Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $payoutId = (int)($_POST['payout_id'] ?? 0);

    if ($payoutId <= 0) {
        flash('error', 'Invalid payout request identifier.');
        redirect(url('/admin/payouts.php'));
    }

    $stmt = $db->prepare("SELECT p.*, sp.store_name FROM payouts p JOIN seller_profiles sp ON p.seller_id = sp.user_id WHERE p.id = :id");
    $stmt->execute(['id' => $payoutId]);
    $payout = $stmt->fetch();

    if (!$payout) {
        flash('error', 'Payout record not found.');
        redirect(url('/admin/payouts.php'));
    }

    $adminId = get_current_user_id();

    if ($action === 'approve') {
        $stmt = $db->prepare("
            UPDATE payouts 
            SET status = 'approved', approved_at = NOW(), processed_by = :admin, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute(['admin' => $adminId, 'id' => $payoutId]);

        // Notify seller
        $notif = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (:user_id, 'payout', 'Payout Approved', :msg, '/seller/payouts.php', NOW())
        ");
        $notif->execute([
            'user_id' => $payout['seller_id'],
            'msg'     => "Your payout request #" . $payoutId . " for " . format_price($payout['amount']) . " was approved and queued for bank release."
        ]);

        log_audit($db, 'approve_payout', 'payouts', $payoutId, ['status' => $payout['status']], ['status' => 'approved']);
        flash('success', "Payout #{$payoutId} approved for disbursement.");
        redirect(url('/admin/payouts.php'));

    } elseif ($action === 'complete') {
        $reference = trim($_POST['reference'] ?? 'MANUAL-DISBURSEMENT');

        $stmt = $db->prepare("
            UPDATE payouts 
            SET status = 'completed', reference = :ref, completed_at = NOW(), processed_by = :admin, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute(['ref' => $reference, 'admin' => $adminId, 'id' => $payoutId]);

        $notif = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (:user_id, 'payout', 'Payout Disbursed Successfully', :msg, '/seller/payouts.php', NOW())
        ");
        $notif->execute([
            'user_id' => $payout['seller_id'],
            'msg'     => "Your payout of " . format_price($payout['amount']) . " has been sent! Reference ID: {$reference}"
        ]);

        log_audit($db, 'complete_payout', 'payouts', $payoutId, ['status' => $payout['status']], ['status' => 'completed', 'reference' => $reference]);
        flash('success', "Payout #{$payoutId} marked as completed with reference {$reference}.");
        redirect(url('/admin/payouts.php'));

    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejected_reason'] ?? 'Banking or compliance discrepancy.');

        $stmt = $db->prepare("
            UPDATE payouts 
            SET status = 'rejected', rejected_reason = :reason, processed_by = :admin, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute(['reason' => $reason, 'admin' => $adminId, 'id' => $payoutId]);

        $notif = $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, created_at)
            VALUES (:user_id, 'payout', 'Payout Request Declined', :msg, '/seller/payouts.php', NOW())
        ");
        $notif->execute([
            'user_id' => $payout['seller_id'],
            'msg'     => "Your payout request #{$payoutId} for " . format_price($payout['amount']) . " was rejected. Reason: {$reason}"
        ]);

        log_audit($db, 'reject_payout', 'payouts', $payoutId, ['status' => $payout['status']], ['status' => 'rejected', 'reason' => $reason]);
        flash('success', "Payout #{$payoutId} rejected.");
        redirect(url('/admin/payouts.php'));
    }
}

// Filters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["1=1"];
$params = [];

if ($status !== '' && in_array($status, ['pending', 'approved', 'processing', 'completed', 'rejected'], true)) {
    $where[] = "p.status = :status";
    $params['status'] = $status;
}

if ($search !== '') {
    $where[] = "(sp.store_name LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR p.reference LIKE :search)";
    $params['search'] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// Counts
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM payouts p 
    JOIN seller_profiles sp ON p.seller_id = sp.user_id 
    JOIN users u ON p.seller_id = u.id 
    WHERE {$whereClause}
");
$countStmt->execute($params);
$totalPayouts = (int)$countStmt->fetchColumn();

$pagination = paginate($totalPayouts, $perPage, $page);

// Fetch Payouts
$query = "
    SELECT p.*, sp.store_name, sp.store_slug, u.first_name, u.last_name, u.email, u.phone,
        admin_u.first_name AS processor_fname, admin_u.last_name AS processor_lname
    FROM payouts p
    JOIN seller_profiles sp ON p.seller_id = sp.user_id
    JOIN users u ON p.seller_id = u.id
    LEFT JOIN users admin_u ON p.processed_by = admin_u.id
    WHERE {$whereClause}
    ORDER BY CASE WHEN p.status = 'pending' THEN 0 WHEN p.status = 'approved' THEN 1 ELSE 2 END, p.requested_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$payouts = $stmt->fetchAll();

// Overview Metrics
$statPendingAmount = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE status = 'pending'")->fetchColumn();
$statCompletedAmount = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payouts WHERE status = 'completed'")->fetchColumn();
$statPendingCount = (int)$db->query("SELECT COUNT(*) FROM payouts WHERE status = 'pending'")->fetchColumn();
$statApprovedCount = (int)$db->query("SELECT COUNT(*) FROM payouts WHERE status = 'approved'")->fetchColumn();

$page_title = 'Seller Payout Disbursements';
$current_page = 'payouts';
require_once __DIR__ . '/header.php';
?>

<!-- Metrics Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-clock-history"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($statPendingAmount); ?></h3>
                <p>Pending Disbursements</p>
                <div class="small text-warning fw-semibold"><?php echo $statPendingCount; ?> requests waiting</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-check-all"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($statCompletedAmount); ?></h3>
                <p>Total Paid Out to Date</p>
                <div class="trend up"><i class="bi bi-check-circle"></i> Settled successfully</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-gear-wide-connected"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo $statApprovedCount; ?></h3>
                <p>Approved in Transit</p>
                <div class="small text-ss-muted">Awaiting bank completion</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-wallet2"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($totalPayouts); ?></h3>
                <p>Total Withdrawal Logs</p>
                <div class="small text-ss-muted">Platform lifetime</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/payouts.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search store name, seller name, or transfer reference..." value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select">
                    <option value="">All Payout Statuses</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Approval (<?php echo $statPendingCount; ?>)</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved (Ready for Transfer)</option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply Filter</button>
                <?php if ($search !== '' || $status !== ''): ?>
                    <a href="<?php echo url('/admin/payouts.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Payouts Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Payout Withdrawal Requests (<?php echo number_format($totalPayouts); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($payouts)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-cash-coin fs-1 d-block mb-2"></i>
                No payout requests found matching the current filters.
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>ID & Date</th>
                            <th>Merchant Store</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payouts as $p): ?>
                            <tr>
                                <td>
                                    <strong>#<?php echo $p['id']; ?></strong>
                                    <div class="small text-ss-muted"><?php echo format_date($p['requested_at'], 'M d, Y h:i A'); ?></div>
                                </td>
                                <td>
                                    <a href="<?php echo url('/admin/seller-details.php?id=' . $p['seller_id']); ?>" class="fw-bold text-dark d-block">
                                        <?php echo e($p['store_name']); ?>
                                    </a>
                                    <span class="small text-ss-muted"><?php echo e($p['first_name'] . ' ' . $p['last_name']); ?> &bull; <?php echo e($p['email']); ?></span>
                                </td>
                                <td>
                                    <strong class="text-dark fs-6"><?php echo format_price($p['amount']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border text-uppercase">
                                        <?php echo str_replace('_', ' ', $p['method']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($p['reference'])): ?>
                                        <code><?php echo e($p['reference']); ?></code>
                                    <?php else: ?>
                                        <span class="small text-ss-muted">—</span>
                                    <?php endif; ?>
                                    <?php if (!empty($p['rejected_reason'])): ?>
                                        <div class="small text-danger" title="<?php echo e($p['rejected_reason']); ?>">
                                            <i class="bi bi-info-circle"></i> <?php echo truncate($p['rejected_reason'], 30); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($p['status']) {
                                        'completed' => 'success',
                                        'approved', 'processing' => 'info',
                                        'pending' => 'warning',
                                        'rejected' => 'danger',
                                        default => 'default'
                                    }; ?>">
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <?php if ($p['status'] === 'pending'): ?>
                                            <!-- Approve Form -->
                                            <form method="POST" action="<?php echo url('/admin/payouts.php'); ?>" onsubmit="return confirm('Approve payout #<?php echo $p['id']; ?> for <?php echo format_price($p['amount']); ?>?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="payout_id" value="<?php echo (int)$p['id']; ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm" title="Approve Request">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                            </form>

                                            <!-- Reject Modal Trigger -->
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $p['id']; ?>" title="Reject">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php elseif ($p['status'] === 'approved'): ?>
                                            <!-- Complete Modal Trigger -->
                                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#completeModal<?php echo $p['id']; ?>" title="Mark Completed / Enter Bank Ref">
                                                <i class="bi bi-send-check"></i> Complete
                                            </button>

                                            <!-- Reject Modal Trigger -->
                                            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $p['id']; ?>" title="Reject">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="small text-ss-muted"><i class="bi bi-check2-circle"></i> Logged</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Complete Modal -->
                                    <div class="modal fade text-start" id="completeModal<?php echo $p['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/payouts.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="complete">
                                                    <input type="hidden" name="payout_id" value="<?php echo (int)$p['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Record Disbursement: #<?php echo $p['id']; ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="mb-3">
                                                            Completing payout of <strong><?php echo format_price($p['amount']); ?></strong> to <strong><?php echo e($p['store_name']); ?></strong> via <strong><?php echo strtoupper($p['method']); ?></strong>.
                                                        </p>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Bank / Gateway Reference Number <span class="text-danger">*</span></label>
                                                            <input type="text" name="reference" class="form-control" placeholder="e.g. TRX-982347102 or Bank STANBIC-87291" required>
                                                            <div class="form-text small">Transaction receipt ID from your banking app or gateway.</div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-success">Mark as Completed</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Reject Modal -->
                                    <div class="modal fade text-start" id="rejectModal<?php echo $p['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/payouts.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="payout_id" value="<?php echo (int)$p['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Decline Payout #<?php echo $p['id']; ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Reason for Rejection <span class="text-danger">*</span></label>
                                                            <textarea name="rejected_reason" class="form-control" rows="3" required placeholder="Explain why the payout request was declined (e.g. invalid account details, hold on seller account)..."></textarea>
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

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 pb-3">
                <?php echo render_admin_pagination($pagination, url('/admin/payouts.php'), ['search' => $search, 'status' => $status]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
