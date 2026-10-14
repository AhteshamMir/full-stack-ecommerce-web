<?php
/**
 * ShopSphere - Customer Ratings & Product Reviews Service
 *
 * Handles verified purchase validation, rating aggregations, spam prevention,
 * admin moderation workflows, and vendor performance scoring.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class ReviewService
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
     * Determine whether a user is eligible to submit a review for a specific product.
     *
     * User must have a verified delivered purchase of the product and have not yet reviewed it.
     *
     * @param int $userId Customer user primary key ID.
     * @param int $productId Target product ID.
     * @return array{can_review: bool, order_item_id: int|null, reason: string|null}
     */
    public function canReview(int $userId, int $productId): array
    {
        // Check for existing review
        $existingStmt = $this->pdo->prepare('SELECT id FROM reviews WHERE user_id = :user_id AND product_id = :product_id LIMIT 1');
        $existingStmt->execute([':user_id' => $userId, ':product_id' => $productId]);
        if ($existingStmt->fetch()) {
            return [
                'can_review'    => false,
                'order_item_id' => null,
                'reason'        => 'You have already submitted a review for this product.',
            ];
        }

        // Check for delivered order containing this product
        $orderStmt = $this->pdo->prepare("SELECT oi.id AS order_item_id 
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.user_id = :user_id 
              AND oi.product_id = :product_id
              AND (o.order_status = 'delivered' OR oi.status = 'delivered')
            ORDER BY oi.id DESC 
            LIMIT 1");
        $orderStmt->execute([':user_id' => $userId, ':product_id' => $productId]);
        $orderItemId = $orderStmt->fetchColumn();

        if (!$orderItemId) {
            return [
                'can_review'    => false,
                'order_item_id' => null,
                'reason'        => 'Reviews are restricted to verified purchasers of this item once delivered.',
            ];
        }

        return [
            'can_review'    => true,
            'order_item_id' => (int) $orderItemId,
            'reason'        => null,
        ];
    }

    /**
     * Create a new product review and rating.
     *
     * @param int $userId Reviewer customer ID.
     * @param int $productId Target product ID.
     * @param int|null $orderItemId Optional specific order item ID.
     * @param int $rating Score between 1 and 5.
     * @param string $title Short review summary headline.
     * @param string $comment Detailed feedback comment.
     * @return int Created review primary key ID.
     * @throws InvalidArgumentException|RuntimeException On invalid input or unauthorized submission.
     */
    public function createReview(
        int $userId,
        int $productId,
        ?int $orderItemId = null,
        int $rating = 5,
        string $title = '',
        string $comment = ''
    ): int {
        $rating = max(1, min(5, $rating));
        $title   = trim($title);
        $comment = trim($comment);

        $eligibility = $this->canReview($userId, $productId);
        if (!$eligibility['can_review']) {
            throw new RuntimeException($eligibility['reason'] ?? 'You are not eligible to review this product.');
        }

        $matchedOrderItemId = $orderItemId ?? $eligibility['order_item_id'];
        $isVerifiedPurchase = ($matchedOrderItemId !== null);

        // Auto-approve by default for marketplace activity (or configure as pending)
        $status = 'approved';

        $sql = 'INSERT INTO reviews (
            product_id, user_id, order_item_id, rating, title, comment, status,
            is_verified_purchase, admin_response, reported, created_at, updated_at
        ) VALUES (
            :product_id, :user_id, :order_item_id, :rating, :title, :comment, :status,
            :is_verified_purchase, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':product_id'           => $productId,
            ':user_id'              => $userId,
            ':order_item_id'        => $matchedOrderItemId,
            ':rating'               => $rating,
            ':title'                => $title !== '' ? $title : null,
            ':comment'              => $comment !== '' ? $comment : null,
            ':status'               => $status,
            ':is_verified_purchase' => $isVerifiedPurchase ? 1 : 0,
        ]);

        $reviewId = (int) $this->pdo->lastInsertId();

        // Recalculate rolling average ratings
        if ($status === 'approved') {
            $this->updateProductRating($productId);
        }

        return $reviewId;
    }

    /**
     * Retrieve approved reviews for a product with author names and avatars.
     *
     * @param int $productId Target product ID.
     * @param int $page Current page number.
     * @param int $perPage Reviews per page.
     * @return array{
     *   reviews: array<int, array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   per_page: int,
     *   rating_breakdown: array<int, int>
     * }
     */
    public function getProductReviews(int $productId, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM reviews WHERE product_id = :product_id AND status = 'approved'");
        $countStmt->execute([':product_id' => $productId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT 
            r.*,
            u.first_name,
            u.last_name,
            u.avatar
        FROM reviews r
        JOIN users u ON u.id = r.user_id
        WHERE r.product_id = :product_id AND r.status = 'approved'
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Rating breakdown (1 to 5 stars distribution)
        $distStmt = $this->pdo->prepare("SELECT rating, COUNT(*) AS count 
            FROM reviews 
            WHERE product_id = :product_id AND status = 'approved' 
            GROUP BY rating");
        $distStmt->execute([':product_id' => $productId]);
        $distRows = $distStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $breakdown = [
            5 => (int) ($distRows[5] ?? 0),
            4 => (int) ($distRows[4] ?? 0),
            3 => (int) ($distRows[3] ?? 0),
            2 => (int) ($distRows[2] ?? 0),
            1 => (int) ($distRows[1] ?? 0),
        ];

        return [
            'reviews'          => $reviews,
            'total'            => $total,
            'page'             => $page,
            'per_page'         => $perPage,
            'rating_breakdown' => $breakdown,
        ];
    }

    /**
     * Retrieve all reviews submitted by an authenticated customer.
     *
     * @param int $userId Reviewer customer ID.
     * @param int $page Current page number.
     * @param int $perPage Reviews per page.
     * @return array{reviews: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function getUserReviews(int $userId, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM reviews WHERE user_id = :user_id');
        $countStmt->execute([':user_id' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT 
            r.*,
            p.name AS product_name,
            p.slug AS product_slug,
            (SELECT pi.image_path 
             FROM product_images pi 
             WHERE pi.product_id = p.id 
             ORDER BY pi.is_primary DESC, pi.sort_order ASC 
             LIMIT 1) AS product_image
        FROM reviews r
        JOIN products p ON p.id = r.product_id
        WHERE r.user_id = :user_id
        ORDER BY r.created_at DESC
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'reviews'  => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Admin review moderation update.
     *
     * @param int $reviewId Target review ID.
     * @param string $status Moderation verdict ('approved', 'rejected', 'pending').
     * @param string|null $adminResponse Optional published admin response text.
     * @return bool True on success.
     */
    public function moderateReview(int $reviewId, string $status, ?string $adminResponse = null): bool
    {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT product_id FROM reviews WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $reviewId]);
        $productId = $stmt->fetchColumn();

        if (!$productId) {
            return false;
        }

        $productId = (int) $productId;

        $updStmt = $this->pdo->prepare('UPDATE reviews SET 
            status = :status, 
            admin_response = :resp, 
            updated_at = UTC_TIMESTAMP() 
            WHERE id = :id');
        $updStmt->execute([
            ':status' => $status,
            ':resp'   => $adminResponse ? trim($adminResponse) : null,
            ':id'     => $reviewId,
        ]);

        $this->updateProductRating($productId);

        return true;
    }

    /**
     * Recalculate and synchronize product and vendor average rating scores.
     *
     * @param int $productId Target product ID.
     * @return array{rating_avg: float, rating_count: int} Updated metrics.
     */
    public function updateProductRating(int $productId): array
    {
        // 1. Calculate aggregate for product
        $calcStmt = $this->pdo->prepare("SELECT 
            COALESCE(ROUND(AVG(rating), 2), 0.00) AS avg_rating,
            COUNT(*) AS total_count
        FROM reviews 
        WHERE product_id = :product_id AND status = 'approved'");
        $calcStmt->execute([':product_id' => $productId]);
        $res = $calcStmt->fetch(PDO::FETCH_ASSOC);

        $avgRating  = (float) ($res['avg_rating'] ?? 0.00);
        $totalCount = (int) ($res['total_count'] ?? 0);

        // Update product table
        $updProduct = $this->pdo->prepare('UPDATE products SET 
            rating_avg = :avg, 
            rating_count = :cnt, 
            updated_at = UTC_TIMESTAMP() 
            WHERE id = :id');
        $updProduct->execute([
            ':avg' => $avgRating,
            ':cnt' => $totalCount,
            ':id'  => $productId,
        ]);

        // 2. Sync vendor average rating
        $sellerStmt = $this->pdo->prepare('SELECT seller_id FROM products WHERE id = :id LIMIT 1');
        $sellerStmt->execute([':id' => $productId]);
        $sellerId = $sellerStmt->fetchColumn();

        if ($sellerId) {
            $sellerCalcStmt = $this->pdo->prepare("SELECT 
                COALESCE(ROUND(AVG(r.rating), 2), 0.00) AS vendor_avg
            FROM reviews r
            JOIN products p ON p.id = r.product_id
            WHERE p.seller_id = :seller_id AND r.status = 'approved'");
            $sellerCalcStmt->execute([':seller_id' => $sellerId]);
            $vendorAvg = (float) $sellerCalcStmt->fetchColumn();

            $updSeller = $this->pdo->prepare('UPDATE seller_profiles SET 
                rating_avg = :avg, 
                updated_at = UTC_TIMESTAMP() 
                WHERE user_id = :seller_id');
            $updSeller->execute([
                ':avg'       => $vendorAvg,
                ':seller_id' => $sellerId,
            ]);
        }

        return [
            'rating_avg'   => $avgRating,
            'rating_count' => $totalCount,
        ];
    }
}
