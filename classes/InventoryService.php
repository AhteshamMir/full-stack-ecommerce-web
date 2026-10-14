<?php
/**
 * ShopSphere - Inventory & Stock Movement Service
 *
 * Provides transactional inventory deductions, replenishments, manual adjustments,
 * pessimistic locking (SELECT FOR UPDATE), audit trails, and low-stock alerts.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class InventoryService
{
    /**
     * Database connection instance.
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Constructor accepts optional PDO instance.
     *
     * @param PDO|null $pdo Optional PDO instance.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * Get current available stock for a product or variant.
     *
     * @param int $productId Target product ID.
     * @param int|null $variantId Optional variant ID.
     * @return int Units available.
     */
    public function getStock(int $productId, ?int $variantId = null): int
    {
        if ($variantId !== null) {
            $stmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id AND product_id = :product_id LIMIT 1');
            $stmt->execute([':id' => $variantId, ':product_id' => $productId]);
            $stock = $stmt->fetchColumn();
            return $stock !== false ? (int) $stock : 0;
        }

        $stmt = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        $stock = $stmt->fetchColumn();
        return $stock !== false ? (int) $stock : 0;
    }

    /**
     * Atomically decrement stock with pessimistic FOR UPDATE locking and audit record.
     *
     * @param int $productId Target product ID.
     * @param int|null $variantId Optional variant ID.
     * @param int $quantity Units to decrement.
     * @param string $referenceType Source entity type (e.g. 'order', 'reservation').
     * @param int|null $referenceId Source entity ID (e.g. order_id).
     * @param string|null $reason Audit description.
     * @param int|null $userId User initiating action.
     * @return int Remaining stock count.
     * @throws RuntimeException If stock is insufficient or lock cannot be acquired.
     */
    public function decrementStock(
        int $productId,
        ?int $variantId,
        int $quantity,
        string $referenceType,
        ?int $referenceId = null,
        ?string $reason = null,
        ?int $userId = null
    ): int {
        if ($quantity <= 0) {
            return $this->getStock($productId, $variantId);
        }

        $ownsTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $remainingStock = 0;

            if ($variantId !== null) {
                // Lock variant row
                $vStmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
                $vStmt->execute([':id' => $variantId, ':product_id' => $productId]);
                $vStock = $vStmt->fetchColumn();

                if ($vStock === false) {
                    throw new RuntimeException("Variant ID {$variantId} does not exist for product {$productId}.");
                }

                $currentVStock = (int) $vStock;
                if ($currentVStock < $quantity) {
                    throw new RuntimeException("Insufficient stock for variant (requested {$quantity}, available {$currentVStock}).");
                }

                // Decrement variant
                $updV = $this->pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - :qty, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                $updV->execute([':qty' => $quantity, ':id' => $variantId]);
                $remainingStock = $currentVStock - $quantity;

                // Also decrement parent product stock & bump sales_count
                $updP = $this->pdo->prepare('UPDATE products SET 
                    stock_quantity = GREATEST(0, stock_quantity - :qty_stock), 
                    sales_count = sales_count + :qty_sales, 
                    updated_at = UTC_TIMESTAMP() 
                    WHERE id = :id');
                $updP->execute([':qty_stock' => $quantity, ':qty_sales' => $quantity, ':id' => $productId]);
            } else {
                // Lock product row
                $pStmt = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE');
                $pStmt->execute([':id' => $productId]);
                $pStock = $pStmt->fetchColumn();

                if ($pStock === false) {
                    throw new RuntimeException("Product ID {$productId} not found.");
                }

                $currentPStock = (int) $pStock;
                if ($currentPStock < $quantity) {
                    throw new RuntimeException("Insufficient stock for product {$productId} (requested {$quantity}, available {$currentPStock}).");
                }

                $updP = $this->pdo->prepare('UPDATE products SET 
                    stock_quantity = stock_quantity - :qty_stock, 
                    sales_count = sales_count + :qty_sales, 
                    updated_at = UTC_TIMESTAMP() 
                    WHERE id = :id');
                $updP->execute([':qty_stock' => $quantity, ':qty_sales' => $quantity, ':id' => $productId]);
                $remainingStock = $currentPStock - $quantity;
            }

            // Record inventory movement
            $movementType = ($referenceType === 'order') ? 'sale' : 'adjustment';
            $insMv = $this->pdo->prepare('INSERT INTO inventory_movements (
                product_id, variant_id, quantity_change, type, reference_type, reference_id, reason, created_by, created_at
            ) VALUES (
                :product_id, :variant_id, :quantity_change, :type, :reference_type, :reference_id, :reason, :created_by, UTC_TIMESTAMP()
            )');
            $insMv->execute([
                ':product_id'      => $productId,
                ':variant_id'      => $variantId,
                ':quantity_change' => -$quantity,
                ':type'            => $movementType,
                ':reference_type'  => $referenceType,
                ':reference_id'    => $referenceId,
                ':reason'          => $reason ?? "Stock deduction for {$referenceType} #{$referenceId}",
                ':created_by'      => $userId,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $remainingStock;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atomically increment inventory (e.g. on order cancellation, returns, or restocking).
     *
     * @param int $productId Target product ID.
     * @param int|null $variantId Optional variant ID.
     * @param int $quantity Units to restore.
     * @param string $referenceType Source entity type (e.g. 'order_cancellation', 'return').
     * @param int|null $referenceId Source entity ID.
     * @param string|null $reason Explanation.
     * @param int|null $userId User initiating restoration.
     * @return int New total stock.
     */
    public function incrementStock(
        int $productId,
        ?int $variantId,
        int $quantity,
        string $referenceType,
        ?int $referenceId = null,
        ?string $reason = null,
        ?int $userId = null
    ): int {
        if ($quantity <= 0) {
            return $this->getStock($productId, $variantId);
        }

        $ownsTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $newStock = 0;

            if ($variantId !== null) {
                $updV = $this->pdo->prepare('UPDATE product_variants SET 
                    stock_quantity = stock_quantity + :qty, 
                    updated_at = UTC_TIMESTAMP() 
                    WHERE id = :id AND product_id = :product_id');
                $updV->execute([':qty' => $quantity, ':id' => $variantId, ':product_id' => $productId]);

                $updP = $this->pdo->prepare('UPDATE products SET 
                    stock_quantity = stock_quantity + :qty_stock, 
                    sales_count = GREATEST(0, sales_count - :qty_sales), 
                    updated_at = UTC_TIMESTAMP() 
                    WHERE id = :id');
                $updP->execute([':qty_stock' => $quantity, ':qty_sales' => $quantity, ':id' => $productId]);

                $vStmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id');
                $vStmt->execute([':id' => $variantId]);
                $newStock = (int) $vStmt->fetchColumn();
            } else {
                $updP = $this->pdo->prepare('UPDATE products SET 
                    stock_quantity = stock_quantity + :qty_stock, 
                    sales_count = GREATEST(0, sales_count - :qty_sales), 
                    updated_at = UTC_TIMESTAMP() 
                    WHERE id = :id');
                $updP->execute([':qty_stock' => $quantity, ':qty_sales' => $quantity, ':id' => $productId]);

                $pStmt = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id');
                $pStmt->execute([':id' => $productId]);
                $newStock = (int) $pStmt->fetchColumn();
            }

            $movementType = match ($referenceType) {
                'return', 'return_request' => 'return',
                'purchase', 'restock'      => 'purchase',
                default                    => 'adjustment',
            };

            $insMv = $this->pdo->prepare('INSERT INTO inventory_movements (
                product_id, variant_id, quantity_change, type, reference_type, reference_id, reason, created_by, created_at
            ) VALUES (
                :product_id, :variant_id, :quantity_change, :type, :reference_type, :reference_id, :reason, :created_by, UTC_TIMESTAMP()
            )');
            $insMv->execute([
                ':product_id'      => $productId,
                ':variant_id'      => $variantId,
                ':quantity_change' => $quantity,
                ':type'            => $movementType,
                ':reference_type'  => $referenceType,
                ':reference_id'    => $referenceId,
                ':reason'          => $reason ?? "Stock increment from {$referenceType} #{$referenceId}",
                ':created_by'      => $userId,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $newStock;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Perform a manual stock quantity adjustment with audit logging.
     *
     * @param int $productId Target product ID.
     * @param int|null $variantId Target variant ID (optional).
     * @param int $newQuantity Target absolute new stock quantity.
     * @param string $reason Audit explanation.
     * @param int|null $userId Admin or vendor user ID performing adjustment.
     * @return array{old_quantity: int, new_quantity: int, difference: int}
     */
    public function adjustStock(int $productId, ?int $variantId, int $newQuantity, string $reason, ?int $userId = null): array
    {
        $newQuantity = max(0, $newQuantity);

        $this->pdo->beginTransaction();
        try {
            $oldQuantity = 0;

            if ($variantId !== null) {
                $vStmt = $this->pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
                $vStmt->execute([':id' => $variantId, ':product_id' => $productId]);
                $oldQuantity = (int) $vStmt->fetchColumn();

                $diff = $newQuantity - $oldQuantity;

                $updV = $this->pdo->prepare('UPDATE product_variants SET stock_quantity = :qty, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                $updV->execute([':qty' => $newQuantity, ':id' => $variantId]);

                // Also sync difference to parent product
                if ($diff !== 0) {
                    $updP = $this->pdo->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + :diff), updated_at = UTC_TIMESTAMP() WHERE id = :id');
                    $updP->execute([':diff' => $diff, ':id' => $productId]);
                }
            } else {
                $pStmt = $this->pdo->prepare('SELECT stock_quantity FROM products WHERE id = :id FOR UPDATE');
                $pStmt->execute([':id' => $productId]);
                $oldQuantity = (int) $pStmt->fetchColumn();

                $diff = $newQuantity - $oldQuantity;

                $updP = $this->pdo->prepare('UPDATE products SET stock_quantity = :qty, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                $updP->execute([':qty' => $newQuantity, ':id' => $productId]);
            }

            if ($diff !== 0) {
                $mvStmt = $this->pdo->prepare('INSERT INTO inventory_movements (
                    product_id, variant_id, quantity_change, type, reference_type, reference_id, reason, created_by, created_at
                ) VALUES (
                    :product_id, :variant_id, :diff, "adjustment", "manual_adjustment", NULL, :reason, :created_by, UTC_TIMESTAMP()
                )');
                $mvStmt->execute([
                    ':product_id' => $productId,
                    ':variant_id' => $variantId,
                    ':diff'       => $diff,
                    ':reason'     => $reason,
                    ':created_by' => $userId,
                ]);
            }

            $this->pdo->commit();

            return [
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'difference'   => $diff,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[InventoryService::adjustStock Error] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve audit history of inventory movements for a product.
     *
     * @param int $productId Target product ID.
     * @param int $page Current page number.
     * @param int $perPage Movements per page.
     * @return array{movements: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function getMovements(int $productId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id = :product_id');
        $countStmt->execute([':product_id' => $productId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT 
            im.*,
            pv.name AS variant_name,
            pv.value AS variant_value,
            u.first_name,
            u.last_name,
            u.email AS user_email
        FROM inventory_movements im
        LEFT JOIN product_variants pv ON pv.id = im.variant_id
        LEFT JOIN users u ON u.id = im.created_by
        WHERE im.product_id = :product_id
        ORDER BY im.id DESC
        LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'movements' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
        ];
    }

    /**
     * Retrieve products that have fallen below their configured low-stock alert threshold.
     *
     * @param int|null $sellerId Optional vendor ID filter.
     * @param int|null $threshold Optional fixed threshold override.
     * @return array<int, array<string, mixed>> Low stock product list.
     */
    public function getLowStockProducts(?int $sellerId = null, ?int $threshold = null): array
    {
        $conditions = ["p.status = 'approved'"];
        $params = [];

        if ($threshold !== null) {
            $conditions[] = 'p.stock_quantity <= :threshold';
            $params[':threshold'] = max(0, $threshold);
        } else {
            $conditions[] = 'p.stock_quantity <= p.low_stock_threshold';
        }

        if ($sellerId !== null) {
            $conditions[] = 'p.seller_id = :seller_id';
            $params[':seller_id'] = $sellerId;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $sql = "SELECT 
            p.id,
            p.seller_id,
            p.name,
            p.sku,
            p.price,
            p.stock_quantity,
            p.low_stock_threshold,
            sp.store_name,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image
        FROM products p
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        {$where}
        ORDER BY p.stock_quantity ASC, p.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retrieve vendor catalog inventory list with variant counts and stock metrics.
     *
     * @param int $sellerId Vendor user ID.
     * @param int $page Page number.
     * @param int $perPage Items per page.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function getSellerInventory(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE seller_id = :seller_id');
        $countStmt->execute([':seller_id' => $sellerId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT 
            p.id,
            p.name,
            p.sku,
            p.price,
            p.stock_quantity,
            p.low_stock_threshold,
            p.status,
            (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.id) AS variants_count,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image,
            CASE 
                WHEN p.stock_quantity <= 0 THEN 'out_of_stock'
                WHEN p.stock_quantity <= p.low_stock_threshold THEN 'low_stock'
                ELSE 'in_stock'
            END AS inventory_status
        FROM products p
        WHERE p.seller_id = :seller_id
        ORDER BY p.stock_quantity ASC, p.id DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items'    => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
