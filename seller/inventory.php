<?php
/**
 * ShopSphere - Seller Inventory Management
 */
$page_title = 'Inventory & Stock Levels';
$current_page = 'inventory';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/InventoryService.php';

$inventoryService = new InventoryService();
$errors = [];

// Handle manual stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
    check_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $new_quantity = (int)($_POST['new_quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Manual stock adjustment');

    // Verify product ownership
    $stmt = $db->prepare("SELECT id FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$product_id, $seller_id]);
    if ($stmt->fetch()) {
        try {
            $inventoryService->adjustStock($product_id, null, $new_quantity, $reason, $seller_id);
            flash('success', 'Inventory quantity updated successfully.');
            redirect(url('/seller/inventory.php'));
        } catch (Exception $e) {
            $errors[] = 'Failed to adjust inventory: ' . $e->getMessage();
        }
    } else {
        $errors[] = 'Unauthorized product stock modification.';
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$inventory_data = $inventoryService->getSellerInventory($seller_id, $page, $per_page);
$items = $inventory_data['products'];
$total = $inventory_data['total'];
$total_pages = ceil($total / $per_page);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Inventory Management</h4>
        <p class="text-ss-muted small mb-0">Track on-hand stock and perform audited warehouse quantity adjustments</p>
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

<div class="ss-card">
    <div class="ss-card-body p-0">
        <?php if (empty($items)): ?>
            <div class="py-5 text-center text-ss-muted">
                <i class="bi bi-stack fs-1 d-block mb-2 text-muted"></i>
                <h5>No inventory items found</h5>
                <p class="small">Add products to your catalog to begin tracking stock.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Current Stock</th>
                            <th>Threshold</th>
                            <th>Stock Status</th>
                            <th class="text-end">Quick Adjust</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($it['name']); ?></strong>
                                    <div class="small text-ss-muted"><?php echo e($it['category_name'] ?? 'General'); ?></div>
                                </td>
                                <td><code><?php echo e($it['sku'] ?? 'N/A'); ?></code></td>
                                <td><span class="fs-6 fw-bold"><?php echo $it['stock_quantity']; ?></span></td>
                                <td><?php echo $it['low_stock_threshold']; ?></td>
                                <td>
                                    <?php if ($it['stock_quantity'] === 0): ?>
                                        <span class="ss-badge ss-badge-danger">Out of Stock</span>
                                    <?php elseif ($it['stock_quantity'] <= $it['low_stock_threshold']): ?>
                                        <span class="ss-badge ss-badge-warning">Low Stock Warning</span>
                                    <?php else: ?>
                                        <span class="ss-badge ss-badge-success">Healthy</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#adjustModal_<?php echo $it['id']; ?>">
                                        <i class="bi bi-pencil-square"></i> Adjust
                                    </button>

                                    <!-- Adjust Stock Modal -->
                                    <div class="modal fade" id="adjustModal_<?php echo $it['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog text-start">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Adjust Stock: <?php echo e(truncate($it['name'], 30)); ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="<?php echo url('/seller/inventory.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="adjust_stock">
                                                    <input type="hidden" name="product_id" value="<?php echo $it['id']; ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold">New Total Quantity On-Hand:</label>
                                                            <input type="number" name="new_quantity" class="ss-form-control" required min="0" value="<?php echo $it['stock_quantity']; ?>">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold">Reason for Adjustment:</label>
                                                            <select name="reason" class="ss-form-control" required>
                                                                <option value="New stock arrival / replenishment">New stock arrival / replenishment</option>
                                                                <option value="Physical count audit correction">Physical count audit correction</option>
                                                                <option value="Damaged / expired goods removal">Damaged / expired goods removal</option>
                                                                <option value="Customer return restocked">Customer return restocked</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-ss-primary btn-sm">Save Adjustment</button>
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
        <?php endif; ?>
    </div>
</div>

<?php if ($total_pages > 1): ?>
    <nav class="ss-pagination mt-4">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <a href="<?php echo url('/seller/inventory.php?page=' . $p); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                <?php echo $p; ?>
            </a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
