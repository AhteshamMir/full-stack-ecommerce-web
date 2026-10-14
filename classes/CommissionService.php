<?php
/**
 * ShopSphere - Marketplace Commission & Vendor Settlements Service
 *
 * Resolves hierarchical commission rates (seller override > category rule > platform default),
 * computes commission cuts and vendor earnings, and calculates ledger balances.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class CommissionService
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
     * Determine the applicable commission rate percentage for a given seller and category.
     *
     * Precedence order:
     * 1. Active seller rule in commission_rules
     * 2. Vendor profile override in seller_profiles.commission_rate
     * 3. Active category rule in commission_rules
     * 4. Category table default in categories.commission_rate
     * 5. Active default rule in commission_rules
     * 6. Global constant DEFAULT_COMMISSION_RATE (default 10.00%)
     *
     * @param int $sellerId Vendor user ID.
     * @param int|null $categoryId Category primary key ID.
     * @return float Applicable commission percentage.
     */
    public function getCommissionRate(int $sellerId, ?int $categoryId = null): float
    {
        $today = date('Y-m-d');

        // 1. Check seller rule in commission_rules
        $ruleStmt = $this->pdo->prepare('SELECT rate FROM commission_rules
            WHERE type = "seller"
              AND seller_id = ?
              AND effective_from <= ?
              AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY id DESC LIMIT 1');
        $ruleStmt->execute([
            $sellerId,
            $today,
            $today,
        ]);
        $rate = $ruleStmt->fetchColumn();
        if ($rate !== false && $rate !== null) {
            return (float) $rate;
        }

        // 2. Check seller profile override
        $profileStmt = $this->pdo->prepare('SELECT commission_rate FROM seller_profiles WHERE user_id = ? LIMIT 1');
        $profileStmt->execute([$sellerId]);
        $profileRate = $profileStmt->fetchColumn();
        if ($profileRate !== false && $profileRate !== null) {
            return (float) $profileRate;
        }

        // 3. Check category rule in commission_rules
        if ($categoryId !== null) {
            $catRuleStmt = $this->pdo->prepare('SELECT rate FROM commission_rules
                WHERE type = "category"
                  AND category_id = ?
                  AND effective_from <= ?
                  AND (effective_to IS NULL OR effective_to >= ?)
                ORDER BY id DESC LIMIT 1');
            $catRuleStmt->execute([
                $categoryId,
                $today,
                $today,
            ]);
            $catRuleRate = $catRuleStmt->fetchColumn();
            if ($catRuleRate !== false && $catRuleRate !== null) {
                return (float) $catRuleRate;
            }

            // 4. Check category table commission_rate
            $catStmt = $this->pdo->prepare('SELECT commission_rate FROM categories WHERE id = ? LIMIT 1');
            $catStmt->execute([$categoryId]);
            $catRate = $catStmt->fetchColumn();
            if ($catRate !== false && $catRate !== null) {
                return (float) $catRate;
            }
        }

        // 5. Check platform default rule in commission_rules
        $defStmt = $this->pdo->prepare('SELECT rate FROM commission_rules
            WHERE type = "default"
              AND effective_from <= ?
              AND (effective_to IS NULL OR effective_to >= ?)
            ORDER BY id DESC LIMIT 1');
        $defStmt->execute([
            $today,
            $today,
        ]);
        $defaultRate = $defStmt->fetchColumn();
        if ($defaultRate !== false && $defaultRate !== null) {
            return (float) $defaultRate;
        }

        // 6. Global config fallback
        if (defined('DEFAULT_COMMISSION_RATE')) {
            return (float) DEFAULT_COMMISSION_RATE;
        }

        return 10.00;
    }

    /**
     * Calculate commission amount from an order line item or subtotal.
     *
     * @param float $amount Subtotal or item price in PKR.
     * @param float $rate Commission percentage.
     * @return float Calculated platform commission in PKR.
     */
    public function calculateCommission(float $amount, float $rate): float
    {
        $amount = max(0.00, $amount);
        $rate   = max(0.00, min(100.00, $rate));
        return round(($amount * $rate) / 100.0, 2);
    }

    /**
     * Retrieve the current platform-wide default commission rate.
     *
     * @return float Platform default rate percentage.
     */
    public function getDefaultRate(): float
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT rate FROM commission_rules 
            WHERE type = "default" 
              AND effective_from <= :today1 
              AND (effective_to IS NULL OR effective_to >= :today2)
            ORDER BY id DESC LIMIT 1');
        $stmt->execute([':today1' => $today, ':today2' => $today]);
        $val = $stmt->fetchColumn();

        if ($val !== false && $val !== null) {
            return (float) $val;
        }

        if (defined('DEFAULT_COMMISSION_RATE')) {
            return (float) DEFAULT_COMMISSION_RATE;
        }

        return 10.00;
    }

    /**
     * Set a custom commission rate override for a vendor.
     *
     * @param int $sellerId Vendor user ID.
     * @param float $rate Commission percentage (0.00 - 100.00).
     * @param int $adminId Performing administrator user ID.
     * @return bool True on success.
     */
    public function setSellerRate(int $sellerId, float $rate, int $adminId): bool
    {
        $rate = max(0.00, min(100.00, $rate));

        $this->pdo->beginTransaction();
        try {
            // Update seller profile override directly
            $profStmt = $this->pdo->prepare('UPDATE seller_profiles SET commission_rate = :rate, updated_at = UTC_TIMESTAMP() WHERE user_id = :seller_id');
            $profStmt->execute([':rate' => $rate, ':seller_id' => $sellerId]);

            // Expire previous seller rules
            $expStmt = $this->pdo->prepare('UPDATE commission_rules SET effective_to = CURDATE(), updated_at = UTC_TIMESTAMP() WHERE type = "seller" AND seller_id = :seller_id AND (effective_to IS NULL OR effective_to > CURDATE())');
            $expStmt->execute([':seller_id' => $sellerId]);

            // Insert new rule
            $insStmt = $this->pdo->prepare('INSERT INTO commission_rules (
                type, seller_id, rate, effective_from, effective_to, created_by, created_at, updated_at
            ) VALUES (
                "seller", :seller_id, :rate, CURDATE(), NULL, :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )');
            $insStmt->execute([
                ':seller_id'  => $sellerId,
                ':rate'       => $rate,
                ':created_by' => $adminId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[CommissionService::setSellerRate Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set a category-specific commission rate.
     *
     * @param int $categoryId Target category ID.
     * @param float $rate Commission percentage (0.00 - 100.00).
     * @param int $adminId Performing administrator user ID.
     * @return bool True on success.
     */
    public function setCategoryRate(int $categoryId, float $rate, int $adminId): bool
    {
        $rate = max(0.00, min(100.00, $rate));

        $this->pdo->beginTransaction();
        try {
            // Update category table
            $catStmt = $this->pdo->prepare('UPDATE categories SET commission_rate = :rate, updated_at = UTC_TIMESTAMP() WHERE id = :category_id');
            $catStmt->execute([':rate' => $rate, ':category_id' => $categoryId]);

            // Expire previous category rules
            $expStmt = $this->pdo->prepare('UPDATE commission_rules SET effective_to = CURDATE(), updated_at = UTC_TIMESTAMP() WHERE type = "category" AND category_id = :category_id AND (effective_to IS NULL OR effective_to > CURDATE())');
            $expStmt->execute([':category_id' => $categoryId]);

            // Insert new rule
            $insStmt = $this->pdo->prepare('INSERT INTO commission_rules (
                type, category_id, rate, effective_from, effective_to, created_by, created_at, updated_at
            ) VALUES (
                "category", :category_id, :rate, CURDATE(), NULL, :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )');
            $insStmt->execute([
                ':category_id' => $categoryId,
                ':rate'        => $rate,
                ':created_by'  => $adminId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[CommissionService::setCategoryRate Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve all commission rules configured across the platform.
     *
     * @return array<int, array<string, mixed>> List of commission rules.
     */
    public function getCommissionRules(): array
    {
        $sql = 'SELECT 
            cr.*,
            c.name AS category_name,
            sp.store_name,
            u.email AS creator_email
        FROM commission_rules cr
        LEFT JOIN categories c ON c.id = cr.category_id
        LEFT JOIN seller_profiles sp ON sp.user_id = cr.seller_id
        JOIN users u ON u.id = cr.created_by
        ORDER BY cr.effective_from DESC, cr.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Calculate cumulative earnings and order statistics for a seller.
     *
     * @param int $sellerId Target vendor user ID.
     * @param string|null $dateFrom Optional start date (Y-m-d).
     * @param string|null $dateTo Optional end date (Y-m-d).
     * @return array{
     *   total_sales: float,
     *   total_commission: float,
     *   net_earnings: float,
     *   total_orders: int,
     *   delivered_orders: int,
     *   pending_orders: int,
     *   cancelled_orders: int
     * }
     */
    public function getSellerEarnings(int $sellerId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $conditions = ['so.seller_id = :seller_id'];
        $params = [':seller_id' => $sellerId];

        if (!empty($dateFrom)) {
            $conditions[] = 'so.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if (!empty($dateTo)) {
            $conditions[] = 'so.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $sql = "SELECT 
            COALESCE(SUM(so.subtotal - so.discount_amount), 0.00) AS total_sales,
            COALESCE(SUM(so.commission_amount), 0.00) AS total_commission,
            COALESCE(SUM(so.seller_earning), 0.00) AS net_earnings,
            COUNT(*) AS total_orders,
            COUNT(CASE WHEN so.status = 'delivered' THEN 1 END) AS delivered_orders,
            COUNT(CASE WHEN so.status IN ('pending', 'confirmed', 'processing', 'shipped') THEN 1 END) AS pending_orders,
            COUNT(CASE WHEN so.status = 'cancelled' THEN 1 END) AS cancelled_orders
        FROM seller_orders so
        {$where}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_sales'      => round((float) ($res['total_sales'] ?? 0.0), 2),
            'total_commission' => round((float) ($res['total_commission'] ?? 0.0), 2),
            'net_earnings'     => round((float) ($res['net_earnings'] ?? 0.0), 2),
            'total_orders'     => (int) ($res['total_orders'] ?? 0),
            'delivered_orders' => (int) ($res['delivered_orders'] ?? 0),
            'pending_orders'   => (int) ($res['pending_orders'] ?? 0),
            'cancelled_orders' => (int) ($res['cancelled_orders'] ?? 0),
        ];
    }

    /**
     * Compute a vendor's available wallet balance eligible for payout withdrawal.
     *
     * Available Balance = (Earned from delivered orders) - (All pending, approved, completed payouts)
     *
     * @param int $sellerId Vendor user ID.
     * @return array{
     *   delivered_earnings: float,
     *   withdrawn_amount: float,
     *   pending_payouts: float,
     *   available_balance: float
     * }
     */
    public function getSellerBalance(int $sellerId): array
    {
        // 1. Delivered order net earnings (cleared escrow)
        $earnStmt = $this->pdo->prepare("SELECT COALESCE(SUM(seller_earning), 0.00) FROM seller_orders WHERE seller_id = :seller_id AND status = 'delivered'");
        $earnStmt->execute([':seller_id' => $sellerId]);
        $deliveredEarnings = round((float) $earnStmt->fetchColumn(), 2);

        // 2. Completed payouts
        $paidStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM payouts WHERE seller_id = :seller_id AND status = 'completed'");
        $paidStmt->execute([':seller_id' => $sellerId]);
        $completedPayouts = round((float) $paidStmt->fetchColumn(), 2);

        // 3. Pending/approved payouts (reserved in escrow)
        $pendingStmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0.00) FROM payouts WHERE seller_id = :seller_id AND status IN ('pending', 'approved', 'processing')");
        $pendingStmt->execute([':seller_id' => $sellerId]);
        $pendingPayouts = round((float) $pendingStmt->fetchColumn(), 2);

        $totalReservedOrPaid = round($completedPayouts + $pendingPayouts, 2);
        $availableBalance    = round(max(0.00, $deliveredEarnings - $totalReservedOrPaid), 2);

        return [
            'delivered_earnings' => $deliveredEarnings,
            'withdrawn_amount'   => $completedPayouts,
            'pending_payouts'    => $pendingPayouts,
            'available_balance'  => $availableBalance,
        ];
    }
}
