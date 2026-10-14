<?php
/**
 * ShopSphere - Master & Multi-Vendor Order Management Service
 *
 * Implements end-to-end transactional checkout fulfillment, vendor order slicing,
 * price/commission snapshotting, inventory locking, ledger adjustments, and audit trails.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CartService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/CommissionService.php';
require_once __DIR__ . '/CouponService.php';
require_once __DIR__ . '/PaymentService.php';
require_once __DIR__ . '/NotificationService.php';

class OrderService
{
    /**
     * Database connection instance.
     *
     * @var PDO
     */
    protected PDO $pdo;

    protected CartService $cartService;
    protected InventoryService $inventoryService;
    protected CommissionService $commissionService;
    protected CouponService $couponService;
    protected PaymentService $paymentService;
    protected NotificationService $notificationService;

    /**
     * Constructor accepts optional service dependencies with sensible defaults.
     *
     * @param PDO|null $pdo Optional PDO instance.
     * @param CartService|null $cartService Optional CartService.
     * @param InventoryService|null $inventoryService Optional InventoryService.
     * @param CommissionService|null $commissionService Optional CommissionService.
     * @param CouponService|null $couponService Optional CouponService.
     * @param PaymentService|null $paymentService Optional PaymentService.
     * @param NotificationService|null $notificationService Optional NotificationService.
     */
    public function __construct(
        ?PDO $pdo = null,
        ?CartService $cartService = null,
        ?InventoryService $inventoryService = null,
        ?CommissionService $commissionService = null,
        ?CouponService $couponService = null,
        ?PaymentService $paymentService = null,
        ?NotificationService $notificationService = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->couponService = $couponService ?? new CouponService($this->pdo);
        $this->cartService = $cartService ?? new CartService($this->pdo, $this->couponService);
        $this->inventoryService = $inventoryService ?? new InventoryService($this->pdo);
        $this->commissionService = $commissionService ?? new CommissionService($this->pdo);
        $this->paymentService = $paymentService ?? new PaymentService($this->pdo);
        $this->notificationService = $notificationService ?? new NotificationService($this->pdo);
    }

    /**
     * Complete transactional order creation process.
     *
     * Sequence:
     * 1. Check idempotency key
     * 2. Begin transaction
     * 3. Validate cart items and lock inventory with SELECT FOR UPDATE
     * 4. Calculate final totals and coupon discounts
     * 5. Snapshot shipping & billing addresses
     * 6. Generate order number and persist master order record
     * 7. Group items by vendor, compute commission, and create seller_orders
     * 8. Persist immutable order_items price & commission snapshots
     * 9. Atomically decrement inventory with audit movement records
     * 10. Record coupon redemption
     * 11. Create payment transaction record
     * 12. Record initial status in order_status_history
     * 13. Dispatch notifications to customer and vendors
     * 14. Clear shopping cart
     * 15. Commit transaction
     *
     * @param int $userId Customer user primary key ID.
     * @param int $addressId Shipping & billing address ID.
     * @param int $cartId Shopping cart ID.
     * @param string $paymentMethod Payment channel ('card', 'jazzcash', 'easypaisa', 'bank_transfer', 'cod').
     * @param string|null $notes Optional delivery instructions.
     * @param string|null $idempotencyKey Optional idempotency key to prevent double submits.
     * @return int Created master order ID.
     * @throws RuntimeException|InvalidArgumentException On validation or transactional failure.
     */
    public function createOrder(
        int $userId,
        int $addressId,
        int $cartId,
        string $paymentMethod,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): int {
        // 1. Idempotency verification
        if (!empty($idempotencyKey)) {
            $idempotencyStmt = $this->pdo->prepare('SELECT id FROM orders WHERE idempotency_key = :key LIMIT 1');
            $idempotencyStmt->execute([':key' => $idempotencyKey]);
            $existingOrderId = $idempotencyStmt->fetchColumn();
            if ($existingOrderId) {
                return (int) $existingOrderId;
            }
        }

        // 2. Begin transaction
        $this->pdo->beginTransaction();

        try {
            // Fetch and lock cart
            $cartStmt = $this->pdo->prepare('SELECT * FROM carts WHERE id = :id FOR UPDATE');
            $cartStmt->execute([':id' => $cartId]);
            $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cart) {
                throw new RuntimeException('Shopping cart not found.');
            }

            // Fetch cart line items
            $rawItemsStmt = $this->pdo->prepare('SELECT * FROM cart_items WHERE cart_id = :cart_id');
            $rawItemsStmt->execute([':cart_id' => $cartId]);
            $rawItems = $rawItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($rawItems)) {
                throw new RuntimeException('Shopping cart is empty.');
            }

            // 3. Validate items & lock stock with SELECT FOR UPDATE
            $validatedItems = [];
            $subtotal = 0.00;

            foreach ($rawItems as $rawItem) {
                $productId = (int) $rawItem['product_id'];
                $variantId = $rawItem['variant_id'] !== null ? (int) $rawItem['variant_id'] : null;
                $quantity  = (int) $rawItem['quantity'];

                // Pessimistic lock on product
                $pStmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id FOR UPDATE');
                $pStmt->execute([':id' => $productId]);
                $product = $pStmt->fetch(PDO::FETCH_ASSOC);

                if (!$product || $product['status'] !== 'approved') {
                    throw new RuntimeException("Product '{$product['name']}' is no longer available.");
                }

                $availableStock = (int) $product['stock_quantity'];
                $variantInfo = null;
                $priceAdjustment = 0.00;
                $sku = $product['sku'];

                if ($variantId !== null) {
                    // Pessimistic lock on variant
                    $vStmt = $this->pdo->prepare('SELECT * FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
                    $vStmt->execute([':id' => $variantId, ':product_id' => $productId]);
                    $variant = $vStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$variant || !(bool) $variant['is_active']) {
                        throw new RuntimeException("Selected variant for '{$product['name']}' is no longer available.");
                    }

                    $availableStock = (int) $variant['stock_quantity'];
                    $variantInfo    = "{$variant['name']}: {$variant['value']}";
                    $priceAdjustment = (float) $variant['price_adjustment'];
                    if (!empty($variant['sku_suffix'])) {
                        $sku = $sku ? "{$sku}-{$variant['sku_suffix']}" : $variant['sku_suffix'];
                    }
                }

                if ($quantity > $availableStock) {
                    throw new RuntimeException("Insufficient stock for '{$product['name']}' (available: {$availableStock}, requested: {$quantity}).");
                }

                $unitPrice = max(0.00, (float) $product['price'] + $priceAdjustment);
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $validatedItems[] = [
                    'product_id'       => $productId,
                    'variant_id'       => $variantId,
                    'seller_id'        => (int) $product['seller_id'],
                    'category_id'      => (int) $product['category_id'],
                    'product_name'     => (string) $product['name'],
                    'variant_info'     => $variantInfo,
                    'sku'              => $sku,
                    'quantity'         => $quantity,
                    'unit_price'       => $unitPrice,
                    'total_price'      => $lineTotal,
                    'price_adjustment' => $priceAdjustment,
                ];
            }

            $subtotal = round($subtotal, 2);

            // 4. Calculate coupon discount
            $couponId       = null;
            $couponCode     = !empty($cart['coupon_code']) ? (string) $cart['coupon_code'] : null;
            $couponDiscount = 0.00;

            if ($couponCode !== null) {
                $couponVal = $this->couponService->validateCoupon($couponCode, $userId, $subtotal, $validatedItems);
                if ($couponVal['valid'] && !empty($couponVal['coupon'])) {
                    $couponId = (int) $couponVal['coupon']['id'];
                    $couponDiscount = round((float) $couponVal['discount'], 2);
                } else {
                    // Invalid coupon: disregard
                    $couponCode = null;
                }
            }

            $shippingAmount = 0.00;
            $taxAmount      = 0.00;
            $totalAmount    = round(max(0.00, ($subtotal - $couponDiscount) + $shippingAmount + $taxAmount), 2);

            // 5. Snapshot user address
            $addrStmt = $this->pdo->prepare('SELECT * FROM addresses WHERE id = :id AND user_id = :user_id LIMIT 1');
            $addrStmt->execute([':id' => $addressId, ':user_id' => $userId]);
            $address = $addrStmt->fetch(PDO::FETCH_ASSOC);

            if (!$address) {
                throw new RuntimeException('Shipping address not found or does not belong to user.');
            }

            $addressSnapshot = json_encode([
                'full_name'      => $address['full_name'],
                'phone'          => $address['phone'],
                'address_line1'  => $address['address_line1'],
                'address_line2'  => $address['address_line2'],
                'city'           => $address['city'],
                'state'          => $address['state'],
                'postal_code'    => $address['postal_code'],
                'country'        => $address['country'],
            ], JSON_THROW_ON_ERROR);

            // 6. Generate order number
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

            // Insert master order record
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $insertOrderSql = 'INSERT INTO orders (
                order_number, user_id, address_id, shipping_address, billing_address,
                subtotal, discount_amount, shipping_amount, tax_amount, total_amount,
                coupon_id, coupon_code, coupon_discount, payment_method, payment_status,
                order_status, notes, ip_address, idempotency_key, created_at, updated_at
            ) VALUES (
                :order_number, :user_id, :address_id, :shipping_address, :billing_address,
                :subtotal, :discount_amount, :shipping_amount, :tax_amount, :total_amount,
                :coupon_id, :coupon_code, :coupon_discount, :payment_method, "pending",
                "pending", :notes, :ip_address, :idempotency_key, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )';

            $insertOrderStmt = $this->pdo->prepare($insertOrderSql);
            $insertOrderStmt->execute([
                ':order_number'     => $orderNumber,
                ':user_id'          => $userId,
                ':address_id'       => $addressId,
                ':shipping_address' => $addressSnapshot,
                ':billing_address'  => $addressSnapshot,
                ':subtotal'         => $subtotal,
                ':discount_amount'  => $couponDiscount,
                ':shipping_amount'  => $shippingAmount,
                ':tax_amount'       => $taxAmount,
                ':total_amount'     => $totalAmount,
                ':coupon_id'        => $couponId,
                ':coupon_code'      => $couponCode,
                ':coupon_discount'  => $couponDiscount,
                ':payment_method'   => $paymentMethod,
                ':notes'            => $notes,
                ':ip_address'       => $ipAddress,
                ':idempotency_key'  => $idempotencyKey,
            ]);

            $orderId = (int) $this->pdo->lastInsertId();

            // 7. Group items by vendor to create seller_orders
            $sellerGrouped = [];
            foreach ($validatedItems as $item) {
                $sId = $item['seller_id'];
                if (!isset($sellerGrouped[$sId])) {
                    $sellerGrouped[$sId] = [
                        'items'    => [],
                        'subtotal' => 0.00,
                    ];
                }
                $sellerGrouped[$sId]['items'][] = $item;
                $sellerGrouped[$sId]['subtotal'] += $item['total_price'];
            }

            $sellerOrderMap = []; // Maps seller_id => seller_order_id

            foreach ($sellerGrouped as $sellerId => $grp) {
                $sellerSubtotal = round($grp['subtotal'], 2);

                // Pro-rate coupon discount across sellers based on subtotal ratio
                $sellerDiscount = 0.00;
                if ($subtotal > 0.00 && $couponDiscount > 0.00) {
                    $sellerDiscount = round(($sellerSubtotal / $subtotal) * $couponDiscount, 2);
                }

                $sellerNetBasis = max(0.00, $sellerSubtotal - $sellerDiscount);

                // Compute commission for seller
                $commissionRate   = $this->commissionService->getCommissionRate($sellerId);
                $commissionAmount = $this->commissionService->calculateCommission($sellerNetBasis, $commissionRate);
                $sellerEarning    = round(max(0.00, $sellerNetBasis - $commissionAmount), 2);

                $insertSellerOrderSql = 'INSERT INTO seller_orders (
                    order_id, seller_id, subtotal, discount_amount, shipping_amount,
                    commission_amount, seller_earning, status, created_at, updated_at
                ) VALUES (
                    :order_id, :seller_id, :subtotal, :discount_amount, 0.00,
                    :commission_amount, :seller_earning, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP()
                )';

                $stmtSo = $this->pdo->prepare($insertSellerOrderSql);
                $stmtSo->execute([
                    ':order_id'          => $orderId,
                    ':seller_id'         => $sellerId,
                    ':subtotal'          => $sellerSubtotal,
                    ':discount_amount'   => $sellerDiscount,
                    ':commission_amount' => $commissionAmount,
                    ':seller_earning'    => $sellerEarning,
                ]);

                $sellerOrderId = (int) $this->pdo->lastInsertId();
                $sellerOrderMap[$sellerId] = [
                    'id'              => $sellerOrderId,
                    'discount_amount' => $sellerDiscount,
                    'subtotal'        => $sellerSubtotal,
                ];
            }

            // 8. Create immutable order_items snapshots and decrement inventory
            $insertItemSql = 'INSERT INTO order_items (
                order_id, seller_order_id, product_id, variant_id, seller_id,
                product_name, variant_info, sku, quantity, unit_price, total_price,
                discount_amount, commission_rate, commission_amount, seller_earning,
                status, created_at, updated_at
            ) VALUES (
                :order_id, :seller_order_id, :product_id, :variant_id, :seller_id,
                :product_name, :variant_info, :sku, :quantity, :unit_price, :total_price,
                :discount_amount, :commission_rate, :commission_amount, :seller_earning,
                "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )';
            $stmtItem = $this->pdo->prepare($insertItemSql);

            foreach ($validatedItems as $item) {
                $sellerId = $item['seller_id'];
                $sellerOrderId = $sellerOrderMap[$sellerId]['id'];
                $soSubtotal    = $sellerOrderMap[$sellerId]['subtotal'];
                $soDiscount    = $sellerOrderMap[$sellerId]['discount_amount'];

                // Pro-rate line item discount
                $itemDiscount = 0.00;
                if ($soSubtotal > 0.00 && $soDiscount > 0.00) {
                    $itemDiscount = round(($item['total_price'] / $soSubtotal) * $soDiscount, 2);
                }

                $itemNet = max(0.00, $item['total_price'] - $itemDiscount);

                // Applicable commission rate for product category and seller
                $commRate   = $this->commissionService->getCommissionRate($sellerId, $item['category_id']);
                $commAmount = $this->commissionService->calculateCommission($itemNet, $commRate);
                $earning    = round(max(0.00, $itemNet - $commAmount), 2);

                $stmtItem->execute([
                    ':order_id'          => $orderId,
                    ':seller_order_id'   => $sellerOrderId,
                    ':product_id'        => $item['product_id'],
                    ':variant_id'        => $item['variant_id'],
                    ':seller_id'         => $sellerId,
                    ':product_name'      => $item['product_name'],
                    ':variant_info'      => $item['variant_info'],
                    ':sku'               => $item['sku'],
                    ':quantity'          => $item['quantity'],
                    ':unit_price'        => $item['unit_price'],
                    ':total_price'       => $item['total_price'],
                    ':discount_amount'   => $itemDiscount,
                    ':commission_rate'   => $commRate,
                    ':commission_amount' => $commAmount,
                    ':seller_earning'    => $earning,
                ]);

                // 9. Decrement inventory with audit movement record
                $this->inventoryService->decrementStock(
                    $item['product_id'],
                    $item['variant_id'],
                    $item['quantity'],
                    'order',
                    $orderId,
                    "Order fulfillment for #{$orderNumber}",
                    $userId
                );
            }

            // 10. Record coupon redemption if coupon was applied
            if ($couponId !== null && $couponDiscount > 0.00) {
                $this->couponService->redeemCoupon($couponId, $userId, $orderId, $couponDiscount);
            }

            // 11. Create payment record
            $paymentId = $this->paymentService->createPayment(
                $orderId,
                $paymentMethod,
                $totalAmount,
                null,
                'pending'
            );

            // Handle COD or immediate simulated completion
            if (strtolower($paymentMethod) === 'cod') {
                $this->paymentService->processCOD($paymentId);
            } elseif (in_array(strtolower($paymentMethod), ['card', 'jazzcash', 'easypaisa'], true)) {
                // If in simulated/dev environment, mark as paid automatically
                $this->paymentService->processSimulatedPayment($paymentId);
            }

            // 12. Create initial order status history
            $histSql = 'INSERT INTO order_status_history (
                order_id, seller_order_id, old_status, new_status, comment, created_by, created_at
            ) VALUES (
                :order_id, NULL, NULL, "pending", "Order placed by customer", :user_id, UTC_TIMESTAMP()
            )';
            $histStmt = $this->pdo->prepare($histSql);
            $histStmt->execute([':order_id' => $orderId, ':user_id' => $userId]);

            // 13. Dispatch transactional notifications to customer and vendors
            $this->notificationService->notifyOrderPlaced($orderId);

            // 14. Clear shopping cart
            $this->cartService->clearCart($cartId);

            // 15. Commit transaction
            $this->pdo->commit();

            return $orderId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[OrderService::createOrder Error] ' . $e->getMessage());
            throw new RuntimeException('Failed to complete order checkout: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retrieve paginated orders for a customer account.
     *
     * @param int $userId Authenticated customer ID.
     * @param int $page Current page number.
     * @param int $perPage Orders per page.
     * @return array{orders: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getOrdersByUser(int $userId, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :user_id');
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT 
            o.*,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items_count,
            (SELECT COUNT(*) FROM seller_orders so WHERE so.order_id = o.id) AS sellers_count
        FROM orders o
        WHERE o.user_id = :user_id
        ORDER BY o.id DESC
        LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'orders'      => $orders,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve comprehensive order aggregate by ID with ownership verification.
     *
     * @param int $orderId Master order primary key ID.
     * @param int|null $userId Optional user ID to enforce customer access control.
     * @return array<string, mixed>|null Complete order details or null if not found/unauthorized.
     */
    public function getOrderById(int $orderId, ?int $userId = null): ?array
    {
        $sql = 'SELECT o.*, u.first_name, u.last_name, u.email, u.phone AS user_phone 
            FROM orders o 
            JOIN users u ON u.id = o.user_id 
            WHERE o.id = :id'
             . ($userId !== null ? ' AND o.user_id = :user_id' : '')
             . ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $params = [':id' => $orderId];
        if ($userId !== null) {
            $params[':user_id'] = $userId;
        }
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return null;
        }

        // Decode JSON addresses
        $order['shipping_address'] = json_decode((string) $order['shipping_address'], true) ?: [];
        $order['billing_address']  = json_decode((string) $order['billing_address'], true) ?: [];

        // Fetch seller orders
        $soStmt = $this->pdo->prepare('SELECT so.*, sp.store_name, sp.store_slug 
            FROM seller_orders so 
            JOIN seller_profiles sp ON sp.user_id = so.seller_id 
            WHERE so.order_id = :order_id');
        $soStmt->execute([':order_id' => $orderId]);
        $order['seller_orders'] = $soStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch order line items with primary images
        $itemsSql = "SELECT 
            oi.*,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = oi.product_id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id ASC";

        $itemsStmt = $this->pdo->prepare($itemsSql);
        $itemsStmt->execute([':order_id' => $orderId]);
        $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fetch latest payment record
        $order['payment'] = $this->paymentService->getPaymentByOrder($orderId);

        // Fetch status history
        $histStmt = $this->pdo->prepare('SELECT * FROM order_status_history WHERE order_id = :order_id ORDER BY id ASC');
        $histStmt->execute([':order_id' => $orderId]);
        $order['status_history'] = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $order;
    }

    /**
     * Retrieve order aggregate by public order tracking number.
     *
     * @param string $orderNumber Unique order number string.
     * @return array<string, mixed>|null Order aggregate or null.
     */
    public function getOrderByNumber(string $orderNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM orders WHERE order_number = :num LIMIT 1');
        $stmt->execute([':num' => trim($orderNumber)]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            return null;
        }

        return $this->getOrderById((int) $id);
    }

    /**
     * Retrieve vendor-scoped orders with filtering and pagination.
     *
     * @param int $sellerId Vendor user ID.
     * @param array<string, mixed> $filters Filter parameters: status, search, date_from, date_to.
     * @param int $page Current page number.
     * @param int $perPage Orders per page.
     * @return array{orders: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getSellerOrders(int $sellerId, array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $conditions = ['so.seller_id = :seller_id'];
        $params = [':seller_id' => $sellerId];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = 'so.status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(o.order_number LIKE :search OR u.first_name LIKE :search_name OR u.last_name LIKE :search_last)';
            $searchWildcard = '%' . trim((string) $filters['search']) . '%';
            $params[':search']      = $searchWildcard;
            $params[':search_name'] = $searchWildcard;
            $params[':search_last'] = $searchWildcard;
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'so.created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'so.created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $countSql = "SELECT COUNT(*) 
            FROM seller_orders so 
            JOIN orders o ON o.id = so.order_id 
            JOIN users u ON u.id = o.user_id 
            {$where}";

        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataSql = "SELECT 
            so.*,
            o.order_number,
            o.payment_method,
            o.payment_status,
            o.shipping_address,
            u.first_name AS buyer_first_name,
            u.last_name AS buyer_last_name,
            u.email AS buyer_email,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.seller_order_id = so.id) AS item_count
        FROM seller_orders so
        JOIN orders o ON o.id = so.order_id
        JOIN users u ON u.id = o.user_id
        {$where}
        ORDER BY so.id DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($orders as &$ord) {
            $ord['shipping_address'] = json_decode((string) $ord['shipping_address'], true) ?: [];
        }

        $totalPages = (int) ceil($total / $perPage);

        return [
            'orders'      => $orders,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve vendor-specific order details with vendor ownership validation.
     *
     * @param int $sellerOrderId Sub-order ID in seller_orders.
     * @param int $sellerId Vendor user ID.
     * @return array<string, mixed>|null Detailed seller order record or null.
     */
    public function getSellerOrderDetails(int $sellerOrderId, int $sellerId): ?array
    {
        $sql = 'SELECT 
            so.*,
            o.order_number,
            o.payment_method,
            o.payment_status,
            o.shipping_address,
            o.notes AS order_notes,
            u.first_name AS buyer_first_name,
            u.last_name AS buyer_last_name,
            u.email AS buyer_email,
            u.phone AS buyer_phone
        FROM seller_orders so
        JOIN orders o ON o.id = so.order_id
        JOIN users u ON u.id = o.user_id
        WHERE so.id = :id AND so.seller_id = :seller_id
        LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $sellerOrderId, ':seller_id' => $sellerId]);
        $so = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$so) {
            return null;
        }

        $so['shipping_address'] = json_decode((string) $so['shipping_address'], true) ?: [];

        // Fetch line items for this seller order
        $itemsSql = "SELECT 
            oi.*,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = oi.product_id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS primary_image
        FROM order_items oi
        WHERE oi.seller_order_id = :so_id
        ORDER BY oi.id ASC";

        $itemsStmt = $this->pdo->prepare($itemsSql);
        $itemsStmt->execute([':so_id' => $sellerOrderId]);
        $so['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Status history for this seller order
        $histStmt = $this->pdo->prepare('SELECT * FROM order_status_history WHERE seller_order_id = :so_id ORDER BY id ASC');
        $histStmt->execute([':so_id' => $sellerOrderId]);
        $so['status_history'] = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $so;
    }

    /**
     * Update fulfillment status for a vendor's sub-order.
     *
     * @param int $sellerOrderId Sub-order ID in seller_orders.
     * @param int $sellerId Vendor user ID.
     * @param string $newStatus New status ('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned').
     * @param string|null $comment Optional comment for audit log.
     * @param string|null $trackingNumber Optional tracking number if shipping.
     * @param string|null $carrier Optional shipping courier name.
     * @return bool True on success.
     * @throws InvalidArgumentException On invalid status.
     */
    public function updateSellerOrderStatus(
        int $sellerOrderId,
        int $sellerId,
        string $newStatus,
        ?string $comment = null,
        ?string $trackingNumber = null,
        ?string $carrier = null
    ): bool {
        $validStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];
        if (!in_array($newStatus, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid order status: {$newStatus}");
        }

        $stmt = $this->pdo->prepare('SELECT * FROM seller_orders WHERE id = :id AND seller_id = :seller_id LIMIT 1');
        $stmt->execute([':id' => $sellerOrderId, ':seller_id' => $sellerId]);
        $so = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$so) {
            return false;
        }

        $oldStatus = $so['status'];
        if ($oldStatus === $newStatus) {
            return true;
        }

        $orderId = (int) $so['order_id'];

        $this->pdo->beginTransaction();
        try {
            $fields = ['status = :status', 'updated_at = UTC_TIMESTAMP()'];
            $params = [':status' => $newStatus, ':id' => $sellerOrderId];

            if ($newStatus === 'shipped') {
                $fields[] = 'shipped_at = UTC_TIMESTAMP()';
            } elseif ($newStatus === 'delivered') {
                $fields[] = 'delivered_at = UTC_TIMESTAMP()';
            }

            if ($trackingNumber !== null) {
                $fields[] = 'tracking_number = :tracking';
                $params[':tracking'] = trim($trackingNumber);
            }

            if ($carrier !== null) {
                $fields[] = 'shipping_carrier = :carrier';
                $params[':carrier'] = trim($carrier);
            }

            $updSo = $this->pdo->prepare('UPDATE seller_orders SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $updSo->execute($params);

            // Update item statuses under this vendor order
            $updItems = $this->pdo->prepare('UPDATE order_items SET status = :status, updated_at = UTC_TIMESTAMP() WHERE seller_order_id = :so_id');
            $updItems->execute([':status' => $newStatus, ':so_id' => $sellerOrderId]);

            // Append status history
            $histSql = 'INSERT INTO order_status_history (
                order_id, seller_order_id, old_status, new_status, comment, created_by, created_at
            ) VALUES (
                :order_id, :so_id, :old_status, :new_status, :comment, :user_id, UTC_TIMESTAMP()
            )';
            $histStmt = $this->pdo->prepare($histSql);
            $histStmt->execute([
                ':order_id'   => $orderId,
                ':so_id'      => $sellerOrderId,
                ':old_status' => $oldStatus,
                ':new_status' => $newStatus,
                ':comment'    => $comment ?? "Seller updated fulfillment status to {$newStatus}",
                ':user_id'    => $sellerId,
            ]);

            // Check if all sub-orders for this master order have achieved this status
            $checkAllStmt = $this->pdo->prepare('SELECT COUNT(*) FROM seller_orders WHERE order_id = :order_id AND status != :status');
            $checkAllStmt->execute([':order_id' => $orderId, ':status' => $newStatus]);
            $nonMatching = (int) $checkAllStmt->fetchColumn();

            if ($nonMatching === 0) {
                // Elevate master order status
                $updMaster = $this->pdo->prepare('UPDATE orders SET order_status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id');
                $updMaster->execute([':status' => $newStatus, ':id' => $orderId]);
                $this->notificationService->notifyOrderStatusChange($orderId, $newStatus);
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[OrderService::updateSellerOrderStatus Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cancel an order if pending/confirmed, restoring stock and logging status change.
     *
     * @param int $orderId Master order primary key ID.
     * @param int|null $userId Optional user ID for authorization.
     * @return bool True on success, false if order cannot be cancelled.
     */
    public function cancelOrder(int $orderId, ?int $userId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id' . ($userId !== null ? ' AND user_id = :user_id' : '') . ' LIMIT 1');
        $params = [':id' => $orderId];
        if ($userId !== null) {
            $params[':user_id'] = $userId;
        }
        $stmt->execute($params);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return false;
        }

        // Only pending and confirmed orders can be cancelled
        if (!in_array($order['order_status'], ['pending', 'confirmed'], true)) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            // Update master order status
            $updOrder = $this->pdo->prepare('UPDATE orders SET order_status = "cancelled", updated_at = UTC_TIMESTAMP() WHERE id = :id');
            $updOrder->execute([':id' => $orderId]);

            // Update sub-orders
            $updSo = $this->pdo->prepare('UPDATE seller_orders SET status = "cancelled", updated_at = UTC_TIMESTAMP() WHERE order_id = :order_id');
            $updSo->execute([':order_id' => $orderId]);

            // Update order items
            $updItems = $this->pdo->prepare('UPDATE order_items SET status = "cancelled", updated_at = UTC_TIMESTAMP() WHERE order_id = :order_id');
            $updItems->execute([':order_id' => $orderId]);

            // Restore inventory for all items
            $itemsStmt = $this->pdo->prepare('SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = :order_id');
            $itemsStmt->execute([':order_id' => $orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($items as $item) {
                $this->inventoryService->incrementStock(
                    (int) $item['product_id'],
                    $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                    (int) $item['quantity'],
                    'order_cancellation',
                    $orderId,
                    "Restored stock from cancelled order #{$order['order_number']}",
                    $userId
                );
            }

            // Append status history
            $histSql = 'INSERT INTO order_status_history (
                order_id, seller_order_id, old_status, new_status, comment, created_by, created_at
            ) VALUES (
                :order_id, NULL, :old_status, "cancelled", "Order cancelled", :user_id, UTC_TIMESTAMP()
            )';
            $histStmt = $this->pdo->prepare($histSql);
            $histStmt->execute([
                ':order_id'   => $orderId,
                ':old_status' => $order['order_status'],
                ':user_id'    => $userId,
            ]);

            $this->notificationService->notifyOrderStatusChange($orderId, 'cancelled');

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[OrderService::cancelOrder Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Admin query for all marketplace orders with multi-criteria filtering and pagination.
     *
     * @param array<string, mixed> $filters Filters: status, payment_status, payment_method, search, date_from, date_to.
     * @param int $page Page number.
     * @param int $perPage Orders per page.
     * @return array{orders: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getAllOrders(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = 'o.order_status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $conditions[] = 'o.payment_status = :payment_status';
            $params[':payment_status'] = (string) $filters['payment_status'];
        }

        if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
            $conditions[] = 'o.payment_method = :payment_method';
            $params[':payment_method'] = (string) $filters['payment_method'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = '(o.order_number LIKE :search OR u.email LIKE :search_email OR u.first_name LIKE :search_first OR u.last_name LIKE :search_last)';
            $searchWildcard = '%' . trim((string) $filters['search']) . '%';
            $params[':search']       = $searchWildcard;
            $params[':search_email'] = $searchWildcard;
            $params[':search_first'] = $searchWildcard;
            $params[':search_last']  = $searchWildcard;
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'o.created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'o.created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataSql = "SELECT 
            o.*,
            u.first_name,
            u.last_name,
            u.email,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS items_count,
            (SELECT COUNT(*) FROM seller_orders so WHERE so.order_id = o.id) AS sellers_count
        FROM orders o
        JOIN users u ON u.id = o.user_id
        {$where}
        ORDER BY o.id DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'orders'      => $orders,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Administrator override update on master order status.
     *
     * @param int $orderId Master order primary key ID.
     * @param string $newStatus New master order status.
     * @param int|null $adminId Performing administrator user ID.
     * @param string|null $comment Optional audit comment.
     * @return bool True on success.
     * @throws InvalidArgumentException On invalid status.
     */
    public function updateOrderStatus(int $orderId, string $newStatus, ?int $adminId = null, ?string $comment = null): bool
    {
        $validStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];
        if (!in_array($newStatus, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid order status: {$newStatus}");
        }

        $stmt = $this->pdo->prepare('SELECT order_status FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $oldStatus = $stmt->fetchColumn();

        if ($oldStatus === false) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            // Update master order
            $updOrder = $this->pdo->prepare('UPDATE orders SET order_status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id');
            $updOrder->execute([':status' => $newStatus, ':id' => $orderId]);

            // Cascade update to sub-orders
            $updSo = $this->pdo->prepare('UPDATE seller_orders SET status = :status, updated_at = UTC_TIMESTAMP() WHERE order_id = :order_id');
            $updSo->execute([':status' => $newStatus, ':order_id' => $orderId]);

            // Cascade update to line items
            $updItems = $this->pdo->prepare('UPDATE order_items SET status = :status, updated_at = UTC_TIMESTAMP() WHERE order_id = :order_id');
            $updItems->execute([':status' => $newStatus, ':order_id' => $orderId]);

            // Append status history
            $histSql = 'INSERT INTO order_status_history (
                order_id, seller_order_id, old_status, new_status, comment, created_by, created_at
            ) VALUES (
                :order_id, NULL, :old_status, :new_status, :comment, :admin_id, UTC_TIMESTAMP()
            )';
            $histStmt = $this->pdo->prepare($histSql);
            $histStmt->execute([
                ':order_id'   => $orderId,
                ':old_status' => (string) $oldStatus,
                ':new_status' => $newStatus,
                ':comment'    => $comment ?? "Admin status change to {$newStatus}",
                ':admin_id'   => $adminId,
            ]);

            $this->notificationService->notifyOrderStatusChange($orderId, $newStatus);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[OrderService::updateOrderStatus Error] ' . $e->getMessage());
            return false;
        }
    }
}
