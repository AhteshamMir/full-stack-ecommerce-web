<?php
/**
 * ShopSphere - In-App Notification Service
 *
 * Manages customer, seller, and administrator transactional alerts,
 * unread badges, order status updates, vendor verification notices, and stock alerts.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class NotificationService
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
        // Always use the provided PDO instance to avoid connection issues
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = Database::getInstance();
        }
    }

    /**
     * Create and dispatch a new in-app notification.
     *
     * @param int $userId Recipient user primary key ID.
     * @param string $type Category ('order', 'payout', 'account', 'system', 'stock').
     * @param string $title Concise title header.
     * @param string $message Detailed alert message.
     * @param string|null $link Optional relative URL target.
     * @return int Created notification primary key ID.
     */
    public function create(int $userId, string $type, string $title, string $message, ?string $link = null): int
    {
        $sql = 'INSERT INTO notifications (
            user_id, type, title, message, link, is_read, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP()
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $userId,
            $type,
            trim($title),
            trim($message),
            $link ? trim($link) : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Retrieve paginated notifications for a given user.
     *
     * @param int $userId Target user ID.
     * @param int $limit Maximum records to return.
     * @param int $offset Records offset.
     * @return array<int, array<string, mixed>> Ordered notification records.
     */
    public function getUserNotifications(int $userId, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT * FROM notifications 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retrieve count of unread notifications for a user (for UI badge).
     *
     * @param int $userId Target user ID.
     * @return int Unread notification count.
     */
    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Mark an individual notification as read with user authorization check.
     *
     * @param int $notificationId Notification primary key ID.
     * @param int $userId Authenticated user ID.
     * @return bool True on success.
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':id'      => $notificationId,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark all notifications as read for a given user.
     *
     * @param int $userId Authenticated user ID.
     * @return int Number of rows marked read.
     */
    public function markAllAsRead(int $userId): int
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
        $stmt->execute([':user_id' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Dispatch notifications upon master order placement to buyer and participating vendors.
     *
     * @param int $orderId Master order primary key ID.
     * @return void
     */
    public function notifyOrderPlaced(int $orderId): void
    {
        // 1. Fetch order details
        $stmt = $this->pdo->prepare('SELECT order_number, user_id, total_amount FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return;
        }

        $orderNumber = $order['order_number'];
        $customerId  = (int) $order['user_id'];
        $totalFormatted = number_format((float) $order['total_amount'], 2);

        // Notify customer
        $this->create(
            $customerId,
            'order',
            "Order Confirmed: #{$orderNumber}",
            "Thank you for your order! Order #{$orderNumber} for Rs. {$totalFormatted} has been placed successfully.",
            "/orders/view.php?id={$orderId}"
        );

        // 2. Fetch all seller orders
        $sellerOrdersStmt = $this->pdo->prepare('SELECT id, seller_id, subtotal FROM seller_orders WHERE order_id = :order_id');
        $sellerOrdersStmt->execute([':order_id' => $orderId]);
        $sellerOrders = $sellerOrdersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($sellerOrders as $so) {
            $sellerId = (int) $so['seller_id'];
            $sellerOrderId = (int) $so['id'];
            $soSubtotal = number_format((float) $so['subtotal'], 2);

            $this->create(
                $sellerId,
                'order',
                "New Order Received: #{$orderNumber}",
                "You have received a new customer order #{$orderNumber} amounting to Rs. {$soSubtotal}. Please review and prepare fulfillment.",
                "/seller/orders/view.php?id={$sellerOrderId}"
            );
        }
    }

    /**
     * Notify customer about order fulfillment lifecycle transitions.
     *
     * @param int $orderId Master order primary key ID.
     * @param string $newStatus New status string.
     * @return void
     */
    public function notifyOrderStatusChange(int $orderId, string $newStatus): void
    {
        $stmt = $this->pdo->prepare('SELECT order_number, user_id FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return;
        }

        $customerId  = (int) $order['user_id'];
        $orderNumber = $order['order_number'];
        $statusLabel = ucfirst($newStatus);

        $message = match ($newStatus) {
            'confirmed'  => "Your order #{$orderNumber} has been verified and confirmed by the marketplace.",
            'processing' => "Vendors are currently packaging and assembling your order #{$orderNumber}.",
            'shipped'    => "Great news! Your order #{$orderNumber} has been handed off to courier for delivery.",
            'delivered'  => "Your package for order #{$orderNumber} has been successfully delivered. Enjoy your purchase!",
            'cancelled'  => "Your order #{$orderNumber} has been cancelled.",
            'returned'   => "Your return request for order #{$orderNumber} has been processed.",
            default      => "The status of your order #{$orderNumber} has been updated to {$statusLabel}.",
        };

        $this->create(
            $customerId,
            'order',
            "Order Status Update: #{$orderNumber}",
            $message,
            "/orders/view.php?id={$orderId}"
        );
    }

    /**
     * Notify a vendor that their marketplace application has been approved.
     *
     * @param int $sellerId Vendor user ID.
     * @return void
     */
    public function notifySellerApproved(int $sellerId): void
    {
        $this->create(
            $sellerId,
            'account',
            'Vendor Account Approved! 🎉',
            'Congratulations! Your seller store application has been approved by the administrators. You may now publish products and begin selling.',
            '/seller/dashboard.php'
        );
    }

    /**
     * Notify a vendor that their marketplace application was rejected.
     *
     * @param int $sellerId Vendor user ID.
     * @param string $reason Rejection explanation.
     * @return void
     */
    public function notifySellerRejected(int $sellerId, string $reason): void
    {
        $this->create(
            $sellerId,
            'account',
            'Seller Application Update',
            "Your vendor application could not be approved at this time. Reason: {$reason}",
            '/seller/profile.php'
        );
    }

    /**
     * Notify vendor that a submitted product was approved by catalog moderation.
     *
     * @param int $productId Target product ID.
     * @return void
     */
    public function notifyProductApproved(int $productId): void
    {
        $stmt = $this->pdo->prepare('SELECT name, seller_id FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return;
        }

        $sellerId    = (int) $product['seller_id'];
        $productName = $product['name'];

        $this->create(
            $sellerId,
            'system',
            'Product Approved',
            "Your product '{$productName}' has been approved by moderators and is now active on the marketplace.",
            "/seller/products/edit.php?id={$productId}"
        );
    }

    /**
     * Notify vendor regarding critical low inventory for an active product.
     *
     * @param int $productId Target product ID.
     * @param int $currentStock Current inventory balance.
     * @return void
     */
    public function notifyLowStock(int $productId, int $currentStock): void
    {
        $stmt = $this->pdo->prepare('SELECT name, seller_id FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            return;
        }

        $sellerId    = (int) $product['seller_id'];
        $productName = $product['name'];

        $this->create(
            $sellerId,
            'stock',
            'Low Stock Warning',
            "Product '{$productName}' is running low on inventory (only {$currentStock} remaining). Please replenish soon.",
            "/seller/products/edit.php?id={$productId}"
        );
    }
}
