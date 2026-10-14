<?php
/**
 * ShopSphere - Payment Gateway & Transaction Processing Service
 *
 * Coordinates payment creation, gateway responses, sandbox simulation (Card,
 * JazzCash, EasyPaisa), Cash on Delivery (COD) workflows, and order payment reconciliation.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class PaymentService
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
     * Create a payment record against an order in PKR.
     *
     * @param int $orderId Master order primary key ID.
     * @param string $method Payment channel ('card', 'jazzcash', 'easypaisa', 'bank_transfer', 'cod').
     * @param float $amount Payment total amount.
     * @param string|null $transactionId Optional gateway transaction reference.
     * @param string $status Initial payment status ('pending', 'completed', 'failed', 'refunded').
     * @return int Created payment ID.
     * @throws InvalidArgumentException On invalid amount or parameters.
     */
    public function createPayment(
        int $orderId,
        string $method,
        float $amount,
        ?string $transactionId = null,
        string $status = 'pending'
    ): int {
        $amount = max(0.00, $amount);
        $method = strtolower(trim($method));

        if (!in_array($status, ['pending', 'completed', 'failed', 'refunded'], true)) {
            $status = 'pending';
        }

        $sql = 'INSERT INTO payments (
            order_id, method, transaction_id, amount, currency, status, gateway_response, is_simulated, created_at, updated_at
        ) VALUES (
            :order_id, :method, :transaction_id, :amount, "PKR", :status, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':order_id'       => $orderId,
            ':method'         => $method,
            ':transaction_id' => $transactionId,
            ':amount'         => $amount,
            ':status'         => $status,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Process sandbox test transaction simulation, marking payment as completed.
     *
     * @param int $paymentId Target payment primary key ID.
     * @return bool True on success.
     */
    public function processSimulatedPayment(int $paymentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return false;
        }

        $orderId = (int) $payment['order_id'];
        $simulatedTxnId = 'SIM-' . strtoupper($payment['method']) . '-' . strtoupper(bin2hex(random_bytes(6)));

        $gatewayResponse = json_encode([
            'simulated'      => true,
            'channel'        => $payment['method'],
            'transaction_id' => $simulatedTxnId,
            'status'         => 'SUCCESS',
            'amount'         => (float) $payment['amount'],
            'currency'       => 'PKR',
            'timestamp'      => date('c'),
        ], JSON_THROW_ON_ERROR);

        $ownsTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownsTx = true;
        }

        try {
            // Update payment record
            $updPay = $this->pdo->prepare('UPDATE payments SET 
                transaction_id = :tx_id,
                status = "completed",
                gateway_response = :resp,
                is_simulated = 1,
                updated_at = UTC_TIMESTAMP()
                WHERE id = :id');
            $updPay->execute([
                ':tx_id' => $simulatedTxnId,
                ':resp'  => $gatewayResponse,
                ':id'    => $paymentId,
            ]);

            // Update master order status
            $updOrder = $this->pdo->prepare('UPDATE orders SET 
                payment_status = "paid",
                order_status = CASE WHEN order_status = "pending" THEN "confirmed" ELSE order_status END,
                updated_at = UTC_TIMESTAMP()
                WHERE id = :order_id');
            $updOrder->execute([':order_id' => $orderId]);

            // Update seller orders if confirmed
            $updSellerOrders = $this->pdo->prepare('UPDATE seller_orders SET 
                status = CASE WHEN status = "pending" THEN "confirmed" ELSE status END,
                updated_at = UTC_TIMESTAMP()
                WHERE order_id = :order_id');
            $updSellerOrders->execute([':order_id' => $orderId]);

            if ($ownsTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[PaymentService::processSimulatedPayment Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process Cash on Delivery (COD) payment flow.
     *
     * @param int $paymentId Target payment primary key ID.
     * @return bool True on success.
     */
    public function processCOD(int $paymentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return false;
        }

        $orderId = (int) $payment['order_id'];
        $gatewayResponse = json_encode([
            'method'    => 'cod',
            'status'    => 'payable_on_delivery',
            'note'      => 'Courier to collect full cash payment upon physical parcel handoff.',
            'timestamp' => date('c'),
        ], JSON_THROW_ON_ERROR);

        $ownsTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $ownsTx = true;
        }

        try {
            $updPay = $this->pdo->prepare('UPDATE payments SET 
                status = "pending",
                gateway_response = :resp,
                updated_at = UTC_TIMESTAMP()
                WHERE id = :id');
            $updPay->execute([
                ':resp' => $gatewayResponse,
                ':id'   => $paymentId,
            ]);

            // COD orders are confirmed for fulfillment but payment remains pending until delivery
            $updOrder = $this->pdo->prepare('UPDATE orders SET 
                payment_status = "pending",
                order_status = CASE WHEN order_status = "pending" THEN "confirmed" ELSE order_status END,
                updated_at = UTC_TIMESTAMP()
                WHERE id = :order_id');
            $updOrder->execute([':order_id' => $orderId]);

            $updSellerOrders = $this->pdo->prepare('UPDATE seller_orders SET 
                status = CASE WHEN status = "pending" THEN "confirmed" ELSE status END,
                updated_at = UTC_TIMESTAMP()
                WHERE order_id = :order_id');
            $updSellerOrders->execute([':order_id' => $orderId]);

            if ($ownsTx) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[PaymentService::processCOD Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update payment transaction status with external gateway verification payload.
     *
     * @param int $paymentId Target payment ID.
     * @param string $status New status ('pending', 'completed', 'failed', 'refunded').
     * @param array<string, mixed>|string|null $gatewayResponse Raw payload from gateway callback.
     * @param string|null $transactionId External transaction ID.
     * @return bool True on success.
     */
    public function updatePaymentStatus(
        int $paymentId,
        string $status,
        array|string|null $gatewayResponse = null,
        ?string $transactionId = null
    ): bool {
        if (!in_array($status, ['pending', 'completed', 'failed', 'refunded'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT order_id FROM payments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $paymentId]);
        $orderId = $stmt->fetchColumn();

        if (!$orderId) {
            return false;
        }

        $orderId = (int) $orderId;

        $jsonResponse = null;
        if (is_array($gatewayResponse)) {
            $jsonResponse = json_encode($gatewayResponse);
        } elseif (is_string($gatewayResponse)) {
            $jsonResponse = $gatewayResponse;
        }

        $this->pdo->beginTransaction();
        try {
            $fields = ['status = :status', 'updated_at = UTC_TIMESTAMP()'];
            $params = [':status' => $status, ':id' => $paymentId];

            if ($jsonResponse !== null) {
                $fields[] = 'gateway_response = :resp';
                $params[':resp'] = $jsonResponse;
            }

            if ($transactionId !== null) {
                $fields[] = 'transaction_id = :tx_id';
                $params[':tx_id'] = $transactionId;
            }

            $sql = 'UPDATE payments SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $updStmt = $this->pdo->prepare($sql);
            $updStmt->execute($params);

            // Sync master order status
            if ($status === 'completed') {
                $updOrder = $this->pdo->prepare('UPDATE orders SET 
                    payment_status = "paid",
                    order_status = CASE WHEN order_status = "pending" THEN "confirmed" ELSE order_status END,
                    updated_at = UTC_TIMESTAMP() 
                    WHERE id = :order_id');
                $updOrder->execute([':order_id' => $orderId]);
            } elseif ($status === 'failed') {
                $updOrder = $this->pdo->prepare('UPDATE orders SET payment_status = "failed", updated_at = UTC_TIMESTAMP() WHERE id = :order_id');
                $updOrder->execute([':order_id' => $orderId]);
            } elseif ($status === 'refunded') {
                $updOrder = $this->pdo->prepare('UPDATE orders SET payment_status = "refunded", updated_at = UTC_TIMESTAMP() WHERE id = :order_id');
                $updOrder->execute([':order_id' => $orderId]);
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[PaymentService::updatePaymentStatus Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve the latest payment record for a given order ID.
     *
     * @param int $orderId Master order primary key ID.
     * @return array<string, mixed>|null Payment row or null.
     */
    public function getPaymentByOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
