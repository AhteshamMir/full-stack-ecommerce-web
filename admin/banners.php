<?php
/**
 * ShopSphere - Banner & Marketing CMS
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

// Handle Banner Actions (Create, Update, Toggle, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $position = in_array($_POST['position'] ?? '', ['hero', 'sidebar', 'promotional', 'category'], true) ? $_POST['position'] : 'hero';
        $linkUrl = trim($_POST['link_url'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            flash('error', 'Banner title is required.');
            redirect(url('/admin/banners.php'));
        }

        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a banner image file.');
            redirect(url('/admin/banners.php'));
        }

        $upload = upload_image($_FILES['image'], 'uploads/banners');
        if (!$upload['success']) {
            flash('error', 'Image upload failed: ' . ($upload['error'] ?? 'Unknown error'));
            redirect(url('/admin/banners.php'));
        }

        $imagePath = $upload['path'];

        $stmt = $db->prepare("
            INSERT INTO banners (title, image_path, link_url, position, sort_order, is_active, starts_at, expires_at, created_at, updated_at)
            VALUES (:title, :img, :link, :pos, :sort, :active, :starts, :expires, NOW(), NOW())
        ");
        $stmt->execute([
            'title'   => $title,
            'img'     => $imagePath,
            'link'    => $linkUrl ?: null,
            'pos'     => $position,
            'sort'    => $sortOrder,
            'active'  => $isActive,
            'starts'  => $startsAt,
            'expires' => $expiresAt,
        ]);
        $newId = (int)$db->lastInsertId();

        log_audit($db, 'create_banner', 'banners', $newId, null, ['title' => $title, 'position' => $position]);
        flash('success', "Banner '{$title}' created successfully.");
        redirect(url('/admin/banners.php'));

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $position = in_array($_POST['position'] ?? '', ['hero', 'sidebar', 'promotional', 'category'], true) ? $_POST['position'] : 'hero';
        $linkUrl = trim($_POST['link_url'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $startsAt = !empty($_POST['starts_at']) ? $_POST['starts_at'] : null;
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $stmt = $db->prepare("SELECT * FROM banners WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            flash('error', 'Banner not found.');
            redirect(url('/admin/banners.php'));
        }

        $imagePath = $existing['image_path'];
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload = upload_image($_FILES['image'], 'uploads/banners');
            if ($upload['success']) {
                delete_file($existing['image_path']);
                $imagePath = $upload['path'];
            }
        }

        $stmt = $db->prepare("
            UPDATE banners 
            SET title = :title, image_path = :img, link_url = :link, position = :pos, 
                sort_order = :sort, is_active = :active, starts_at = :starts, expires_at = :expires, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'title'   => $title,
            'img'     => $imagePath,
            'link'    => $linkUrl ?: null,
            'pos'     => $position,
            'sort'    => $sortOrder,
            'active'  => $isActive,
            'starts'  => $startsAt,
            'expires' => $expiresAt,
            'id'      => $id,
        ]);

        log_audit($db, 'update_banner', 'banners', $id, ['title' => $existing['title']], ['title' => $title]);
        flash('success', "Banner '{$title}' updated successfully.");
        redirect(url('/admin/banners.php'));

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, is_active, title FROM banners WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $b = $stmt->fetch();

        if ($b) {
            $newActive = $b['is_active'] ? 0 : 1;
            $db->prepare("UPDATE banners SET is_active = :a, updated_at = NOW() WHERE id = :id")->execute(['a' => $newActive, 'id' => $id]);
            log_audit($db, 'toggle_banner_active', 'banners', $id, ['is_active' => $b['is_active']], ['is_active' => $newActive]);
            flash('success', "Banner status toggled.");
        }

        redirect(url('/admin/banners.php'));

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM banners WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $b = $stmt->fetch();

        if ($b) {
            delete_file($b['image_path']);
            $db->prepare("DELETE FROM banners WHERE id = :id")->execute(['id' => $id]);
            log_audit($db, 'delete_banner', 'banners', $id);
            flash('success', 'Banner deleted successfully.');
        }

        redirect(url('/admin/banners.php'));
    }
}

// Fetch Banners
$posFilter = trim($_GET['position'] ?? '');
$where = ["1=1"];
$params = [];

if ($posFilter !== '' && in_array($posFilter, ['hero', 'sidebar', 'promotional', 'category'], true)) {
    $where[] = "position = :pos";
    $params['pos'] = $posFilter;
}

$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("SELECT * FROM banners WHERE {$whereClause} ORDER BY sort_order ASC, id DESC");
$stmt->execute($params);
$banners = $stmt->fetchAll();

$page_title = 'Banner CMS';
$current_page = 'banners';
require_once __DIR__ . '/header.php';
?>

<div class="row g-4 mb-4">
    <!-- Left Column: Add New Banner -->
    <div class="col-lg-4">
        <div class="ss-card">
            <div class="ss-card-header">
                <h5><i class="bi bi-image text-primary me-2"></i> Add Promotional Banner</h5>
            </div>
            <div class="ss-card-body">
                <form method="POST" action="<?php echo url('/admin/banners.php'); ?>" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="create">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Banner Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Mega Electronics Super Sale" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Banner Placement Position</label>
                        <select name="position" class="form-select" required>
                            <option value="hero">Hero Main Slider (Top)</option>
                            <option value="promotional">Promotional Grid / Mid-page</option>
                            <option value="sidebar">Sidebar Banner</option>
                            <option value="category">Category Header</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Destination Link URL</label>
                        <input type="text" name="link_url" class="form-control" placeholder="e.g. /shop.php?category=electronics">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Banner Image File <span class="text-danger">*</span></label>
                        <input type="file" name="image" class="form-control" accept="image/*" required>
                        <div class="form-text small">Recommended: 1920x600 for hero, 800x400 for promotional.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Starts At</label>
                            <input type="datetime-local" name="starts_at" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">Expires At</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="bActive" checked value="1">
                        <label class="form-check-label" for="bActive">Active & Visible Immediately</label>
                    </div>

                    <button type="submit" class="btn btn-ss-primary w-100">
                        <i class="bi bi-upload"></i> Upload & Publish Banner
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Banners List -->
    <div class="col-lg-8">
        <div class="ss-card">
            <div class="ss-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">Configured Banners (<?php echo count($banners); ?>)</h5>
                <div class="d-flex gap-2">
                    <a href="<?php echo url('/admin/banners.php'); ?>" class="btn btn-sm <?php echo $posFilter === '' ? 'btn-primary' : 'btn-light border'; ?>">All</a>
                    <a href="<?php echo url('/admin/banners.php?position=hero'); ?>" class="btn btn-sm <?php echo $posFilter === 'hero' ? 'btn-primary' : 'btn-light border'; ?>">Hero</a>
                    <a href="<?php echo url('/admin/banners.php?position=promotional'); ?>" class="btn btn-sm <?php echo $posFilter === 'promotional' ? 'btn-primary' : 'btn-light border'; ?>">Promotional</a>
                    <a href="<?php echo url('/admin/banners.php?position=sidebar'); ?>" class="btn btn-sm <?php echo $posFilter === 'sidebar' ? 'btn-primary' : 'btn-light border'; ?>">Sidebar</a>
                </div>
            </div>
            <div class="ss-card-body p-0">
                <?php if (empty($banners)): ?>
                    <div class="p-5 text-center text-ss-muted">
                        <i class="bi bi-image fs-1 d-block mb-2"></i>
                        No banners uploaded in this placement.
                    </div>
                <?php else: ?>
                    <div class="ss-table-responsive">
                        <table class="ss-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Image Preview</th>
                                    <th>Title & Placement</th>
                                    <th>Link</th>
                                    <th>Order</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($banners as $b): ?>
                                    <tr>
                                        <td style="width: 140px;">
                                            <div class="rounded border overflow-hidden bg-light" style="width: 130px; height: 60px;">
                                                <img src="<?php echo asset($b['image_path']); ?>" alt="<?php echo e($b['title']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="d-block text-dark"><?php echo e($b['title']); ?></strong>
                                            <span class="badge bg-light text-dark border text-uppercase" style="font-size: 11px;">
                                                <?php echo e($b['position']); ?>
                                            </span>
                                            <?php if ($b['starts_at'] || $b['expires_at']): ?>
                                                <div class="small text-ss-muted mt-1">
                                                    <?php echo $b['starts_at'] ? format_date($b['starts_at'], 'M d') : 'Now'; ?> &rarr; 
                                                    <?php echo $b['expires_at'] ? format_date($b['expires_at'], 'M d, Y') : 'Indefinite'; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($b['link_url'])): ?>
                                                <a href="<?php echo e(url($b['link_url'])); ?>" target="_blank" class="small text-truncate d-inline-block" style="max-width: 150px;">
                                                    <i class="bi bi-link-45deg"></i> <?php echo e($b['link_url']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="small text-ss-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo (int)$b['sort_order']; ?></span>
                                        </td>
                                        <td>
                                            <form method="POST" action="<?php echo url('/admin/banners.php'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                                                <button type="submit" class="border-0 bg-transparent p-0" title="Click to toggle status">
                                                    <span class="ss-badge ss-badge-<?php echo $b['is_active'] ? 'success' : 'default'; ?>" style="cursor: pointer;">
                                                        <?php echo $b['is_active'] ? 'Active' : 'Disabled'; ?>
                                                    </span>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1 align-items-center">
                                                <!-- Edit Modal Trigger -->
                                                <button type="button" class="btn btn-light border btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $b['id']; ?>" title="Edit Banner">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <!-- Delete Banner -->
                                                <form method="POST" action="<?php echo url('/admin/banners.php'); ?>" onsubmit="return confirm('Delete banner <?php echo addslashes($b['title']); ?>?');" class="d-inline">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>

                                            <!-- Edit Banner Modal -->
                                            <div class="modal fade text-start" id="editModal<?php echo $b['id']; ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <form method="POST" action="<?php echo url('/admin/banners.php'); ?>" enctype="multipart/form-data">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="action" value="update">
                                                            <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Banner: <?php echo e($b['title']); ?></h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Banner Title</label>
                                                                    <input type="text" name="title" class="form-control" value="<?php echo e($b['title']); ?>" required>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Placement Position</label>
                                                                    <select name="position" class="form-select" required>
                                                                        <option value="hero" <?php echo $b['position'] === 'hero' ? 'selected' : ''; ?>>Hero Main Slider</option>
                                                                        <option value="promotional" <?php echo $b['position'] === 'promotional' ? 'selected' : ''; ?>>Promotional Grid</option>
                                                                        <option value="sidebar" <?php echo $b['position'] === 'sidebar' ? 'selected' : ''; ?>>Sidebar</option>
                                                                        <option value="category" <?php echo $b['position'] === 'category' ? 'selected' : ''; ?>>Category Header</option>
                                                                    </select>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Link URL</label>
                                                                    <input type="text" name="link_url" class="form-control" value="<?php echo e($b['link_url'] ?? ''); ?>">
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Replace Image (Optional)</label>
                                                                    <input type="file" name="image" class="form-control" accept="image/*">
                                                                    <div class="form-text small">Leave empty to retain existing banner image.</div>
                                                                </div>
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">Sort Order</label>
                                                                    <input type="number" name="sort_order" class="form-control" value="<?php echo (int)$b['sort_order']; ?>">
                                                                </div>
                                                                <div class="form-check form-switch mb-2">
                                                                    <input class="form-check-input" type="checkbox" name="is_active" id="editBActive<?php echo $b['id']; ?>" <?php echo $b['is_active'] ? 'checked' : ''; ?> value="1">
                                                                    <label class="form-check-label" for="editBActive<?php echo $b['id']; ?>">Active</label>
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
