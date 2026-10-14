<?php
/**
 * ShopSphere - Seller Payout Withdrawals
 */
$page_title = 'Payout Settlements & Withdrawals';
$current_page = 'payouts';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/PayoutService.php';

$payoutService = new PayoutService();
$errors = [];

$available_balance = $payoutService->getSellerAvailableBalance($seller_id);
$pending_balance = $payoutService->getSellerPendingBalance($seller_id);

// Handle payout withdrawal submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_payout') {
    check_csrf();
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['method'] ?? '');
    $reference = trim($_POST['reference'] ?? '');

    if ($amount <= 0) {
        $errors[] = 'Please specify a valid withdrawal amount.';
    } elseif ($amount < 500) {
        $errors[] = 'Minimum withdrawal threshold is Rs. 500.00.';
    } elseif ($amount > $available_balance) {
        $errors[] = 'Requested amount exceeds your cleared available balance (' . format_price($available_balance) . ').';
    } elseif (empty($method)) {
        $errors[] = 'Please select a disbursement channel.';
    } elseif (empty($reference)) {
        $errors[] = 'Please enter your bank account or wallet details.';
    }

    if (empty($errors)) {
        try {
            $payoutService->requestPayout($seller_id, $amount, $method . ' - ' . $reference);
            flash('success', 'Withdrawal request of ' . format_price($amount) . ' submitted for processing.');
            redirect(url('/seller/payouts.php'));
        } catch (Exception $e) {
            $errors[] = 'Payout request failed: ' . $e->getMessage();
        }
    }
}

$payout_history = $payoutService->getSellerPayouts($seller_id);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Merchant Payouts</h4>
        <p class="text-ss-muted small mb-0">Disbursement of cleared revenue directly to your corporate or local account</p>
    </div>
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

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="ss-card h-100 p-4 border bg-white">
            <h6 class="text-ss-muted mb-2 text-uppercase fw-bold" style="letter-spacing: 0.5px;">Cleared Available Balance</h6>
            <div class="display-6 fw-bold text-ss-success mb-2"><?php echo format_price($available_balance); ?></div>
            <p class="small text-ss-muted mb-0">Funds ready for instant withdrawal from delivered order packages.</p>
        </div>
    </div>
    <div class="col-md-6">
        <div class="ss-card h-100 p-4 border bg-white">
            <h6 class="text-ss-muted mb-2 text-uppercase fw-bold" style="letter-spacing: 0.5px;">Pending In-Transit Balance</h6>
            <div class="display-6 fw-bold text-ss-primary mb-2"><?php echo format_price($pending_balance); ?></div>
            <p class="small text-ss-muted mb-0">Earnings from orders that are currently in shipping or processing transit.</p>
        </div>
    </div>
</div>

<!-- Request Payout Card -->
<div class="ss-card mb-4">
    <div class="ss-card-header">
        <h5>Submit Withdrawal Request</h5>
    </div>
    <div class="ss-card-body p-4">
        <?php if ($available_balance < 500): ?>
            <div class="text-ss-muted">
                <i class="bi bi-info-circle me-1"></i> You need at least Rs. 500.00 in available cleared balance to request a payout.
            </div>
        <?php else: ?>
            <form action="<?php echo url('/seller/payouts.php'); ?>" method="POST" class="row g-3">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="request_payout">

                <div class="col-md-4">
                    <label class="form-label small fw-bold">Withdrawal Amount (PKR):</label>
                    <input type="number" step="0.01" name="amount" class="ss-form-control" required min="500" max="<?php echo $available_balance; ?>" value="<?php echo $available_balance; ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-bold">Settlement Channel:</label>
                    <select name="method" class="ss-form-control" required>
                        <option value="Bank Transfer (IBAN)">Bank Wire / Direct Transfer (IBAN)</option>
                        <option value="JazzCash Mobile Account">JazzCash Merchant/Mobile Account</option>
                        <option value="EasyPaisa Mobile Account">EasyPaisa Account</option>
                        <option value="Raast Instant Settlement">Raast ID Instant Transfer</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label small fw-bold">Account Title & Number / IBAN:</label>
                    <input type="text" name="reference" class="ss-form-control" required placeholder="e.g. PK36SCBL000000... / 03001234567">
                </div>

                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-ss-primary btn-sm px-4">
                        <i class="bi bi-check2-circle"></i> Confirm Withdrawal
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Payout History Table -->
<div class="ss-card">
    <div class="ss-card-header">
        <h5>Disbursement History</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($payout_history)): ?>
            <div class="py-5 text-center text-ss-muted">
                <i class="bi bi-wallet fs-1 d-block mb-2 text-muted"></i>
                <h6>No payout requests found</h6>
                <p class="small">Your submitted withdrawal records will appear here.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead>
                        <tr>
                            <th>Ref #</th>
                            <th>Request Date</th>
                            <th>Amount</th>
                            <th>Disbursement Target</th>
                            <th>Status</th>
                            <th>Processed Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payout_history as $p): ?>
                            <tr>
                                <td><strong>#PO-<?php echo $p['id']; ?></strong></td>
                                <td><?php echo format_date($p['requested_at'] ?? $p['created_at'], 'M d, Y'); ?></td>
                                <td><strong><?php echo format_price($p['amount']); ?></strong></td>
                                <td><?php echo e($p['method']); ?></td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php 
                                        echo match($p['status']) {
                                            'completed' => 'success',
                                            'approved' => 'info',
                                            'processing' => 'warning',
                                            'rejected' => 'danger',
                                            default => 'default'
                                        };
                                    ?>">
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo !empty($p['completed_at']) ? format_date($p['completed_at'], 'M d, Y') : 'Pending Review'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
