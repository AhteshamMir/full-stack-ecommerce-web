<?php
/**
 * ShopSphere - Seller Edit Product
 */
$page_title = 'Edit Product';
$current_page = 'products';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../classes/ProductService.php';

$productService = new ProductService();
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verify ownership
$stmt = $db->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ? AND p.seller_id = ? LIMIT 1");
$stmt->execute([$product_id, $seller_id]);
$product = $stmt->fetch();

if (!$product) {
    flash('error', 'Product not found or access denied.');
    redirect(url('/seller/products.php'));
}

$categories = $productService->getCategories();
$images = $productService->getProductImages($product_id);
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
    if ($category_id <= 0) $errors[] = 'Please select a category.';
    if ($price <= 0) $errors[] = 'Price must be greater than zero.';

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
                'description' => $description
            ];

            $productService->updateProduct($product_id, $seller_id, $data);

            // Handle new image upload
            if (!empty($_FILES['primary_image']['name'])) {
                $uploadResult = upload_image($_FILES['primary_image'], 'products');
                if ($uploadResult['success']) {
                    $productService->addProductImage($product_id, $uploadResult['path'], '', 0, true);
                }
            }

            flash('success', 'Product updated successfully.');
            redirect(url('/seller/edit-product.php?id=' . $product_id));
        } catch (Exception $e) {
            $errors[] = 'Update failed: ' . $e->getMessage();
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Edit: <?php echo e(truncate($product['name'], 40)); ?></h4>
            <div class="d-flex gap-2">
                <a href="<?php echo url('/product.php?id=' . $product_id); ?>" target="_blank" class="btn btn-ss-outline btn-sm">
                    <i class="bi bi-eye"></i> Preview
                </a>
                <a href="<?php echo url('/seller/products.php'); ?>" class="btn btn-ss-outline btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="ss-alert ss-alert-danger mb-4">
                <i class="bi bi-exclamation-triangle"></i>
                <div><ul class="mb-0 ps-3"><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
            </div>
        <?php endif; ?>

        <!-- Current Images -->
        <?php if (!empty($images)): ?>
            <div class="ss-card mb-4 p-3">
                <h6 class="mb-3">Current Images</h6>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($images as $img): ?>
                        <div class="position-relative" style="width: 100px; height: 100px;">
                            <img src="<?php echo e(asset($img['image_path'])); ?>" alt="" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px; border: 2px solid <?php echo $img['is_primary'] ? '#4f6df5' : '#e5e7eb'; ?>;">
                            <?php if ($img['is_primary']): ?>
                                <span class="badge bg-primary position-absolute top-0 start-0" style="font-size: 0.6rem;">Primary</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="ss-card p-4">
            <form action="<?php echo url('/seller/edit-product.php?id=' . $product_id); ?>" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <div class="row g-3">
                    <div class="col-12 ss-form-group">
                        <label for="name">Product Title <span class="required">*</span></label>
                        <input type="text" id="name" name="name" class="ss-form-control" required value="<?php echo e($product['name']); ?>">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="category_id">Category <span class="required">*</span></label>
                        <select id="category_id" name="category_id" class="ss-form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="sku">SKU</label>
                        <input type="text" id="sku" name="sku" class="ss-form-control" value="<?php echo e($product['sku']); ?>">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="price">Selling Price (PKR) <span class="required">*</span></label>
                        <input type="number" step="0.01" id="price" name="price" class="ss-form-control" required value="<?php echo e($product['price']); ?>">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="compare_price">Compare / Original Price</label>
                        <input type="number" step="0.01" id="compare_price" name="compare_price" class="ss-form-control" value="<?php echo e($product['compare_price']); ?>">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="stock_quantity">Stock Quantity</label>
                        <input type="number" id="stock_quantity" name="stock_quantity" class="ss-form-control" value="<?php echo e($product['stock_quantity']); ?>" min="0">
                    </div>

                    <div class="col-md-6 ss-form-group">
                        <label for="low_stock_threshold">Low Stock Alert Threshold</label>
                        <input type="number" id="low_stock_threshold" name="low_stock_threshold" class="ss-form-control" value="<?php echo e($product['low_stock_threshold']); ?>" min="1">
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="primary_image">Upload New Primary Image (Optional)</label>
                        <input type="file" id="primary_image" name="primary_image" class="ss-form-control ss-file-upload" accept="image/*">
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="short_description">Short Summary</label>
                        <textarea id="short_description" name="short_description" rows="2" class="ss-form-control"><?php echo e($product['short_description']); ?></textarea>
                    </div>

                    <div class="col-12 ss-form-group">
                        <label for="description">Full Description</label>
                        <textarea id="description" name="description" rows="6" class="ss-form-control"><?php echo e($product['description']); ?></textarea>
                    </div>

                    <div class="col-12 mt-3 pt-3 border-top d-flex gap-3">
                        <button type="submit" class="btn btn-ss-primary px-4 py-2">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                        <a href="<?php echo url('/seller/products.php'); ?>" class="btn btn-ss-outline px-4 py-2">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
