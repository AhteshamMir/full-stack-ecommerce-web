<?php
/**
 * ShopSphere - Seller Order Details & Fulfillment
 */
$page_title = 'Fulfill Order Package';
$current_page = 'orders';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/OrderService.php';

$orderService = new OrderService();
$seller_order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$order = $orderService->getSellerOrderDetails($seller_order_id, $seller_id);
if (!$order) {
    flash('error', 'Order not found or access denied.');
    redirect(url('/seller/orders.php'));
}

// Handle Status Transitions
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    check_csrf();
    $new_status = $_POST['new_status'] ?? '';
    $tracking_number = trim($_POST['tracking_number'] ?? '');
    $shipping_carrier = trim($_POST['shipping_carrier'] ?? '');

    try {
        if ($orderService->updateSellerOrderStatus($seller_order_id, $seller_id, $new_status, $tracking_number, $shipping_carrier)) {
            flash('success', 'Package status updated to ' . ucfirst($new_status));
            redirect(url('/seller/order-details.php?id=' . $seller_order_id));
        } else {
            $errors[] = 'Invalid status progression for this order.';
        }
    } catch (Exception $e) {
        $errors[] = 'Error updating status: ' . $e->getMessage();
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Package #<?php echo e($order['order_number']); ?>-S<?php echo $order['id']; ?></h4>
        <p class="text-ss-muted small mb-0">Master Order Reference: <strong><?php echo e($order['order_number']); ?></strong> | Placed on <?php echo format_date($order['created_at'], 'M d, Y H:i'); ?></p>
    </div>
    <a href="<?php echo url('/seller/orders.php'); ?>" class="btn btn-ss-outline btn-sm">
        <i class="bi bi-arrow-left"></i> Back to Orders
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

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Order Items in this seller package -->
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5>Your Package Items</h5>
            </div>
            <div class="ss-card-body p-0">
                <div class="ss-table-responsive">
                    <table class="ss-table mb-0">
                        <thead>
                            <tr>
                                <th>Product Details</th>
                                <th>Unit Price</th>
                                <th>Quantity</th>
                                <th>Commission (<?php echo (float)($order['items'][0]['commission_rate'] ?? 10); ?>%)</th>
                                <th>Your Payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order['items'] as $it): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e($it['product_name']); ?></strong>
                                        <?php if (!empty($it['variant_info'])): ?>
                                            <div class="small text-ss-muted">Variant: <?php echo e($it['variant_info']); ?></div>
                                        <?php endif; ?>
                                        <div class="small text-ss-muted">SKU: <?php echo e($it['sku'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td><?php echo format_price($it['unit_price']); ?></td>
                                    <td><strong><?php echo $it['quantity']; ?></strong></td>
                                    <td class="text-danger">-<?php echo format_price($it['commission_amount']); ?></td>
                                    <td><strong class="text-ss-success"><?php echo format_price($it['seller_earning']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Package Settlement Summary -->
        <div class="ss-card">
            <div class="ss-card-header">
                <h5>Package Earnings Breakdown</h5>
            </div>
            <div class="ss-card-body">
                <div class="row g-2">
                    <div class="col-6 text-ss-muted">Package Subtotal:</div>
                    <div class="col-6 text-end fw-bold"><?php echo format_price($order['subtotal']); ?></div>

                    <div class="col-6 text-ss-muted">Platform Commission Deduction:</div>
                    <div class="col-6 text-end text-danger fw-bold">-<?php echo format_price($order['commission_amount']); ?></div>

                    <div class="col-6 border-top pt-2 text-ss-primary h6 mb-0">Net Merchant Balance Credit:</div>
                    <div class="col-6 border-top pt-2 text-end text-ss-success h6 mb-0 fw-bold"><?php echo format_price($order['seller_earning']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fulfillment Action Panel -->
    <div class="col-lg-4">
        <!-- Status & Dispatch -->
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5>Fulfillment Controls</h5>
            </div>
            <div class="ss-card-body p-3">
                <div class="mb-3">
                    <label class="small text-ss-muted d-block">Current Package State:</label>
                    <span class="ss-badge ss-badge-<?php 
                        echo match($order['status']) {
                            'delivered' => 'success',
                            'shipped' => 'info',
                            'processing' => 'warning',
                            'cancelled' => 'danger',
                            default => 'default'
                        };
                    ?> fs-6">
                        <?php echo ucfirst($order['status']); ?>
                    </span>
                </div>

                <?php if (!empty($order['tracking_number'])): ?>
                    <div class="mb-3 p-2 bg-light rounded border">
                        <small class="text-ss-muted d-block">Carrier:</small>
                        <strong><?php echo e($order['shipping_carrier'] ?? 'Partner Courier'); ?></strong>
                        <small class="text-ss-muted d-block mt-1">Consignment Tracking #:</small>
                        <code class="fs-6"><?php echo e($order['tracking_number']); ?></code>
                    </div>
                <?php endif; ?>

                <?php if ($order['status'] !== 'delivered' && $order['status'] !== 'cancelled'): ?>
                    <form method="POST" action="<?php echo url('/seller/order-details.php?id=' . $order['id']); ?>" class="border-top pt-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update_status">

                        <div class="mb-3">
                            <label for="new_status" class="small fw-bold">Update Order Status:</label>
                            <select name="new_status" id="new_status" class="ss-form-control form-control-sm" required>
                                <?php if ($order['status'] === 'pending'): ?>
                                    <option value="processing">Mark as Processing (Accepted)</option>
                                    <option value="cancelled">Cancel Package</option>
                                <?php elseif ($order['status'] === 'processing'): ?>
                                    <option value="shipped">Mark as Shipped (Dispatched)</option>
                                    <option value="cancelled">Cancel Package</option>
                                <?php elseif ($order['status'] === 'shipped'): ?>
                                    <option value="delivered">Confirm Delivery Handover</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="shippingFields" style="<?php echo $order['status'] === 'processing' ? '' : 'display:none;'; ?>">
                            <label for="shipping_carrier" class="small fw-bold">Courier Partner:</label>
                            <input type="text" name="shipping_carrier" id="shipping_carrier" class="ss-form-control form-control-sm mb-2" placeholder="e.g. TCS, Leopard, Call Courier" value="<?php echo e($order['shipping_carrier'] ?? ''); ?>">

                            <label for="tracking_number" class="small fw-bold">Tracking / Airway Bill #:</label>
                            <input type="text" name="tracking_number" id="tracking_number" class="ss-form-control form-control-sm" placeholder="Enter tracking code" value="<?php echo e($order['tracking_number'] ?? ''); ?>">
                        </div>

                        <button type="submit" class="btn btn-ss-primary btn-sm w-100">
                            Save Status Change
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Destination Address -->
        <div class="ss-card">
            <div class="ss-card-header">
                <h5>Shipping Destination</h5>
            </div>
            <div class="ss-card-body">
                <?php 
                $shipAddr = json_decode($order['shipping_address'] ?? '{}', true);
                ?>
                <p class="mb-1 fw-bold"><?php echo e($shipAddr['full_name'] ?? 'Recipient'); ?></p>
                <p class="mb-1 text-ss-muted small"><i class="bi bi-telephone"></i> <?php echo e($shipAddr['phone'] ?? 'N/A'); ?></p>
                <p class="mb-0 text-ss-muted small">
                    <i class="bi bi-geo-alt"></i> 
                    <?php echo e($shipAddr['address_line1'] ?? ''); ?><br>
                    <?php echo e($shipAddr['city'] ?? ''); ?>, <?php echo e($shipAddr['state'] ?? ''); ?> <?php echo e($shipAddr['postal_code'] ?? ''); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('new_status')?.addEventListener('change', function() {
    const fields = document.getElementById('shippingFields');
    if (fields) {
        fields.style.display = (this.value === 'shipped') ? 'block' : 'none';
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
