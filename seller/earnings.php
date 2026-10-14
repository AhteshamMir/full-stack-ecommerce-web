<?php
/**
 * ShopSphere - Seller Earnings & Financial Audit
 */
$page_title = 'Earnings & Commission Breakdown';
$current_page = 'earnings';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/CommissionService.php';

$commissionService = new CommissionService();

$earnings = $commissionService->getSellerEarnings($seller_id);
$balance = $commissionService->getSellerBalance($seller_id);

// Fetch recent settled items
$stmt = $db->prepare("SELECT oi.*, o.order_number, o.created_at as order_date 
                      FROM order_items oi 
                      JOIN orders o ON oi.order_id = o.id 
                      WHERE oi.seller_id = ? 
                      ORDER BY oi.id DESC LIMIT 20");
$stmt->execute([$seller_id]);
$settled_items = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Merchant Earnings & Ledger</h4>
        <p class="text-ss-muted small mb-0">Transparent breakdown of gross volume, platform fees, and net payouts</p>
    </div>
    <a href="<?php echo url('/seller/payouts.php'); ?>" class="btn btn-ss-primary btn-sm">
        <i class="bi bi-wallet2"></i> Request Withdrawal
    </a>
</div>

<!-- Financial Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-cash"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo format_price($earnings['gross_sales'] ?? 0); ?></h3>
                <p>Gross Merchandise Volume</p>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-pie-chart"></i></div>
            <div class="ss-stat-info">
                <h3 class="text-danger"><?php echo format_price($earnings['commission_paid'] ?? 0); ?></h3>
                <p>Platform Commission Fee</p>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-wallet-fill"></i></div>
            <div class="ss-stat-info">
                <h3 class="text-ss-success"><?php echo format_price($earnings['net_earnings'] ?? 0); ?></h3>
                <p>Lifetime Net Earnings</p>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-credit-card"></i></div>
            <div class="ss-stat-info">
                <h3 class="text-ss-accent"><?php echo format_price($balance['available_balance'] ?? 0); ?></h3>
                <p>Available Balance</p>
            </div>
        </div>
    </div>
</div>

<!-- Ledger of Sold Items -->
<div class="ss-card">
    <div class="ss-card-header">
        <h5>Itemized Sales Ledger</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($settled_items)): ?>
            <div class="py-5 text-center text-ss-muted">
                <i class="bi bi-receipt fs-1 d-block mb-2 text-muted"></i>
                <h5>No sales transactions recorded yet</h5>
                <p class="small">Transactions will appear as customers purchase your items.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Item Name</th>
                            <th>Qty</th>
                            <th>Gross Total</th>
                            <th>Commission Rate</th>
                            <th>Commission Deducted</th>
                            <th>Net Credit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settled_items as $si): ?>
                            <tr>
                                <td><strong><?php echo e($si['order_number']); ?></strong></td>
                                <td><?php echo format_date($si['order_date'], 'M d, Y'); ?></td>
                                <td><?php echo e(truncate($si['product_name'], 30)); ?></td>
                                <td><?php echo $si['quantity']; ?></td>
                                <td><?php echo format_price($si['total_price']); ?></td>
                                <td><?php echo (float)$si['commission_rate']; ?>%</td>
                                <td class="text-danger">-<?php echo format_price($si['commission_amount']); ?></td>
                                <td><strong class="text-ss-success"><?php echo format_price($si['seller_earning']); ?></strong></td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo $si['status'] === 'delivered' ? 'success' : 'default'; ?>">
                                        <?php echo ucfirst($si['status']); ?>
                                    </span>
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
