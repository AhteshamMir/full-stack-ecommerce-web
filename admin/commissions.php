<?php
/**
 * ShopSphere - Commission Rules & Fee Matrix
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

// Handle Actions (Default Rate, Rules CRUD, Category & Seller Overrides)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_default_rate') {
        $rate = (float)($_POST['default_rate'] ?? 10.0);
        if ($rate < 0 || $rate > 100) {
            flash('error', 'Rate must be between 0% and 100%.');
            redirect(url('/admin/commissions.php'));
        }

        // Update in website_settings
        $stmt = $db->prepare("
            INSERT INTO website_settings (setting_key, setting_value, setting_group, created_at, updated_at)
            VALUES ('default_commission_rate', :val, 'general', NOW(), NOW())
            ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()
        ");
        $stmt->execute(['val' => (string)$rate, 'val2' => (string)$rate]);

        // Insert / Update in commission_rules as active default rule
        $stmtRule = $db->prepare("
            INSERT INTO commission_rules (type, rate, effective_from, created_by, created_at, updated_at)
            VALUES ('default', :rate, CURDATE(), :user_id, NOW(), NOW())
        ");
        $stmtRule->execute(['rate' => $rate, 'user_id' => get_current_user_id()]);

        log_audit($db, 'update_default_commission', 'website_settings', null, null, ['default_rate' => $rate]);
        flash('success', "Platform default commission rate set to {$rate}%.");
        redirect(url('/admin/commissions.php'));

    } elseif ($action === 'create_rule') {
        $type = in_array($_POST['type'] ?? '', ['default', 'category', 'seller'], true) ? $_POST['type'] : 'default';
        $rate = (float)($_POST['rate'] ?? 0);
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $sellerId = !empty($_POST['seller_id']) ? (int)$_POST['seller_id'] : null;
        $effectiveFrom = !empty($_POST['effective_from']) ? $_POST['effective_from'] : date('Y-m-d');
        $effectiveTo = !empty($_POST['effective_to']) ? $_POST['effective_to'] : null;

        if ($rate < 0 || $rate > 100) {
            flash('error', 'Rate must be between 0% and 100%.');
            redirect(url('/admin/commissions.php'));
        }

        if ($type === 'category' && !$categoryId) {
            flash('error', 'Please select a target category for category-level commission rule.');
            redirect(url('/admin/commissions.php'));
        }

        if ($type === 'seller' && !$sellerId) {
            flash('error', 'Please select a vendor store for seller-level commission rule.');
            redirect(url('/admin/commissions.php'));
        }

        $stmt = $db->prepare("
            INSERT INTO commission_rules (type, category_id, seller_id, rate, effective_from, effective_to, created_by, created_at, updated_at)
            VALUES (:type, :cat_id, :seller_id, :rate, :from, :to, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            'type'       => $type,
            'cat_id'     => ($type === 'category') ? $categoryId : null,
            'seller_id'  => ($type === 'seller') ? $sellerId : null,
            'rate'       => $rate,
            'from'       => $effectiveFrom,
            'to'         => $effectiveTo,
            'created_by' => get_current_user_id()
        ]);
        $ruleId = (int)$db->lastInsertId();

        log_audit($db, 'create_commission_rule', 'commission_rules', $ruleId, null, ['type' => $type, 'rate' => $rate]);
        flash('success', 'Commission rule created successfully.');
        redirect(url('/admin/commissions.php'));

    } elseif ($action === 'delete_rule') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM commission_rules WHERE id = :id");
        $stmt->execute(['id' => $id]);

        log_audit($db, 'delete_commission_rule', 'commission_rules', $id);
        flash('success', 'Commission rule deleted.');
        redirect(url('/admin/commissions.php'));

    } elseif ($action === 'update_category_rate') {
        $catId = (int)($_POST['category_id'] ?? 0);
        $rateInput = trim($_POST['rate'] ?? '');
        $rate = ($rateInput === '' || $rateInput === null) ? null : (float)$rateInput;

        if ($catId > 0) {
            $stmt = $db->prepare("UPDATE categories SET commission_rate = :r, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['r' => $rate, 'id' => $catId]);
            log_audit($db, 'update_category_commission', 'categories', $catId, null, ['commission_rate' => $rate]);
            flash('success', 'Category commission rate updated.');
        }
        redirect(url('/admin/commissions.php'));

    } elseif ($action === 'update_seller_override') {
        $sellerId = (int)($_POST['seller_id'] ?? 0);
        $rateInput = trim($_POST['rate'] ?? '');
        $rate = ($rateInput === '' || $rateInput === null) ? null : (float)$rateInput;

        if ($sellerId > 0) {
            $stmt = $db->prepare("UPDATE seller_profiles SET commission_rate = :r, updated_at = NOW() WHERE user_id = :id");
            $stmt->execute(['r' => $rate, 'id' => $sellerId]);
            log_audit($db, 'update_seller_commission_override', 'seller_profiles', $sellerId, null, ['commission_rate' => $rate]);
            flash('success', 'Seller commission override updated.');
        }
        redirect(url('/admin/commissions.php'));
    }
}

// Fetch Global Default Rate
$defaultRate = (float)get_setting('default_commission_rate', defined('DEFAULT_COMMISSION_RATE') ? DEFAULT_COMMISSION_RATE : 10.0);

// Fetch All Commission Rules
$stmt = $db->query("
    SELECT cr.*, 
        c.name AS category_name,
        sp.store_name,
        u.first_name, u.last_name
    FROM commission_rules cr
    LEFT JOIN categories c ON cr.category_id = c.id
    LEFT JOIN seller_profiles sp ON cr.seller_id = sp.user_id
    LEFT JOIN users u ON cr.created_by = u.id
    ORDER BY cr.effective_from DESC, cr.id DESC
");
$rules = $stmt->fetchAll();

// Fetch Categories with their rates
$categories = $db->query("SELECT id, name, commission_rate FROM categories ORDER BY name ASC")->fetchAll();

// Fetch Sellers with custom rates
$sellers = $db->query("SELECT user_id, store_name, commission_rate FROM seller_profiles WHERE status = 'approved' ORDER BY store_name ASC")->fetchAll();

$page_title = 'Commission Rules & Fee Engine';
$current_page = 'commissions';
require_once __DIR__ . '/header.php';
?>

<div class="row g-4 mb-4">
    <!-- Default Rate Widget -->
    <div class="col-lg-4">
        <div class="ss-card mb-4">
            <div class="ss-card-header">
                <h5><i class="bi bi-globe text-primary me-2"></i> Standard Platform Rate</h5>
            </div>
            <div class="ss-card-body">
                <p class="small text-ss-muted mb-3">
                    This global default fee applies to all marketplace sales unless overridden by a category or vendor-specific rule.
                </p>
                <form method="POST" action="<?php echo url('/admin/commissions.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_default_rate">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Platform Standard Rate (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" name="default_rate" class="form-control form-control-lg fw-bold" value="<?php echo number_format($defaultRate, 2); ?>" required>
                            <span class="input-group-text fs-5">%</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-ss-primary w-100">
                        <i class="bi bi-check2"></i> Update Global Rate
                    </button>
                </form>
            </div>
        </div>

        <!-- Add Custom Rule Widget -->
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-plus-circle text-primary me-2"></i> Add Commission Rule</h5>
            </div>
            <div class="ss-card-body">
                <form method="POST" action="<?php echo url('/admin/commissions.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create_rule">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Rule Scope</label>
                        <select name="type" class="form-select" id="ruleTypeSelect" required onchange="toggleRuleFields()">
                            <option value="category">Category Specific</option>
                            <option value="seller">Seller / Vendor Specific</option>
                            <option value="default">Global Platform Baseline</option>
                        </select>
                    </div>

                    <div class="mb-3" id="catGroup">
                        <label class="form-label fw-bold">Select Category</label>
                        <select name="category_id" class="form-select">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="sellerGroup">
                        <label class="form-label fw-bold">Select Vendor Store</label>
                        <select name="seller_id" class="form-select">
                            <option value="">— Select Seller Store —</option>
                            <?php foreach ($sellers as $s): ?>
                                <option value="<?php echo $s['user_id']; ?>"><?php echo e($s['store_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Commission Rate (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" name="rate" class="form-control" placeholder="e.g. 7.50" required>
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Effective From</label>
                            <input type="date" name="effective_from" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Effective To</label>
                            <input type="date" name="effective_to" class="form-control">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100">
                        <i class="bi bi-save"></i> Save Rule
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Rules, Category Rates, and Seller Overrides Tabs -->
    <div class="col-lg-8">
        <div class="ss-card">
            <div class="ss-card-header p-0">
                <ul class="nav nav-tabs border-0 px-3 pt-2" id="commTab" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="rules-tab" data-bs-toggle="tab" data-bs-target="#rules-pane" type="button" role="tab">
                            <i class="bi bi-card-checklist me-1"></i> Commission Rules Table (<?php echo count($rules); ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="cat-tab" data-bs-toggle="tab" data-bs-target="#cat-pane" type="button" role="tab">
                            <i class="bi bi-tags me-1"></i> Category Rates (<?php echo count($categories); ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="sellers-tab" data-bs-toggle="tab" data-bs-target="#sellers-pane" type="button" role="tab">
                            <i class="bi bi-shop me-1"></i> Seller Overrides (<?php echo count($sellers); ?>)
                        </button>
                    </li>
                </ul>
            </div>
            <div class="tab-content" id="commTabContent">
                <!-- Rules Table Pane -->
                <div class="tab-pane fade show active p-0" id="rules-pane" role="tabpanel">
                    <?php if (empty($rules)): ?>
                        <div class="p-4 text-center text-ss-muted">
                            No custom rules configured yet. The platform uses standard baseline rates.
                        </div>
                    <?php else: ?>
                        <div class="ss-table-responsive">
                            <table class="ss-table mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Rule Target</th>
                                        <th>Rate</th>
                                        <th>Effective Window</th>
                                        <th>Created By</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rules as $r): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="badge <?php echo match($r['type']) {
                                                        'category' => 'bg-info text-dark',
                                                        'seller' => 'bg-warning text-dark',
                                                        default => 'bg-primary'
                                                    }; ?>">
                                                        <?php echo ucfirst($r['type']); ?>
                                                    </span>
                                                    <strong>
                                                        <?php if ($r['type'] === 'category'): ?>
                                                            <?php echo e($r['category_name'] ?? 'Category #' . $r['category_id']); ?>
                                                        <?php elseif ($r['type'] === 'seller'): ?>
                                                            <?php echo e($r['store_name'] ?? 'Seller #' . $r['seller_id']); ?>
                                                        <?php else: ?>
                                                            Global Baseline
                                                        <?php endif; ?>
                                                    </strong>
                                                </div>
                                            </td>
                                            <td><strong class="text-success"><?php echo number_format((float)$r['rate'], 2); ?>%</strong></td>
                                            <td>
                                                <div class="small">From: <?php echo format_date($r['effective_from'], 'M d, Y'); ?></div>
                                                <div class="small text-ss-muted">To: <?php echo $r['effective_to'] ? format_date($r['effective_to'], 'M d, Y') : 'Ongoing'; ?></div>
                                            </td>
                                            <td><span class="small text-ss-muted"><?php echo e(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')); ?></span></td>
                                            <td class="text-end">
                                                <form method="POST" action="<?php echo url('/admin/commissions.php'); ?>" onsubmit="return confirm('Delete this commission rule?');" class="d-inline">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_rule">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Rule">
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

                <!-- Category Rates Pane -->
                <div class="tab-pane fade p-0" id="cat-pane" role="tabpanel">
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Current Commission Rate</th>
                                    <th class="text-end">Update Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr>
                                        <td><strong><?php echo e($cat['name']); ?></strong></td>
                                        <td>
                                            <?php if ($cat['commission_rate'] !== null): ?>
                                                <span class="badge bg-success"><?php echo number_format((float)$cat['commission_rate'], 2); ?>% Custom</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">Default (<?php echo number_format($defaultRate, 2); ?>%)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="<?php echo url('/admin/commissions.php'); ?>" class="d-inline-flex gap-1 justify-content-end" style="max-width: 250px;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="update_category_rate">
                                                <input type="hidden" name="category_id" value="<?php echo (int)$cat['id']; ?>">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" step="0.01" min="0" max="100" name="rate" class="form-control" placeholder="Default" value="<?php echo $cat['commission_rate'] !== null ? e((string)$cat['commission_rate']) : ''; ?>">
                                                    <button type="submit" class="btn btn-ss-primary btn-sm">Save</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Seller Overrides Pane -->
                <div class="tab-pane fade p-0" id="sellers-pane" role="tabpanel">
                    <div class="ss-table-responsive">
                        <table class="ss-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Merchant Store</th>
                                    <th>Current Vendor Rate</th>
                                    <th class="text-end">Set Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sellers as $s): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo url('/admin/seller-details.php?id=' . $s['user_id']); ?>" class="fw-bold text-dark">
                                                <?php echo e($s['store_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ($s['commission_rate'] !== null): ?>
                                                <span class="badge bg-warning text-dark"><?php echo number_format((float)$s['commission_rate'], 2); ?>% Override</span>
                                            <?php else: ?>
                                                <span class="text-ss-muted small">Category Standard</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="<?php echo url('/admin/commissions.php'); ?>" class="d-inline-flex gap-1 justify-content-end" style="max-width: 250px;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="update_seller_override">
                                                <input type="hidden" name="seller_id" value="<?php echo (int)$s['user_id']; ?>">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" step="0.01" min="0" max="100" name="rate" class="form-control" placeholder="Category" value="<?php echo $s['commission_rate'] !== null ? e((string)$s['commission_rate']) : ''; ?>">
                                                    <button type="submit" class="btn btn-ss-primary btn-sm">Save</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleRuleFields() {
    const val = document.getElementById('ruleTypeSelect').value;
    const catGroup = document.getElementById('catGroup');
    const sellerGroup = document.getElementById('sellerGroup');

    if (val === 'category') {
        catGroup.classList.remove('d-none');
        sellerGroup.classList.add('d-none');
    } else if (val === 'seller') {
        catGroup.classList.add('d-none');
        sellerGroup.classList.remove('d-none');
    } else {
        catGroup.classList.add('d-none');
        sellerGroup.classList.add('d-none');
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
