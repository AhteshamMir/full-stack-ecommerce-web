<?php
/**
 * ShopSphere - Seller Add Product
 */
$page_title = 'Add New Product';
$current_page = 'add-product';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/ProductService.php';

$productService = new ProductService();
$categories = $productService->getCategories();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $name = trim($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = (float)($_POST['price'] ?? 0);
    $compare_price = !empty($_POST['compare_price']) ? (float)$_POST['compare_price'] : null;
    $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
    $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 5);
    $sku = trim($_POST['sku'] ?? '');
    $short_description = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name)) $errors[] = 'Product title is required.';
    if ($category_id <= 0) $errors[] = 'Please select a valid category.';
    if ($price <= 0) $errors[] = 'Price must be greater than zero.';
    if ($stock_quantity < 0) $errors[] = 'Stock quantity cannot be negative.';

    if (empty($errors)) {
        try {
            $data = [
                'name' => $name,
                'category_id' => $category_id,
                'price' => $price,
                'compare_price' => $compare_price,
                'stock_quantity' => $stock_quantity,
                'low_stock_threshold' => $low_stock_threshold,
                'sku' => $sku,
                'short_description' => $short_description,
                'description' => $description,
                'status' => 'pending' // Pending admin approval
            ];

            $product_id = $productService->createProduct($seller_id, $data);

            // Handle primary image upload if supplied
            if (!empty($_FILES['primary_image']['name'])) {
                $uploadResult = upload_image($_FILES['primary_image'], 'products');
                if ($uploadResult['success']) {
                    $productService->addProductImage($product_id, $uploadResult['path'], '', 0, true);
                }
            }

            flash('success', 'Product has been created and submitted for administrative approval.');
            redirect(url('/seller/products.php'));
        } catch (Exception $e) {
            $errors[] = 'Failed to create product: ' . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Publish New Listing</h4>
            <a href="<?php echo url('/seller/products.php'); ?>" class="btn btn-ss-outline btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Products
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

        <div class="ss-card p-4">
            <form action="<?php echo url('/seller/add-product.php'); ?>" method="POST" enctype="multipart/form-data" class="ss-prevent-double-submit">
                <?php echo csrf_field(); ?>

                <div class="row g-3">
                    <div class="col-12 ss-form-group">
                        <label for="name">Product Title <span class="required">*</span></label>
                        <input type="text" id="name" name="name" class="ss-form-control" required value="<?php echo e($_POST['name'] ?? ''); ?>" placeholder="e.g. Wireless Bluetooth Noise-Cancelling Headphones">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="category_id">Category <span class="required">*</span></label>
                        <select id="category_id" name="category_id" class="ss-form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo ((int)($_POST['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="sku">SKU (Stock Keeping Unit)</label>
                        <input type="text" id="sku" name="sku" class="ss-form-control" value="<?php echo e($_POST['sku'] ?? ''); ?>" placeholder="e.g. WH-1000XM4-BLK">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="price">Selling Price (PKR) <span class="required">*</span></label>
                        <input type="number" step="0.01" id="price" name="price" class="ss-form-control" required value="<?php echo e($_POST['price'] ?? ''); ?>" placeholder="0.00">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="compare_price">Original / Compare Price (PKR)</label>
                        <input type="number" step="0.01" id="compare_price" name="compare_price" class="ss-form-control" value="<?php echo e($_POST['compare_price'] ?? ''); ?>" placeholder="Optional strikethrough price">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="stock_quantity">Available Stock <span class="required">*</span></label>
                        <input type="number" id="stock_quantity" name="stock_quantity" class="ss-form-control" required value="<?php echo e($_POST['stock_quantity'] ?? '1'); ?>" min="0">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="low_stock_threshold">Low Stock Warning Threshold</label>
                        <input type="number" id="low_stock_threshold" name="low_stock_threshold" class="ss-form-control" value="<?php echo e($_POST['low_stock_threshold'] ?? '5'); ?>" min="1">
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="primary_image">Primary Product Photograph</label>
                        <input type="file" id="primary_image" name="primary_image" class="ss-form-control ss-file-upload" accept="image/jpeg,image/png,image/webp">
                        <small class="text-ss-muted">Accepted formats: JPG, PNG, WebP (Max 5MB). Professional photography recommended.</small>
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="short_description">Short Summary</label>
                        <textarea id="short_description" name="short_description" rows="2" class="ss-form-control" placeholder="Brief 1-2 sentence overview shown in search results"><?php echo e($_POST['short_description'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="description">Full Product Specifications & Details</label>
                        <textarea id="description" name="description" rows="6" class="ss-form-control" placeholder="Detailed technical specifications, in-box items, warranty, etc."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-12 mt-4 pt-3 border-top d-flex gap-3">
                        <button type="submit" class="btn btn-ss-primary px-4 py-2">
                            <i class="bi bi-cloud-arrow-up"></i> Submit for Review
                        </button>
                        <a href="<?php echo url('/seller/products.php'); ?>" class="btn btn-ss-outline px-4 py-2">
                            Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
