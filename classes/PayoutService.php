<?php
/**
 * ShopSphere - Vendor Payout & Settlement Disbursement Service
 *
 * Manages vendor balance withdrawals, available ledger balances, payout request
 * approvals/rejections, electronic transaction references, and settlement history.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/CommissionService.php';
require_once __DIR__ . '/NotificationService.php';

class PayoutService
{
    /**
     * Database connection instance.
     *
     * @var PDO
     */
    protected PDO $pdo;

    protected CommissionService $commissionService;
    protected NotificationService $notificationService;

    /**
     * Minimum payout withdrawal threshold in PKR.
     */
    public const MINIMUM_PAYOUT_AMOUNT = 500.00;

    /**
     * Constructor accepts optional dependencies.
     *
     * @param PDO|null $pdo Optional PDO instance.
     * @param CommissionService|null $commissionService Optional CommissionService.
     * @param NotificationService|null $notificationService Optional NotificationService.
     */
    public function __construct(
        ?PDO $pdo = null,
        ?CommissionService $commissionService = null,
        ?NotificationService $notificationService = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->commissionService = $commissionService ?? new CommissionService($this->pdo);
        $this->notificationService = $notificationService ?? new NotificationService($this->pdo);
    }

    /**
     * Submit a vendor payout request against available cleared earnings.
     *
     * @param int $sellerId Vendor user ID.
     * @param float $amount Withdrawal amount in PKR.
     * @param string $method Transfer channel ('bank_transfer', 'jazzcash', 'easypaisa').
     * @return int Created payout primary key ID.
     * @throws InvalidArgumentException|RuntimeException On validation failure or insufficient funds.
     */
    public function requestPayout(int $sellerId, float $amount, string $method): int
    {
        $amount = round($amount, 2);
        $method = strtolower(trim($method));

        if (!in_array($method, ['bank_transfer', 'jazzcash', 'easypaisa'], true)) {
            throw new InvalidArgumentException('Invalid payout method. Supported channels: bank_transfer, jazzcash, easypaisa.');
        }

        if ($amount < self::MINIMUM_PAYOUT_AMOUNT) {
            throw new InvalidArgumentException(sprintf('Minimum payout withdrawal amount is Rs. %s.', number_format(self::MINIMUM_PAYOUT_AMOUNT, 2)));
        }

        // Verify available balance
        $availableBalance = $this->getSellerAvailableBalance($sellerId);
        if ($amount > $availableBalance) {
            throw new RuntimeException(sprintf(
                'Requested amount of Rs. %s exceeds your available balance of Rs. %s.',
                number_format($amount, 2),
                number_format($availableBalance, 2)
            ));
        }

        $this->pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO payouts (
                seller_id, amount, method, reference, status, requested_at,
                approved_at, completed_at, rejected_reason, processed_by, created_at, updated_at
            ) VALUES (
                :seller_id, :amount, :method, NULL, "pending", UTC_TIMESTAMP(),
                NULL, NULL, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':seller_id' => $sellerId,
                ':amount'    => $amount,
                ':method'    => $method,
            ]);

            $payoutId = (int) $this->pdo->lastInsertId();

            // Link delivered seller orders that have not yet been tied to a payout
            $deliveredOrdersStmt = $this->pdo->prepare("SELECT so.id, so.seller_earning, so.commission_amount 
                FROM seller_orders so
                LEFT JOIN payout_items pi ON pi.seller_order_id = so.id
                WHERE so.seller_id = :seller_id 
                  AND so.status = 'delivered'
                  AND pi.id IS NULL
                ORDER BY so.id ASC");
            $deliveredOrdersStmt->execute([':seller_id' => $sellerId]);
            $unlinkedOrders = $deliveredOrdersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $runningTotal = 0.00;
            $linkStmt = $this->pdo->prepare('INSERT INTO payout_items (payout_id, seller_order_id, amount, commission_amount) VALUES (:payout_id, :so_id, :amount, :comm)');

            foreach ($unlinkedOrders as $uo) {
                if ($runningTotal >= $amount) {
                    break;
                }
                $earning = (float) $uo['seller_earning'];
                $linkStmt->execute([
                    ':payout_id' => $payoutId,
                    ':so_id'     => $uo['id'],
                    ':amount'    => $earning,
                    ':comm'      => (float) $uo['commission_amount'],
                ]);
                $runningTotal += $earning;
            }

            // Notification
            $this->notificationService->create(
                $sellerId,
                'payout',
                'Payout Request Submitted',
                "Your payout request #{$payoutId} for Rs. " . number_format($amount, 2) . " via {$method} has been received and is awaiting approval.",
                '/seller/payouts.php'
            );

            $this->pdo->commit();
            return $payoutId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[PayoutService::requestPayout Error] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retrieve paginated payout history for a specific seller.
     *
     * @param int $sellerId Vendor user ID.
     * @param int $page Current page number.
     * @param int $perPage Payouts per page.
     * @return array{payouts: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getSellerPayouts(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM payouts WHERE seller_id = :seller_id');
        $countStmt->execute([':seller_id' => $sellerId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT * FROM payouts 
            WHERE seller_id = :seller_id 
            ORDER BY requested_at DESC, id DESC 
            LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'payouts'     => $payouts,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Administrator approval of a pending payout request.
     *
     * @param int $payoutId Target payout primary key ID.
     * @param int $adminId Performing administrator user ID.
     * @return bool True on success.
     */
    public function approvePayout(int $payoutId, int $adminId): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payouts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $payoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payout || $payout['status'] !== 'pending') {
            return false;
        }

        $updStmt = $this->pdo->prepare('UPDATE payouts SET 
            status = "approved", 
            approved_at = UTC_TIMESTAMP(), 
            processed_by = :admin_id, 
            updated_at = UTC_TIMESTAMP() 
            WHERE id = :id');
        $success = $updStmt->execute([
            ':admin_id' => $adminId,
            ':id'       => $payoutId,
        ]);

        if ($success) {
            $formattedAmount = number_format((float) $payout['amount'], 2);
            $this->notificationService->create(
                (int) $payout['seller_id'],
                'payout',
                'Payout Approved',
                "Your payout request #{$payoutId} for Rs. {$formattedAmount} has been approved and is being processed for disbursement.",
                '/seller/payouts.php'
            );
        }

        return $success;
    }

    /**
     * Administrator rejection of a payout request.
     *
     * @param int $payoutId Target payout primary key ID.
     * @param int $adminId Performing administrator user ID.
     * @param string $reason Rejection explanation.
     * @return bool True on success.
     */
    public function rejectPayout(int $payoutId, int $adminId, string $reason): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payouts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $payoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payout || in_array($payout['status'], ['completed', 'rejected'], true)) {
            return false;
        }

        $updStmt = $this->pdo->prepare('UPDATE payouts SET 
            status = "rejected", 
            rejected_reason = :reason, 
            processed_by = :admin_id, 
            updated_at = UTC_TIMESTAMP() 
            WHERE id = :id');
        $success = $updStmt->execute([
            ':reason'   => trim($reason),
            ':admin_id' => $adminId,
            ':id'       => $payoutId,
        ]);

        if ($success) {
            // Also detach payout items so orders become available again
            $delItems = $this->pdo->prepare('DELETE FROM payout_items WHERE payout_id = :payout_id');
            $delItems->execute([':payout_id' => $payoutId]);

            $formattedAmount = number_format((float) $payout['amount'], 2);
            $this->notificationService->create(
                (int) $payout['seller_id'],
                'payout',
                'Payout Request Rejected',
                "Your payout request #{$payoutId} for Rs. {$formattedAmount} was rejected. Reason: {$reason}",
                '/seller/payouts.php'
            );
        }

        return $success;
    }

    /**
     * Mark a payout as completed upon successful funds disbursement.
     *
     * @param int $payoutId Target payout primary key ID.
     * @param int $adminId Performing administrator user ID.
     * @param string|null $reference Bank or mobile wallet transfer reference number.
     * @return bool True on success.
     */
    public function completePayout(int $payoutId, int $adminId, ?string $reference = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payouts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $payoutId]);
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payout || in_array($payout['status'], ['completed', 'rejected'], true)) {
            return false;
        }

        $updStmt = $this->pdo->prepare('UPDATE payouts SET 
            status = "completed", 
            completed_at = UTC_TIMESTAMP(), 
            reference = :reference, 
            processed_by = :admin_id, 
            updated_at = UTC_TIMESTAMP() 
            WHERE id = :id');
        $success = $updStmt->execute([
            ':reference' => $reference ? trim($reference) : null,
            ':admin_id'  => $adminId,
            ':id'        => $payoutId,
        ]);

        if ($success) {
            $formattedAmount = number_format((float) $payout['amount'], 2);
            $refText = $reference ? " (Ref: {$reference})" : '';
            $this->notificationService->create(
                (int) $payout['seller_id'],
                'payout',
                'Payout Disbursed ✅',
                "Your payout #{$payoutId} for Rs. {$formattedAmount} has been successfully completed and disbursed{$refText}.",
                '/seller/payouts.php'
            );
        }

        return $success;
    }

    /**
     * Admin listing of all marketplace payouts with multi-criteria filtering and pagination.
     *
     * @param array<string, mixed> $filters Filters: status, method, seller_id, date_from, date_to.
     * @param int $page Current page number.
     * @param int $perPage Payouts per page.
     * @return array{payouts: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getAllPayouts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = 'p.status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $conditions[] = 'p.method = :method';
            $params[':method'] = (string) $filters['method'];
        }

        if (!empty($filters['seller_id'])) {
            $conditions[] = 'p.seller_id = :seller_id';
            $params[':seller_id'] = (int) $filters['seller_id'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'p.requested_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'p.requested_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM payouts p {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataSql = "SELECT 
            p.*,
            sp.store_name,
            sp.store_slug,
            sp.business_name,
            u.first_name,
            u.last_name,
            u.email AS seller_email,
            admin_u.first_name AS processor_first_name,
            admin_u.last_name AS processor_last_name
        FROM payouts p
        JOIN users u ON u.id = p.seller_id
        JOIN seller_profiles sp ON sp.user_id = p.seller_id
        LEFT JOIN users admin_u ON admin_u.id = p.processed_by
        {$where}
        ORDER BY p.requested_at DESC, p.id DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'payouts'     => $payouts,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Compute sum of all pending or in-processing payouts for a vendor.
     *
     * @param int $sellerId Vendor user ID.
     * @return float Total pending payout volume in PKR.
     */
    public function getSellerPendingBalance(int $sellerId): float
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0.00) 
            FROM payouts 
            WHERE seller_id = :seller_id 
              AND status IN ('pending', 'approved', 'processing')");
        $stmt->execute([':seller_id' => $sellerId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Compute current available balance eligible for payout withdrawal in PKR.
     *
     * @param int $sellerId Vendor user ID.
     * @return float Cleared available funds in PKR.
     */
    public function getSellerAvailableBalance(int $sellerId): float
    {
        $balance = $this->commissionService->getSellerBalance($sellerId);
        return (float) $balance['available_balance'];
    }
}
