<?php
/**
 * ShopSphere - Seller Products Management
 */
$page_title = 'My Products';
$current_page = 'products';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/ProductService.php';

$productService = new ProductService();

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    check_csrf();
    $delete_id = (int)($_POST['product_id'] ?? 0);
    try {
        if ($productService->deleteProduct($delete_id, $seller_id)) {
            flash('success', 'Product has been archived.');
        } else {
            flash('error', 'Unable to delete product.');
        }
    } catch (Exception $e) {
        flash('error', 'Error: ' . $e->getMessage());
    }
    redirect(url('/seller/products.php'));
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$filters = [
    'seller_id' => $seller_id,
    'search' => $search
];
if (!empty($status)) {
    $filters['status'] = $status;
}

$result = $productService->getProducts($filters, $page, $per_page);
$products = $result['products'];
$total = $result['total'];
$total_pages = ceil($total / $per_page);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Store Product Catalog</h4>
        <p class="text-ss-muted small mb-0">Total <?php echo $total; ?> products listed under your merchant account</p>
    </div>
    <a href="<?php echo url('/seller/add-product.php'); ?>" class="btn btn-ss-primary">
        <i class="bi bi-plus-lg"></i> Add New Product
    </a>
</div>

<!-- Filters -->
<div class="ss-card mb-4 p-3 bg-white">
    <form method="GET" action="<?php echo url('/seller/products.php'); ?>" class="row g-2 align-items-center">
        <div class="col-md-5">
            <input type="text" name="q" class="ss-form-control form-control-sm" placeholder="Search by product name or SKU..." value="<?php echo e($search); ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="ss-form-control form-control-sm">
                <option value="">All Statuses</option>
                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved (Active)</option>
                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-ss-primary btn-sm flex-grow-1"><i class="bi bi-search"></i> Filter</button>
            <?php if (!empty($search) || !empty($status)): ?>
                <a href="<?php echo url('/seller/products.php'); ?>" class="btn btn-ss-outline btn-sm">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Products Table -->
<div class="ss-card">
    <div class="ss-card-body p-0">
        <?php if (empty($products)): ?>
            <div class="py-5 text-center text-ss-muted">
                <i class="bi bi-box-seam fs-1 d-block mb-2 text-muted"></i>
                <h5>No products found</h5>
                <p class="small mb-3">You haven't added any products matching the criteria.</p>
                <a href="<?php echo url('/seller/add-product.php'); ?>" class="btn btn-ss-primary btn-sm">Add First Product</a>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table mb-0">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Image</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Views / Sales</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <div style="width: 48px; height: 48px; border-radius: 4px; overflow: hidden; background: #f3f4f6;" class="border">
                                        <img src="<?php echo !empty($p['image_path']) ? asset($p['image_path']) : asset('images/misc/placeholder.svg'); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                    </div>
                                </td>
                                <td>
                                    <strong><a href="<?php echo url('/seller/edit-product.php?id=' . $p['id']); ?>" class="text-ss-primary"><?php echo e($p['name']); ?></a></strong>
                                    <div class="small text-ss-muted">SKU: <?php echo e($p['sku'] ?? 'N/A'); ?></div>
                                </td>
                                <td><?php echo e($p['category_name'] ?? 'General'); ?></td>
                                <td>
                                    <strong><?php echo format_price($p['price']); ?></strong>
                                    <?php if (!empty($p['compare_price']) && $p['compare_price'] > $p['price']): ?>
                                        <div class="small text-decoration-line-through text-muted"><?php echo format_price($p['compare_price']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($p['stock_quantity'] <= $p['low_stock_threshold']): ?>
                                        <span class="badge bg-danger-subtle text-danger"><?php echo $p['stock_quantity']; ?> (Low)</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success"><?php echo $p['stock_quantity']; ?> in stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><i class="bi bi-eye"></i> <?php echo $p['views_count']; ?> | <i class="bi bi-bag"></i> <?php echo $p['sales_count']; ?></small>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php 
                                        echo match($p['status']) {
                                            'approved' => 'success',
                                            'pending' => 'warning',
                                            'rejected' => 'danger',
                                            default => 'default'
                                        };
                                    ?>">
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?php echo url('/product.php?slug=' . $p['slug']); ?>" target="_blank" class="btn btn-light border" title="Preview on storefront">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?php echo url('/seller/edit-product.php?id=' . $p['id']); ?>" class="btn btn-light border" title="Edit Product">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="<?php echo url('/seller/products.php'); ?>" onsubmit="return confirm('Archive this product listing?');" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn btn-light border text-danger" title="Archive">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
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
            <a href="<?php echo url('/seller/products.php?' . http_build_query(array_merge($_GET, ['page' => $p]))); ?>" class="page-link <?php echo $p === $page ? 'active' : ''; ?>">
                <?php echo $p; ?>
            </a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
