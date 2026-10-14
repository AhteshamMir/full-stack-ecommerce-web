<?php
/**
 * ShopSphere - Category Management
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

// Handle Form Submissions (Create, Update, Delete, Toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-tag');
        $rateInput = trim($_POST['commission_rate'] ?? '');
        $commissionRate = ($rateInput === '' || $rateInput === null) ? null : (float)$rateInput;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            flash('error', 'Category name is required.');
            redirect(url('/admin/categories.php'));
        }

        if ($slug === '') {
            $slug = generate_slug($name);
        } else {
            $slug = generate_slug($slug);
        }

        // Check unique slug
        $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        if ((int)$stmt->fetchColumn() > 0) {
            $slug = $slug . '-' . time();
        }

        if ($commissionRate !== null && ($commissionRate < 0 || $commissionRate > 100)) {
            flash('error', 'Commission rate must be between 0% and 100%.');
            redirect(url('/admin/categories.php'));
        }

        $stmt = $db->prepare("
            INSERT INTO categories (parent_id, name, slug, description, icon, commission_rate, sort_order, is_active, created_at, updated_at)
            VALUES (:parent_id, :name, :slug, :description, :icon, :commission_rate, :sort_order, :is_active, NOW(), NOW())
        ");
        $stmt->execute([
            'parent_id'       => $parentId,
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'icon'            => $icon,
            'commission_rate' => $commissionRate,
            'sort_order'      => $sortOrder,
            'is_active'       => $isActive,
        ]);
        $newId = (int)$db->lastInsertId();

        log_audit($db, 'create_category', 'categories', $newId, null, [
            'name' => $name, 'slug' => $slug, 'commission_rate' => $commissionRate
        ]);

        flash('success', "Category \"{$name}\" created successfully.");
        redirect(url('/admin/categories.php'));

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-tag');
        $rateInput = trim($_POST['commission_rate'] ?? '');
        $commissionRate = ($rateInput === '' || $rateInput === null) ? null : (float)$rateInput;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id <= 0 || $name === '') {
            flash('error', 'Category ID and name are required.');
            redirect(url('/admin/categories.php'));
        }

        if ($parentId === $id) {
            flash('error', 'A category cannot be its own parent.');
            redirect(url('/admin/categories.php'));
        }

        $slug = ($slug === '') ? generate_slug($name) : generate_slug($slug);

        // Check slug uniqueness excluding self
        $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = :slug AND id != :id");
        $stmt->execute(['slug' => $slug, 'id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $slug = $slug . '-' . $id;
        }

        if ($commissionRate !== null && ($commissionRate < 0 || $commissionRate > 100)) {
            flash('error', 'Commission rate must be between 0% and 100%.');
            redirect(url('/admin/categories.php'));
        }

        $stmt = $db->prepare("
            UPDATE categories 
            SET parent_id = :parent_id, name = :name, slug = :slug, description = :description, icon = :icon, 
                commission_rate = :commission_rate, sort_order = :sort_order, is_active = :is_active, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'parent_id'       => $parentId,
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'icon'            => $icon,
            'commission_rate' => $commissionRate,
            'sort_order'      => $sortOrder,
            'is_active'       => $isActive,
            'id'              => $id,
        ]);

        log_audit($db, 'update_category', 'categories', $id, null, ['name' => $name, 'slug' => $slug]);

        flash('success', "Category \"{$name}\" updated successfully.");
        redirect(url('/admin/categories.php'));

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid category identifier.');
            redirect(url('/admin/categories.php'));
        }

        // Check if products exist in category
        $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = :id");
        $stmt->execute(['id' => $id]);
        $productCount = (int)$stmt->fetchColumn();

        if ($productCount > 0) {
            flash('error', "Cannot delete category: {$productCount} product(s) are currently categorized under it. Please reassign products first.");
            redirect(url('/admin/categories.php'));
        }

        // Promote subcategories or set parent_id = null
        $db->prepare("UPDATE categories SET parent_id = NULL WHERE parent_id = :id")->execute(['id' => $id]);

        $stmt = $db->prepare("DELETE FROM categories WHERE id = :id");
        $stmt->execute(['id' => $id]);

        log_audit($db, 'delete_category', 'categories', $id);

        flash('success', 'Category deleted successfully.');
        redirect(url('/admin/categories.php'));

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, is_active FROM categories WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $cat = $stmt->fetch();

        if ($cat) {
            $newActive = $cat['is_active'] ? 0 : 1;
            $db->prepare("UPDATE categories SET is_active = :v, updated_at = NOW() WHERE id = :id")->execute(['v' => $newActive, 'id' => $id]);
            log_audit($db, 'toggle_category_active', 'categories', $id, ['is_active' => $cat['is_active']], ['is_active' => $newActive]);
            flash('success', 'Category visibility status updated.');
        }

        redirect(url('/admin/categories.php'));
    }
}

// Fetch all categories for hierarchy tree and table
$stmt = $db->query("
    SELECT c.*, p.name AS parent_name,
        (SELECT COUNT(*) FROM products prod WHERE prod.category_id = c.id) AS products_count,
        (SELECT COUNT(*) FROM categories sub WHERE sub.parent_id = c.id) AS subcategories_count
    FROM categories c
    LEFT JOIN categories p ON c.parent_id = p.id
    ORDER BY COALESCE(c.parent_id, c.id), c.parent_id IS NOT NULL, c.sort_order ASC, c.name ASC
");
$allCategories = $stmt->fetchAll();

// Root categories for dropdown selection
$rootCategories = array_filter($allCategories, fn($c) => empty($c['parent_id']));

$page_title = 'Category Management';
$current_page = 'categories';
require_once __DIR__ . '/header.php';
?>

<div class="row g-4">
    <!-- Left Column: Add New Category Form -->
    <div class="col-lg-4">
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-folder-plus text-primary me-2"></i> Add New Category</h5>
            </div>
            <div class="ss-card-body">
                <form method="POST" action="<?php echo url('/admin/categories.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Smartphones & Accessories" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Slug (Optional)</label>
                        <input type="text" name="slug" class="form-control" placeholder="e.g. smartphones-accessories">
                        <div class="form-text small">Leave blank to automatically generate from name.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Parent Category</label>
                        <select name="parent_id" class="form-select">
                            <option value="">— None (Top-Level Category) —</option>
                            <?php foreach ($rootCategories as $rc): ?>
                                <option value="<?php echo $rc['id']; ?>"><?php echo e($rc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Commission Rate (%)</label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0" max="100" name="commission_rate" class="form-control" placeholder="e.g. 8.50">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text small">Leave blank to inherit platform standard commission.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bootstrap Icon Class</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-tag"></i></span>
                            <input type="text" name="icon" class="form-control" placeholder="e.g. bi-phone, bi-laptop, bi-bag" value="bi-tag">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Short description for SEO and category headers..."></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="createIsActive" checked value="1">
                        <label class="form-check-label" for="createIsActive">Active & Visible in Storefront</label>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100">
                        <i class="bi bi-plus-lg"></i> Create Category
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Categories List Table -->
    <div class="col-lg-8">
        <div class="ss-card">
            <div class="ss-card-header d-flex justify-content-between align-items-center">
                <h5>All Categories (<?php echo count($allCategories); ?>)</h5>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($allCategories)): ?>
                    <div class="p-5 text-center text-ss-muted">
                        <i class="bi bi-tags fs-1 d-block mb-2"></i>
                        No categories found. Use the form on the left to add one.
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Category Name</th>
                                    <th>Slug</th>
                                    <th>Commission</th>
                                    <th>Products</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allCategories as $cat): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if (!empty($cat['parent_id'])): ?>
                                                    <span class="text-ss-muted ps-3">&rdquor;</span>
                                                <?php endif; ?>
                                                <div class="rounded bg-light border d-flex align-items-center justify-content-center text-primary" style="width: 32px; height: 32px;">
                                                    <i class="bi <?php echo e($cat['icon'] ?: 'bi-tag'); ?>"></i>
                                                </div>
                                                <div>
                                                    <strong class="text-dark d-block">
                                                        <?php echo e($cat['name']); ?>
                                                    </strong>
                                                    <?php if (!empty($cat['parent_name'])): ?>
                                                        <span class="small text-ss-muted">Parent: <?php echo e($cat['parent_name']); ?></span>
                                                    <?php else: ?>
                                                        <span class="small text-primary fw-semibold">Top Level (<?php echo (int)$cat['subcategories_count']; ?> sub)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><code><?php echo e($cat['slug']); ?></code></td>
                                        <td>
                                            <?php if ($cat['commission_rate'] !== null): ?>
                                                <span class="badge bg-info text-dark"><?php echo number_format((float)$cat['commission_rate'], 1); ?>%</span>
                                            <?php else: ?>
                                                <span class="small text-ss-muted">Default</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo url('/admin/products.php?category_id=' . $cat['id']); ?>" class="badge bg-light text-dark border">
                                                <?php echo (int)$cat['products_count']; ?> items
                                            </a>
                                        </td>
                                        <td>
                                            <form method="POST" action="<?php echo url('/admin/categories.php'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                                <button type="submit" class="border-0 bg-transparent p-0" title="Click to toggle status">
                                                    <span class="ss-badge ss-badge-<?php echo $cat['is_active'] ? 'success' : 'default'; ?>" style="cursor: pointer;">
                                                        <?php echo $cat['is_active'] ? 'Active' : 'Hidden'; ?>
                                                    </span>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-light border btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $cat['id']; ?>" title="Edit Category">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <form method="POST" action="<?php echo url('/admin/categories.php'); ?>" onsubmit="return confirm('Are you sure you want to delete category &quot;<?php echo addslashes($cat['name']); ?>&quot;?');" class="d-inline">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Category" <?php echo $cat['products_count'] > 0 ? 'disabled' : ''; ?>>
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>

                                            <!-- Edit Category Modal -->
                                            <div class="modal fade text-start" id="editModal<?php echo $cat['id']; ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST" action="<?php echo url('/admin/categories.php'); ?>">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="update">
                                                            <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Category: <?php echo e($cat['name']); ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Category Name</label>
                                                                    <input type="text" name="name" class="form-control" value="<?php echo e($cat['name']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Slug</label>
                                                                    <input type="text" name="slug" class="form-control" value="<?php echo e($cat['slug']); ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Parent Category</label>
                                                                    <select name="parent_id" class="form-select">
                                                                        <option value="">— None (Top-Level) —</option>
                                                                        <?php foreach ($rootCategories as $rc): ?>
                                                                            <?php if ($rc['id'] !== $cat['id']): ?>
                                                                                <option value="<?php echo $rc['id']; ?>" <?php echo $cat['parent_id'] == $rc['id'] ? 'selected' : ''; ?>>
                                                                                    <?php echo e($rc['name']); ?>
                                                                                </option>
                                                                            <?php endif; ?>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Commission Override (%)</label>
                                                                    <div class="input-group">
                                                                        <input type="number" step="0.01" min="0" max="100" name="commission_rate" class="form-control" value="<?php echo $cat['commission_rate'] !== null ? e((string)$cat['commission_rate']) : ''; ?>">
                                                                        <span class="input-group-text">%</span>
                                                                    </div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Bootstrap Icon Class</label>
                                                                    <input type="text" name="icon" class="form-control" value="<?php echo e($cat['icon'] ?: 'bi-tag'); ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Sort Order</label>
                                                                    <input type="number" name="sort_order" class="form-control" value="<?php echo (int)$cat['sort_order']; ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold">Description</label>
                                                                    <textarea name="description" class="form-control" rows="3"><?php echo e($cat['description'] ?? ''); ?></textarea>
                                                                </div>
                                                                <div class="form-check form-switch mb-2">
                                                                    <input class="form-check-input" type="checkbox" name="is_active" id="editActive<?php echo $cat['id']; ?>" <?php echo $cat['is_active'] ? 'checked' : ''; ?> value="1">
                                                                    <label class="form-check-label" for="editActive<?php echo $cat['id']; ?>">Active & Visible</label>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" class="btn btn-ss-primary">Save Changes</button>
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
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
