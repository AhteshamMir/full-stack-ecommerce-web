<?php
/**
 * ShopSphere - Coupon & Promotional Discount Service
 *
 * Manages promotional vouchers, multi-vendor restrictions, category eligibility,
 * customer redemptions, discount computations, and coupon administration.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class CouponService
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
        // Always use the provided PDO instance to avoid connection issues
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = Database::getInstance();
        }
    }

    /**
     * Validate coupon eligibility against a shopping cart or checkout context.
     *
     * @param string $code Promotional voucher code.
     * @param int|null $userId User ID attempting redemption (optional for guests).
     * @param float $cartTotal Total cart value before discount.
     * @param array<int, array<string, mixed>> $cartItems Array of cart item rows with seller_id and category_id.
     * @return array{valid: bool, coupon?: array<string, mixed>, discount: float, eligible_total?: float, error?: string, message?: string}
     */
    public function validateCoupon(string $code, ?int $userId = null, float $cartTotal = 0.0, array $cartItems = []): array
    {
        $cleanCode = strtoupper(trim($code));
        if ($cleanCode === '') {
            return ['valid' => false, 'discount' => 0.00, 'error' => 'Please enter a coupon code.'];
        }

        $coupon = $this->getCouponByCode($cleanCode);
        if (!$coupon) {
            return ['valid' => false, 'discount' => 0.00, 'error' => 'Coupon code is invalid or does not exist.'];
        }

        if ((int) $coupon['is_active'] !== 1) {
            return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon has been disabled.'];
        }

        $now = time();
        $startTime = !empty($coupon['starts_at']) ? strtotime($coupon['starts_at']) : false;
        $expireTime = !empty($coupon['expires_at']) ? strtotime($coupon['expires_at']) : false;

        // Allow 60-second grace window for slight server/clock skew
        if ($startTime !== false && $now < ($startTime - 60)) {
            return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon promotion has not started yet.'];
        }

        if ($expireTime !== false && $now > $expireTime) {
            return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon promotion has expired.'];
        }

        // Global usage limit check
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon usage limit has been reached.'];
        }

        // Per user usage limit check
        if ($userId !== null && $coupon['per_user_limit'] !== null) {
            $userRedemptionsStmt = $this->pdo->prepare('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?');
            $userRedemptionsStmt->execute([
                $coupon['id'],
                $userId,
            ]);
            $userUsed = (int) $userRedemptionsStmt->fetchColumn();

            if ($userUsed >= (int) $coupon['per_user_limit']) {
                return ['valid' => false, 'discount' => 0.00, 'error' => 'You have already reached the maximum usage limit for this coupon.'];
            }
        }

        // First order only restriction
        if (!empty($coupon['first_order_only'])) {
            if ($userId === null) {
                return ['valid' => false, 'discount' => 0.00, 'error' => 'Please log in to apply a first-order discount coupon.'];
            }

            $orderCountStmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND order_status != 'cancelled'");
            $orderCountStmt->execute([$userId]);
            $pastOrders = (int) $orderCountStmt->fetchColumn();

            if ($pastOrders > 0) {
                return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon is exclusively available for first-time orders.'];
            }
        }

        // Minimum cart total validation
        $minOrderAmount = (float) $coupon['min_order_amount'];
        if ($cartTotal < $minOrderAmount) {
            return [
                'valid'    => false,
                'discount' => 0.00,
                'error'    => sprintf('Minimum order amount of Rs. %s is required to use this coupon.', number_format($minOrderAmount, 2)),
            ];
        }

        // Compute eligible total based on seller or category scope
        $eligibleTotal = 0.00;
        $hasScopeFilter = ($coupon['seller_id'] !== null || $coupon['category_id'] !== null);

        if (!$hasScopeFilter || empty($cartItems)) {
            $eligibleTotal = $cartTotal;
        } else {
            foreach ($cartItems as $item) {
                $itemMatches = true;

                if ($coupon['seller_id'] !== null && (int) ($item['seller_id'] ?? 0) !== (int) $coupon['seller_id']) {
                    $itemMatches = false;
                }

                if ($coupon['category_id'] !== null && (int) ($item['category_id'] ?? 0) !== (int) $coupon['category_id']) {
                    $itemMatches = false;
                }

                if ($itemMatches) {
                    if (isset($item['line_total'])) {
                        $eligibleTotal += (float) $item['line_total'];
                    } elseif (isset($item['total_price'])) {
                        $eligibleTotal += (float) $item['total_price'];
                    } else {
                        $linePrice = (float) ($item['unit_price'] ?? $item['base_price'] ?? $item['price'] ?? 0.0);
                        $lineQty   = (int) ($item['quantity'] ?? 1);
                        $eligibleTotal += ($linePrice * $lineQty);
                    }
                }
            }

            if ($eligibleTotal <= 0.00) {
                if ($coupon['seller_id'] !== null) {
                    return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon applies only to items from the participating seller.'];
                }
                return ['valid' => false, 'discount' => 0.00, 'error' => 'This coupon applies only to items within the designated category.'];
            }
        }

        $discount = $this->calculateDiscount($coupon, $cartTotal, $eligibleTotal);

        return [
            'valid'          => true,
            'coupon'         => $coupon,
            'discount'       => $discount,
            'eligible_total' => $eligibleTotal,
            'message'        => 'Coupon applied successfully!',
        ];
    }

    /**
     * Compute discount amount given coupon parameters and cart/eligible totals.
     *
     * @param array<string, mixed> $coupon Coupon record.
     * @param float $cartTotal Total cart subtotal.
     * @param float|null $eligibleTotal Amount of items eligible for discount.
     * @return float Calculated discount in PKR (capped at max_discount).
     */
    public function calculateDiscount(array $coupon, float $cartTotal, ?float $eligibleTotal = null): float
    {
        $basis = $eligibleTotal ?? $cartTotal;
        $discount = 0.00;

        if ($coupon['type'] === 'percentage') {
            $percentage = (float) $coupon['value'];
            $discount = ($basis * $percentage) / 100.0;

            if ($coupon['max_discount'] !== null) {
                $maxDiscount = (float) $coupon['max_discount'];
                if ($discount > $maxDiscount) {
                    $discount = $maxDiscount;
                }
            }
        } else {
            // Fixed discount amount
            $discount = min((float) $coupon['value'], $basis);
        }

        return round(max(0.00, $discount), 2);
    }

    /**
     * Record coupon usage redemption during checkout order processing.
     *
     * @param int $couponId Target coupon primary key ID.
     * @param int $userId Redeeming user ID.
     * @param int $orderId Placed order ID.
     * @param float $discountAmount Applied discount amount in PKR.
     * @return int Created redemption ID.
     */
    public function redeemCoupon(int $couponId, int $userId, int $orderId, float $discountAmount): int
    {
        $insertSql = 'INSERT INTO coupon_redemptions (
            coupon_id, user_id, order_id, discount_amount, created_at
        ) VALUES (
            ?, ?, ?, ?, UTC_TIMESTAMP()
        )';

        $stmt = $this->pdo->prepare($insertSql);
        $stmt->execute([
            $couponId,
            $userId,
            $orderId,
            max(0.00, $discountAmount),
        ]);

        $redemptionId = (int) $this->pdo->lastInsertId();

        // Increment coupon used counter
        $updateSql = 'UPDATE coupons SET used_count = used_count + 1 WHERE id = ?';
        $updateStmt = $this->pdo->prepare($updateSql);
        $updateStmt->execute([$couponId]);

        return $redemptionId;
    }

    /**
     * Retrieve coupon by exact code string.
     *
     * @param string $code Coupon code string.
     * @return array<string, mixed>|null Coupon record or null.
     */
    public function getCouponByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE UPPER(code) = ? LIMIT 1');
        $stmt->execute([strtoupper(trim($code))]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

        return $coupon !== false ? $coupon : null;
    }

    /**
     * Retrieve coupons created by or assigned to a specific seller.
     *
     * @param int $sellerId Owner seller user ID.
     * @param int $page Page number.
     * @param int $perPage Coupons per page.
     * @return array{coupons: array<int, array<string, mixed>>, total: int}
     */
    public function getSellerCoupons(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM coupons WHERE seller_id = :seller_id');
        $countStmt->execute([':seller_id' => $sellerId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT c.*, cat.name AS category_name 
            FROM coupons c 
            LEFT JOIN categories cat ON cat.id = c.category_id 
            WHERE c.seller_id = :seller_id 
            ORDER BY c.id DESC 
            LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'coupons' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'   => $total,
        ];
    }

    /**
     * Create a new promotional coupon.
     *
     * @param array<string, mixed> $data Coupon parameters.
     * @param int $createdBy User ID of creator (admin or vendor).
     * @return int Created coupon ID.
     * @throws InvalidArgumentException On invalid inputs.
     */
    public function createCoupon(array $data, int $createdBy): int
    {
        $code           = strtoupper(trim((string) ($data['code'] ?? '')));
        $type           = (string) ($data['type'] ?? 'percentage');
        $value          = (float) ($data['value'] ?? 0.0);
        $minOrderAmount = max(0.00, (float) ($data['min_order_amount'] ?? 0.0));
        $maxDiscount    = isset($data['max_discount']) && is_numeric($data['max_discount']) ? (float) $data['max_discount'] : null;
        $usageLimit     = isset($data['usage_limit']) && is_numeric($data['usage_limit']) ? (int) $data['usage_limit'] : null;
        $perUserLimit   = max(1, (int) ($data['per_user_limit'] ?? 1));
        $sellerId       = !empty($data['seller_id']) ? (int) $data['seller_id'] : null;
        $categoryId     = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $firstOrderOnly = !empty($data['first_order_only']) ? 1 : 0;
        $isActive       = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $startsAt       = (string) ($data['starts_at'] ?? date('Y-m-d H:i:s'));
        $expiresAt      = (string) ($data['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+30 days')));

        if ($code === '') {
            throw new InvalidArgumentException('Coupon code is required.');
        }

        if (!in_array($type, ['percentage', 'fixed'], true)) {
            throw new InvalidArgumentException('Coupon type must be "percentage" or "fixed".');
        }

        if ($value <= 0.00) {
            throw new InvalidArgumentException('Discount value must be greater than zero.');
        }

        if ($type === 'percentage' && $value > 100.00) {
            throw new InvalidArgumentException('Percentage discount cannot exceed 100%.');
        }

        // Check unique code
        $checkStmt = $this->pdo->prepare('SELECT id FROM coupons WHERE code = :code LIMIT 1');
        $checkStmt->execute([':code' => $code]);
        if ($checkStmt->fetch()) {
            throw new InvalidArgumentException("A coupon with code '{$code}' already exists.");
        }

        $sql = 'INSERT INTO coupons (
            code, type, value, min_order_amount, max_discount, usage_limit,
            per_user_limit, used_count, seller_id, category_id, first_order_only,
            is_active, starts_at, expires_at, created_by, created_at, updated_at
        ) VALUES (
            :code, :type, :value, :min_order_amount, :max_discount, :usage_limit,
            :per_user_limit, 0, :seller_id, :category_id, :first_order_only,
            :is_active, :starts_at, :expires_at, :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code'             => $code,
            ':type'             => $type,
            ':value'            => $value,
            ':min_order_amount' => $minOrderAmount,
            ':max_discount'     => $maxDiscount,
            ':usage_limit'      => $usageLimit,
            ':per_user_limit'   => $perUserLimit,
            ':seller_id'        => $sellerId,
            ':category_id'      => $categoryId,
            ':first_order_only' => $firstOrderOnly,
            ':is_active'        => $isActive,
            ':starts_at'        => $startsAt,
            ':expires_at'       => $expiresAt,
            ':created_by'       => $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update an existing coupon.
     *
     * @param int $id Coupon primary key ID.
     * @param array<string, mixed> $data Updated parameters.
     * @return bool True on success.
     */
    public function updateCoupon(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['code'])) {
            $code = strtoupper(trim((string) $data['code']));
            if ($code !== '') {
                $fields[] = 'code = :code';
                $params[':code'] = $code;
            }
        }

        if (isset($data['type']) && in_array($data['type'], ['percentage', 'fixed'], true)) {
            $fields[] = 'type = :type';
            $params[':type'] = $data['type'];
        }

        if (isset($data['value']) && is_numeric($data['value'])) {
            $fields[] = 'value = :value';
            $params[':value'] = max(0.00, (float) $data['value']);
        }

        if (isset($data['min_order_amount']) && is_numeric($data['min_order_amount'])) {
            $fields[] = 'min_order_amount = :min_order_amount';
            $params[':min_order_amount'] = max(0.00, (float) $data['min_order_amount']);
        }

        if (array_key_exists('max_discount', $data)) {
            $fields[] = 'max_discount = :max_discount';
            $params[':max_discount'] = is_numeric($data['max_discount']) ? (float) $data['max_discount'] : null;
        }

        if (array_key_exists('usage_limit', $data)) {
            $fields[] = 'usage_limit = :usage_limit';
            $params[':usage_limit'] = is_numeric($data['usage_limit']) ? (int) $data['usage_limit'] : null;
        }

        if (isset($data['per_user_limit']) && is_numeric($data['per_user_limit'])) {
            $fields[] = 'per_user_limit = :per_user_limit';
            $params[':per_user_limit'] = max(1, (int) $data['per_user_limit']);
        }

        if (isset($data['first_order_only'])) {
            $fields[] = 'first_order_only = :first_order_only';
            $params[':first_order_only'] = !empty($data['first_order_only']) ? 1 : 0;
        }

        if (isset($data['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params[':is_active'] = !empty($data['is_active']) ? 1 : 0;
        }

        if (isset($data['starts_at'])) {
            $fields[] = 'starts_at = :starts_at';
            $params[':starts_at'] = (string) $data['starts_at'];
        }

        if (isset($data['expires_at'])) {
            $fields[] = 'expires_at = :expires_at';
            $params[':expires_at'] = (string) $data['expires_at'];
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = 'updated_at = UTC_TIMESTAMP()';
        $sql = 'UPDATE coupons SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Toggle coupon active state.
     *
     * @param int $id Target coupon ID.
     * @param bool $isActive Target state.
     * @return bool True on success.
     */
    public function toggleCoupon(int $id, bool $isActive): bool
    {
        $stmt = $this->pdo->prepare('UPDATE coupons SET is_active = :is_active, updated_at = UTC_TIMESTAMP() WHERE id = :id');
        return $stmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':id'        => $id,
        ]);
    }

    /**
     * Admin query for all marketplace coupons with filtering and pagination.
     *
     * @param array<string, mixed> $filters Filters: search, is_active, seller_id, category_id.
     * @param int $page Page number.
     * @param int $perPage Coupons per page.
     * @return array{coupons: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function getAllCoupons(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $params = [];

        if (!empty($filters['search'])) {
            $conditions[] = 'c.code LIKE :search';
            $params[':search'] = '%' . trim((string) $filters['search']) . '%';
        }

        if (isset($filters['is_active'])) {
            $conditions[] = 'c.is_active = :is_active';
            $params[':is_active'] = !empty($filters['is_active']) ? 1 : 0;
        }

        if (!empty($filters['seller_id'])) {
            $conditions[] = 'c.seller_id = :seller_id';
            $params[':seller_id'] = (int) $filters['seller_id'];
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) FROM coupons c {$where}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataSql = "SELECT c.*, 
            sp.store_name, 
            cat.name AS category_name,
            u.email AS creator_email
        FROM coupons c
        LEFT JOIN seller_profiles sp ON sp.user_id = c.seller_id
        LEFT JOIN categories cat ON cat.id = c.category_id
        JOIN users u ON u.id = c.created_by
        {$where}
        ORDER BY c.id DESC
        LIMIT :limit OFFSET :offset";

        $dataStmt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'coupons'  => $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
