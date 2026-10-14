<?php
/**
 * ShopSphere - Product Catalog & Category Management Service
 *
 * Manages products, variants, media galleries, categories, search indexing,
 * stock queries, and catalog curation algorithms (deals, featured, trending).
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class ProductService
{
    /**
     * Database connection instance.
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Constructor accepts optional PDO instance, defaulting to Database singleton.
     *
     * @param PDO|null $pdo Optional PDO instance.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * Query products with multi-attribute filtering, sorting, and pagination.
     *
     * @param array<string, mixed> $filters Filter parameters:
     *   - category_id (int|int[])
     *   - seller_id (int)
     *   - search (string)
     *   - min_price (float)
     *   - max_price (float)
     *   - status (string, defaults to 'approved' if null/not provided)
     *   - is_featured (bool)
     *   - is_trending (bool)
     *   - in_stock (bool)
     *   - sort (string: price_asc, price_desc, newest, rating, popular, views, name_asc)
     * @param int $page Current 1-based page number.
     * @param int $perPage Products per page.
     * @return array{products: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getProducts(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        // Status filter: defaults to 'approved' for public catalog unless explicitly overridden
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = 'p.status = :status';
            $params[':status'] = (string) $filters['status'];
        } elseif (!isset($filters['status'])) {
            $conditions[] = "p.status = 'approved'";
        }

        // Category filter (single ID or array of IDs)
        if (!empty($filters['category_id'])) {
            if (is_array($filters['category_id'])) {
                $placeholders = [];
                foreach ($filters['category_id'] as $idx => $catId) {
                    $ph = ":cat_{$idx}";
                    $placeholders[] = $ph;
                    $params[$ph] = (int) $catId;
                }
                $conditions[] = 'p.category_id IN (' . implode(',', $placeholders) . ')';
            } else {
                $conditions[] = 'p.category_id = :category_id';
                $params[':category_id'] = (int) $filters['category_id'];
            }
        }

        // Seller filter
        if (!empty($filters['seller_id'])) {
            $conditions[] = 'p.seller_id = :seller_id';
            $params[':seller_id'] = (int) $filters['seller_id'];
        }

        // Keyword search
        if (!empty($filters['search'])) {
            $conditions[] = '(p.name LIKE :search_name OR p.description LIKE :search_desc OR p.sku LIKE :search_sku)';
            $searchWildcard = '%' . trim((string) $filters['search']) . '%';
            $params[':search_name'] = $searchWildcard;
            $params[':search_desc'] = $searchWildcard;
            $params[':search_sku']  = $searchWildcard;
        }

        // Price range
        if (isset($filters['min_price']) && is_numeric($filters['min_price'])) {
            $conditions[] = 'p.price >= :min_price';
            $params[':min_price'] = (float) $filters['min_price'];
        }
        if (isset($filters['max_price']) && is_numeric($filters['max_price'])) {
            $conditions[] = 'p.price <= :max_price';
            $params[':max_price'] = (float) $filters['max_price'];
        }

        // Flags
        if (!empty($filters['is_featured'])) {
            $conditions[] = 'p.is_featured = 1';
        }
        if (!empty($filters['is_trending'])) {
            $conditions[] = 'p.is_trending = 1';
        }
        if (!empty($filters['in_stock'])) {
            $conditions[] = 'p.stock_quantity > 0';
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Sorting mapping
        $sortOrder = match ($filters['sort'] ?? '') {
            'price_asc'  => 'p.price ASC, p.id DESC',
            'price_desc' => 'p.price DESC, p.id DESC',
            'newest'     => 'p.created_at DESC',
            'rating'     => 'p.rating_avg DESC, p.rating_count DESC',
            'popular'    => 'p.sales_count DESC, p.id DESC',
            'views'      => 'p.views_count DESC, p.id DESC',
            'name_asc'   => 'p.name ASC',
            default      => 'p.id DESC',
        };

        // Total count
        $countSql = "SELECT COUNT(*) FROM products p {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Product list with category, seller, and primary image
        $dataSql = "SELECT 
            p.*,
            c.name AS category_name,
            c.slug AS category_slug,
            sp.store_name,
            sp.store_slug,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC 
             LIMIT 1) AS primary_image,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC 
             LIMIT 1) AS image_path
        FROM products p
        JOIN categories c ON c.id = p.category_id
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        {$whereClause}
        ORDER BY {$sortOrder}
        LIMIT :limit OFFSET :offset";

        $dataStmt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        $products = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'products'    => $products,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve complete product aggregate by ID (including gallery images, variants, seller info).
     *
     * @param int $id Product primary key ID.
     * @return array<string, mixed>|null Product aggregate array or null if not found.
     */
    public function getProductById(int $id): ?array
    {
        $sql = 'SELECT 
            p.*,
            c.name AS category_name,
            c.slug AS category_slug,
            sp.store_name,
            sp.store_slug,
            sp.rating_avg AS seller_rating,
            sp.verified AS seller_verified,
            u.first_name AS seller_first_name,
            u.last_name AS seller_last_name
        FROM products p
        JOIN categories c ON c.id = p.category_id
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        JOIN users u ON u.id = p.seller_id
        WHERE p.id = :id
        LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return null;
        }

        $product['images'] = $this->getProductImages($id);
        $product['variants'] = $this->getProductVariants($id);
        $product['image_path'] = !empty($product['images'][0]['image_path']) ? $product['images'][0]['image_path'] : null;
        $product['primary_image'] = $product['image_path'];

        return $product;
    }

    /**
     * Retrieve complete product aggregate by URL slug.
     *
     * @param string $slug Product slug.
     * @return array<string, mixed>|null Product aggregate array or null if not found.
     */
    public function getProductBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            return null;
        }

        return $this->getProductById((int) $id);
    }

    /**
     * Create a new product entry under a seller with status 'pending'.
     *
     * @param int $sellerId Owner seller user ID.
     * @param array<string, mixed> $data Product attributes.
     * @return int Created product ID.
     * @throws InvalidArgumentException|RuntimeException On validation or execution errors.
     */
    public function createProduct(int $sellerId, array $data): int
    {
        $name        = trim((string) ($data['name'] ?? ''));
        $categoryId  = (int) ($data['category_id'] ?? 0);
        $price       = (float) ($data['price'] ?? 0.0);
        $description = isset($data['description']) ? (string) $data['description'] : null;
        $shortDesc   = isset($data['short_description']) ? trim((string) $data['short_description']) : null;
        $comparePrice= isset($data['compare_price']) && is_numeric($data['compare_price']) ? (float) $data['compare_price'] : null;
        $costPrice   = isset($data['cost_price']) && is_numeric($data['cost_price']) ? (float) $data['cost_price'] : null;
        $sku         = !empty($data['sku']) ? trim((string) $data['sku']) : null;
        $barcode     = !empty($data['barcode']) ? trim((string) $data['barcode']) : null;
        $stockQty    = max(0, (int) ($data['stock_quantity'] ?? 0));
        $lowStock    = max(0, (int) ($data['low_stock_threshold'] ?? 5));
        $weight      = isset($data['weight']) && is_numeric($data['weight']) ? (float) $data['weight'] : null;
        $dimensions  = !empty($data['dimensions']) ? trim((string) $data['dimensions']) : null;
        $isFeatured  = !empty($data['is_featured']) ? 1 : 0;
        $isTrending  = !empty($data['is_trending']) ? 1 : 0;

        if ($name === '') {
            throw new InvalidArgumentException('Product title/name is required.');
        }

        if ($categoryId <= 0) {
            throw new InvalidArgumentException('A valid category must be selected.');
        }

        if ($price < 0.00) {
            throw new InvalidArgumentException('Price must be greater than or equal to 0.');
        }

        // Generate and ensure unique slug
        $baseSlug = $this->generateSlug($name);
        if ($baseSlug === '') {
            $baseSlug = 'product-' . bin2hex(random_bytes(4));
        }

        $slug = $baseSlug;
        $slugStmt = $this->pdo->prepare('SELECT id FROM products WHERE slug = :slug LIMIT 1');
        $slugStmt->execute([':slug' => $slug]);
        if ($slugStmt->fetch()) {
            $slug = $baseSlug . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        // Generate SKU if omitted
        if ($sku === null) {
            $sku = 'SKU-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $this->pdo->beginTransaction();

        try {
            $insertSql = 'INSERT INTO products (
                seller_id, category_id, name, slug, description, short_description,
                price, compare_price, cost_price, sku, barcode, stock_quantity,
                low_stock_threshold, weight, dimensions, status, is_featured, is_trending,
                views_count, sales_count, rating_avg, rating_count, created_at, updated_at
            ) VALUES (
                :seller_id, :category_id, :name, :slug, :description, :short_description,
                :price, :compare_price, :cost_price, :sku, :barcode, :stock_quantity,
                :low_stock_threshold, :weight, :dimensions, "pending", :is_featured, :is_trending,
                0, 0, 0.00, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )';

            $stmt = $this->pdo->prepare($insertSql);
            $stmt->execute([
                ':seller_id'           => $sellerId,
                ':category_id'         => $categoryId,
                ':name'                => $name,
                ':slug'                => $slug,
                ':description'         => $description,
                ':short_description'   => $shortDesc,
                ':price'               => $price,
                ':compare_price'       => $comparePrice,
                ':cost_price'          => $costPrice,
                ':sku'                 => $sku,
                ':barcode'             => $barcode,
                ':stock_quantity'      => $stockQty,
                ':low_stock_threshold' => $lowStock,
                ':weight'              => $weight,
                ':dimensions'          => $dimensions,
                ':is_featured'         => $isFeatured,
                ':is_trending'         => $isTrending,
            ]);

            $productId = (int) $this->pdo->lastInsertId();

            // Handle optional initial gallery images
            if (!empty($data['images']) && is_array($data['images'])) {
                foreach ($data['images'] as $idx => $img) {
                    $imgPath = is_array($img) ? ($img['image_path'] ?? '') : (string) $img;
                    if ($imgPath !== '') {
                        $isPrimary = $idx === 0 ? 1 : (is_array($img) && !empty($img['is_primary']) ? 1 : 0);
                        $alt = is_array($img) ? ($img['alt_text'] ?? null) : null;
                        $this->addProductImage($productId, $imgPath, (bool) $isPrimary, $alt, $idx);
                    }
                }
            }

            // Handle optional initial variants
            if (!empty($data['variants']) && is_array($data['variants'])) {
                $variantSql = 'INSERT INTO product_variants (
                    product_id, name, value, sku_suffix, price_adjustment, stock_quantity, sort_order, is_active, created_at, updated_at
                ) VALUES (
                    :product_id, :name, :value, :sku_suffix, :price_adjustment, :stock_quantity, :sort_order, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )';
                $variantStmt = $this->pdo->prepare($variantSql);

                foreach ($data['variants'] as $vIdx => $v) {
                    if (empty($v['name']) || empty($v['value'])) {
                        continue;
                    }
                    $variantStmt->execute([
                        ':product_id'       => $productId,
                        ':name'             => trim((string) $v['name']),
                        ':value'            => trim((string) $v['value']),
                        ':sku_suffix'       => !empty($v['sku_suffix']) ? trim((string) $v['sku_suffix']) : null,
                        ':price_adjustment' => (float) ($v['price_adjustment'] ?? 0.0),
                        ':stock_quantity'   => max(0, (int) ($v['stock_quantity'] ?? 0)),
                        ':sort_order'       => (int) ($v['sort_order'] ?? $vIdx),
                    ]);
                }
            }

            $this->pdo->commit();
            return $productId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[ProductService::createProduct Error] ' . $e->getMessage());
            throw new RuntimeException('Failed to create product: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update product details with vendor authorization check.
     *
     * @param int $id Product primary key ID.
     * @param int|null $sellerId Owner seller ID (or null for admin edits).
     * @param array<string, mixed> $data Updated attributes.
     * @return bool True on success, false if product not found or unauthorized.
     */
    public function updateProduct(int $id, ?int $sellerId, array $data): bool
    {
        // Check ownership
        $checkSql = 'SELECT * FROM products WHERE id = :id' . ($sellerId !== null ? ' AND seller_id = :seller_id' : '') . ' LIMIT 1';
        $checkStmt = $this->pdo->prepare($checkSql);
        $checkParams = [':id' => $id];
        if ($sellerId !== null) {
            $checkParams[':seller_id'] = $sellerId;
        }
        $checkStmt->execute($checkParams);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name !== '') {
                $fields[] = 'name = :name';
                $params[':name'] = $name;
            }
        }

        if (isset($data['category_id']) && (int) $data['category_id'] > 0) {
            $fields[] = 'category_id = :category_id';
            $params[':category_id'] = (int) $data['category_id'];
        }

        if (isset($data['description'])) {
            $fields[] = 'description = :description';
            $params[':description'] = (string) $data['description'];
        }

        if (isset($data['short_description'])) {
            $fields[] = 'short_description = :short_description';
            $params[':short_description'] = trim((string) $data['short_description']);
        }

        if (isset($data['price']) && is_numeric($data['price'])) {
            $fields[] = 'price = :price';
            $params[':price'] = max(0.00, (float) $data['price']);
        }

        if (array_key_exists('compare_price', $data)) {
            $fields[] = 'compare_price = :compare_price';
            $params[':compare_price'] = is_numeric($data['compare_price']) ? (float) $data['compare_price'] : null;
        }

        if (array_key_exists('cost_price', $data)) {
            $fields[] = 'cost_price = :cost_price';
            $params[':cost_price'] = is_numeric($data['cost_price']) ? (float) $data['cost_price'] : null;
        }

        if (isset($data['sku']) && trim((string) $data['sku']) !== '') {
            $fields[] = 'sku = :sku';
            $params[':sku'] = trim((string) $data['sku']);
        }

        if (array_key_exists('barcode', $data)) {
            $fields[] = 'barcode = :barcode';
            $params[':barcode'] = !empty($data['barcode']) ? trim((string) $data['barcode']) : null;
        }

        if (isset($data['stock_quantity']) && is_numeric($data['stock_quantity'])) {
            $fields[] = 'stock_quantity = :stock_quantity';
            $params[':stock_quantity'] = max(0, (int) $data['stock_quantity']);
        }

        if (isset($data['low_stock_threshold']) && is_numeric($data['low_stock_threshold'])) {
            $fields[] = 'low_stock_threshold = :low_stock_threshold';
            $params[':low_stock_threshold'] = max(0, (int) $data['low_stock_threshold']);
        }

        if (array_key_exists('weight', $data)) {
            $fields[] = 'weight = :weight';
            $params[':weight'] = is_numeric($data['weight']) ? (float) $data['weight'] : null;
        }

        if (array_key_exists('dimensions', $data)) {
            $fields[] = 'dimensions = :dimensions';
            $params[':dimensions'] = !empty($data['dimensions']) ? trim((string) $data['dimensions']) : null;
        }

        if (isset($data['is_featured'])) {
            $fields[] = 'is_featured = :is_featured';
            $params[':is_featured'] = !empty($data['is_featured']) ? 1 : 0;
        }

        if (isset($data['is_trending'])) {
            $fields[] = 'is_trending = :is_trending';
            $params[':is_trending'] = !empty($data['is_trending']) ? 1 : 0;
        }

        // If updated by seller after rejection, reset status to pending for re-moderation
        if ($sellerId !== null && $existing['status'] === 'rejected') {
            $fields[] = "status = 'pending'";
            $fields[] = 'rejection_reason = NULL';
        } elseif (isset($data['status']) && in_array($data['status'], ['draft', 'pending', 'approved', 'rejected', 'archived'], true)) {
            $fields[] = 'status = :status';
            $params[':status'] = $data['status'];
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';
        $updateSql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($updateSql);
        return $stmt->execute($params);
    }

    /**
     * Soft delete/archive a product.
     *
     * @param int $id Product primary key ID.
     * @param int|null $sellerId Owner seller ID (or null for admin).
     * @return bool True if archived, false otherwise.
     */
    public function deleteProduct(int $id, ?int $sellerId = null): bool
    {
        $sql = "UPDATE products SET status = 'archived', updated_at = UTC_TIMESTAMP() WHERE id = :id"
             . ($sellerId !== null ? ' AND seller_id = :seller_id' : '');

        $params = [':id' => $id];
        if ($sellerId !== null) {
            $params[':seller_id'] = $sellerId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Fetch all images associated with a product.
     *
     * @param int $productId Target product ID.
     * @return array<int, array<string, mixed>> Ordered image records.
     */
    public function getProductImages(int $productId): array
    {
        $sql = 'SELECT * FROM product_images 
            WHERE product_id = :product_id 
            ORDER BY is_primary DESC, sort_order ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Add an image to a product's gallery.
     *
     * @param int $productId Target product ID.
     * @param string $imagePath Relative or URL path to image file.
     * @param bool $isPrimary Whether to designate this image as primary thumbnail.
     * @param string|null $altText Optional descriptive alt text for accessibility.
     * @param int $sortOrder Display sort index.
     * @return int Created image ID.
     */
    public function addProductImage(int $productId, string $imagePath, bool $isPrimary = false, ?string $altText = null, int $sortOrder = 0): int
    {
        $imagePath = trim($imagePath);

        // Check if product already has any images; if not, force this one as primary
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = :product_id');
        $stmt->execute([':product_id' => $productId]);
        $existingCount = (int) $stmt->fetchColumn();
        if ($existingCount === 0) {
            $isPrimary = true;
        }

        if ($isPrimary) {
            $resetStmt = $this->pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = :product_id');
            $resetStmt->execute([':product_id' => $productId]);
        }

        $insertSql = 'INSERT INTO product_images (
            product_id, image_path, alt_text, sort_order, is_primary, created_at
        ) VALUES (
            :product_id, :image_path, :alt_text, :sort_order, :is_primary, UTC_TIMESTAMP()
        )';

        $stmt = $this->pdo->prepare($insertSql);
        $stmt->execute([
            ':product_id' => $productId,
            ':image_path' => $imagePath,
            ':alt_text'   => $altText,
            ':sort_order' => $sortOrder,
            ':is_primary' => $isPrimary ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Delete an image with seller ownership check, promoting a replacement primary if necessary.
     *
     * @param int $imageId Target image primary key ID.
     * @param int|null $sellerId Owner seller ID (null for admin).
     * @return bool True on success, false if not found or unauthorized.
     */
    public function deleteProductImage(int $imageId, ?int $sellerId = null): bool
    {
        $sql = 'SELECT pi.*, p.seller_id 
            FROM product_images pi 
            JOIN products p ON p.id = pi.product_id 
            WHERE pi.id = :id 
            LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $imageId]);
        $image = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$image) {
            return false;
        }

        if ($sellerId !== null && (int) $image['seller_id'] !== $sellerId) {
            return false;
        }

        $productId = (int) $image['product_id'];
        $wasPrimary = (bool) $image['is_primary'];

        $delStmt = $this->pdo->prepare('DELETE FROM product_images WHERE id = :id');
        $delStmt->execute([':id' => $imageId]);

        // If the deleted image was primary, elevate the first remaining image
        if ($wasPrimary) {
            $nextStmt = $this->pdo->prepare('SELECT id FROM product_images WHERE product_id = :product_id ORDER BY sort_order ASC, id ASC LIMIT 1');
            $nextStmt->execute([':product_id' => $productId]);
            $nextId = $nextStmt->fetchColumn();

            if ($nextId) {
                $promoteStmt = $this->pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id');
                $promoteStmt->execute([':id' => $nextId]);
            }
        }

        return true;
    }

    /**
     * Fetch variants for a product.
     *
     * @param int $productId Target product ID.
     * @param bool $activeOnly Whether to filter by is_active = 1.
     * @return array<int, array<string, mixed>> List of variant records.
     */
    public function getProductVariants(int $productId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM product_variants WHERE product_id = :product_id'
             . ($activeOnly ? ' AND is_active = 1' : '')
             . ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retrieve featured approved products for homepage showcase.
     *
     * @param int $limit Maximum items to return.
     * @return array<int, array<string, mixed>> Product list.
     */
    public function getFeaturedProducts(int $limit = 8): array
    {
        $res = $this->getProducts([
            'status'      => 'approved',
            'is_featured' => true,
            'sort'        => 'popular',
        ], 1, $limit);

        return $res['products'];
    }

    /**
     * Retrieve trending approved products based on view and sales velocity.
     *
     * @param int $limit Maximum items to return.
     * @return array<int, array<string, mixed>> Product list.
     */
    public function getTrendingProducts(int $limit = 8): array
    {
        $res = $this->getProducts([
            'status'      => 'approved',
            'is_trending' => true,
            'sort'        => 'views',
        ], 1, $limit);

        return $res['products'];
    }

    /**
     * Retrieve related products from the same category.
     *
     * @param int $productId Current product ID to exclude.
     * @param int $limit Maximum related products to return.
     * @return array<int, array<string, mixed>> Related products.
     */
    public function getRelatedProducts(int $productId, int $limit = 4): array
    {
        $stmt = $this->pdo->prepare('SELECT category_id FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        $categoryId = $stmt->fetchColumn();

        if (!$categoryId) {
            return [];
        }

        $sql = "SELECT 
            p.*,
            c.name AS category_name,
            c.slug AS category_slug,
            sp.store_name,
            sp.store_slug,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS image_path
        FROM products p
        JOIN categories c ON c.id = p.category_id
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        WHERE p.category_id = :category_id 
          AND p.id != :product_id 
          AND p.status = 'approved'
        ORDER BY p.sales_count DESC, p.id DESC
        LIMIT :limit";

        $stmtRel = $this->pdo->prepare($sql);
        $stmtRel->bindValue(':category_id', (int) $categoryId, PDO::PARAM_INT);
        $stmtRel->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmtRel->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtRel->execute();

        return $stmtRel->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retrieve hot deals where compare_price exceeds selling price.
     *
     * @param int $limit Maximum items to return.
     * @return array<int, array<string, mixed>> Discounted products.
     */
    public function getDealProducts(int $limit = 8): array
    {
        $sql = "SELECT 
            p.*,
            c.name AS category_name,
            c.slug AS category_slug,
            sp.store_name,
            sp.store_slug,
            ROUND(((p.compare_price - p.price) / p.compare_price) * 100) AS discount_percentage,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS image_path
        FROM products p
        JOIN categories c ON c.id = p.category_id
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        WHERE p.status = 'approved' 
          AND p.compare_price IS NOT NULL 
          AND p.compare_price > p.price
        ORDER BY discount_percentage DESC, p.id DESC
        LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Increment page view count for product traffic analytics.
     *
     * @param int $productId Target product ID.
     * @return void
     */
    public function incrementViews(int $productId): void
    {
        $stmt = $this->pdo->prepare('UPDATE products SET views_count = views_count + 1 WHERE id = :id');
        $stmt->execute([':id' => $productId]);
    }

    /**
     * Fetch active categories, optionally filtered by parent ID or flat listing.
     *
     * @param int|null|false $parentId Specific parent category ID, null for top-level, false for all.
     * @return array<int, array<string, mixed>> Categories list.
     */
    public function getCategories(int|null|false $parentId = false): array
    {
        if ($parentId === false) {
            $sql = 'SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } elseif ($parentId === null) {
            $sql = 'SELECT * FROM categories WHERE is_active = 1 AND parent_id IS NULL ORDER BY sort_order ASC, name ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = 'SELECT * FROM categories WHERE is_active = 1 AND parent_id = :parent_id ORDER BY sort_order ASC, name ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':parent_id' => $parentId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Fetch single category by unique slug.
     *
     * @param string $slug Category URL slug.
     * @return array<string, mixed>|null Category record or null if not found.
     */
    public function getCategoryBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = :slug AND is_active = 1 LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Quick search query for live frontend autocomplete.
     *
     * @param string $query Search keyword.
     * @param int $limit Maximum results.
     * @return array<int, array<string, mixed>> Lightweight search matches.
     */
    public function searchProducts(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $wildcard = '%' . $query . '%';
        $sql = "SELECT 
            p.id,
            p.name,
            p.slug,
            p.price,
            p.compare_price,
            c.name AS category_name,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.status = 'approved'
          AND (p.name LIKE :q1 OR p.sku LIKE :q2 OR c.name LIKE :q3)
        ORDER BY p.sales_count DESC, p.views_count DESC
        LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':q1', $wildcard);
        $stmt->bindValue(':q2', $wildcard);
        $stmt->bindValue(':q3', $wildcard);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Helper to generate a URL-safe slug.
     *
     * @param string $text Text to slugify.
     * @return string Normalized slug.
     */
    protected function generateSlug(string $text): string
    {
        $slug = preg_replace('~[^\pL\d]+~u', '-', $text);
        $slug = iconv('utf-8', 'us-ascii//TRANSLIT', (string) $slug);
        $slug = preg_replace('~[^-\w]+~', '', (string) $slug);
        $slug = trim((string) $slug, '-');
        $slug = preg_replace('~-+~', '-', (string) $slug);
        return strtolower((string) $slug);
    }
}
