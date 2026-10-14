<?php
/**
 * ShopSphere - Stock Oversight & Inventory Management
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

// Handle Manual Stock Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $productId = (int)($_POST['product_id'] ?? 0);

    if ($productId <= 0) {
        flash('error', 'Invalid product identifier.');
        redirect(url('/admin/inventory.php'));
    }

    $stmt = $db->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute(['id' => $productId]);
    $prod = $stmt->fetch();

    if (!$prod) {
        flash('error', 'Product not found.');
        redirect(url('/admin/inventory.php'));
    }

    if ($action === 'adjust_stock') {
        $change = (int)($_POST['quantity_change'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Manual admin stock adjustment');

        if ($change === 0) {
            flash('error', 'Quantity adjustment must not be zero.');
            redirect(url('/admin/inventory.php'));
        }

        $newStock = max(0, (int)$prod['stock_quantity'] + $change);

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE products SET stock_quantity = :s, updated_at = NOW() WHERE id = :id")
               ->execute(['s' => $newStock, 'id' => $productId]);

            $stmtMov = $db->prepare("
                INSERT INTO inventory_movements (product_id, quantity_change, type, reference_type, reference_id, reason, created_by, created_at)
                VALUES (:pid, :qty, 'adjustment', 'manual_adjustment', :admin_id, :reason, :admin_id, NOW())
            ");
            $stmtMov->execute([
                'pid'      => $productId,
                'qty'      => $change,
                'admin_id' => get_current_user_id(),
                'reason'   => $reason
            ]);

            log_audit($db, 'adjust_inventory', 'products', $productId, ['stock' => $prod['stock_quantity']], ['stock' => $newStock, 'change' => $change]);

            $db->commit();
            flash('success', "Stock updated for \"{$prod['name']}\". New quantity: {$newStock}.");
        } catch (Throwable $e) {
            $db->rollBack();
            flash('error', 'Failed to adjust stock: ' . $e->getMessage());
        }

        redirect(url('/admin/inventory.php'));
    }
}

// Filters
$stockFilter = trim($_GET['stock_status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["p.status = 'approved'"];
$params = [];

if ($stockFilter === 'out_of_stock') {
    $where[] = "p.stock_quantity = 0";
} elseif ($stockFilter === 'low_stock') {
    $where[] = "p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold";
} elseif ($stockFilter === 'in_stock') {
    $where[] = "p.stock_quantity > p.low_stock_threshold";
}

if ($search !== '') {
    $where[] = "(p.name LIKE :search OR p.sku LIKE :search OR sp.store_name LIKE :search)";
    $params['search'] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// Total Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM products p JOIN seller_profiles sp ON p.seller_id = sp.user_id WHERE {$whereClause}");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();

$pagination = paginate($totalItems, $perPage, $page);

// Fetch Products
$query = "
    SELECT p.*, sp.store_name, c.name AS category_name
    FROM products p
    JOIN seller_profiles sp ON p.seller_id = sp.user_id
    JOIN categories c ON p.category_id = c.id
    WHERE {$whereClause}
    ORDER BY p.stock_quantity ASC, p.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$stockList = $stmt->fetchAll();

// Overview Counts
$statOutOfStock = (int)$db->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0 AND status = 'approved'")->fetchColumn();
$statLowStock = (int)$db->query("SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity <= low_stock_threshold AND status = 'approved'")->fetchColumn();
$statHealthy = (int)$db->query("SELECT COUNT(*) FROM products WHERE stock_quantity > low_stock_threshold AND status = 'approved'")->fetchColumn();

// Recent Inventory Movements
$recentMovements = $db->query("
    SELECT im.*, p.name AS product_name, u.first_name, u.last_name
    FROM inventory_movements im
    JOIN products p ON im.product_id = p.id
    LEFT JOIN users u ON im.created_by = u.id
    ORDER BY im.id DESC
    LIMIT 10
")->fetchAll();

$page_title = 'Inventory & Stock Oversight';
$current_page = 'inventory';
require_once __DIR__ . '/header.php';
?>

<!-- Metrics Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-4">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-exclamation-octagon"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statOutOfStock); ?></h3>
                <p>Out of Stock Products</p>
                <div class="small text-danger fw-semibold"><i class="bi bi-x-circle"></i> Requires inventory restock</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-battery-charging"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statLowStock); ?></h3>
                <p>Low Stock Warnings</p>
                <div class="small text-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i> Approaching threshold</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-boxes"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statHealthy); ?></h3>
                <p>Healthy In-Stock Items</p>
                <div class="trend up"><i class="bi bi-check-circle"></i> Well stocked</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/inventory.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search product name, SKU, or merchant..." value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-4">
                <select name="stock_status" class="form-select">
                    <option value="">All Stock Levels</option>
                    <option value="out_of_stock" <?php echo $stockFilter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock (0 units)</option>
                    <option value="low_stock" <?php echo $stockFilter === 'low_stock' ? 'selected' : ''; ?>>Low Stock (Threshold met)</option>
                    <option value="in_stock" <?php echo $stockFilter === 'in_stock' ? 'selected' : ''; ?>>Healthy Stock</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply Filter</button>
                <?php if ($search !== '' || $stockFilter !== ''): ?>
                    <a href="<?php echo url('/admin/inventory.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Products Stock Table -->
    <div class="col-lg-8">
        <div class="ss-card">
            <div class="ss-card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Catalog Inventory Levels (<?php echo number_format($totalItems); ?>)</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($stockList)): ?>
                    <div class="p-5 text-center text-ss-muted">
                        No products match the selected stock filters.
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Merchant</th>
                                    <th>Quantity</th>
                                    <th>Threshold</th>
                                    <th class="text-end">Adjust</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockList as $it): ?>
                                    <tr>
                                        <td>
                                            <strong class="d-block text-dark"><?php echo e($it['name']); ?></strong>
                                            <span class="small text-ss-muted">SKU: <code><?php echo e($it['sku'] ?? 'N/A'); ?></code> &bull; <?php echo e($it['category_name']); ?></span>
                                        </td>
                                        <td>
                                            <span class="small fw-semibold"><?php echo e($it['store_name']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($it['stock_quantity'] === 0): ?>
                                                <span class="badge bg-danger fs-6">0 (Out of stock)</span>
                                            <?php elseif ($it['stock_quantity'] <= $it['low_stock_threshold']): ?>
                                                <span class="badge bg-warning text-dark fs-6"><?php echo $it['stock_quantity']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success fs-6"><?php echo $it['stock_quantity']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-ss-muted small">&le; <?php echo $it['low_stock_threshold']; ?> units</span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-light border btn-sm" data-bs-toggle="modal" data-bs-target="#adjustModal<?php echo $it['id']; ?>" title="Adjust Stock">
                                                <i class="bi bi-plus-slash-minus"></i> Adjust
                                            </button>

                                            <!-- Adjust Modal -->
                                            <div class="modal fade text-start" id="adjustModal<?php echo $it['id']; ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST" action="<?php echo url('/admin/inventory.php'); ?>">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="adjust_stock">
                                                            <input type="hidden" name="product_id" value="<?php echo (int)$it['id']; ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Adjust Stock: <?php echo e($it['name']); ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label text-ss-muted small">Current Available Units</label>
                                                                    <div class="fs-4 fw-bold text-dark"><?php echo (int)$it['stock_quantity']; ?></div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Quantity Change (+ or -)</label>
                                                                    <input type="number" name="quantity_change" class="form-control" placeholder="e.g. +10 or -5" required>
                                                                    <div class="form-text small">Enter positive number to increase, negative to decrease.</div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Adjustment Reason</label>
                                                                    <input type="text" name="reason" class="form-control" placeholder="e.g. Physical inventory audit recount" required>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-ss-primary">Save Adjustment</button>
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

                    <div class="px-4 pb-3">
                        <?php echo render_admin_pagination($pagination, url('/admin/inventory.php'), ['search' => $search, 'stock_status' => $stockFilter]); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Inventory Movements Log -->
    <div class="col-lg-4">
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-clock-history text-primary me-2"></i> Recent Stock Mutations</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($recentMovements)): ?>
                    <div class="p-3 text-ss-muted small">No inventory movement logs recorded yet.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush small">
                        <?php foreach ($recentMovements as $mov): ?>
                            <div class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-dark text-truncate" style="max-width: 180px;"><?php echo e($mov['product_name']); ?></strong>
                                    <span class="badge <?php echo $mov['quantity_change'] > 0 ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo $mov['quantity_change'] > 0 ? '+' : ''; ?><?php echo (int)$mov['quantity_change']; ?>
                                    </span>
                                </div>
                                <div class="text-ss-muted">
                                    <span class="text-uppercase fw-semibold"><?php echo e($mov['type']); ?></span> &bull; 
                                    <?php echo format_date($mov['created_at'], 'M d, h:i A'); ?>
                                </div>
                                <?php if (!empty($mov['reason'])): ?>
                                    <div class="small fst-italic text-dark mt-1">"<?php echo e($mov['reason']); ?>"</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
