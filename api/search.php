<?php
/**
 * ShopSphere - AJAX Product Search Autocomplete API
 *
 * Provides fast live search autocomplete across product catalogs,
 * matching active approved products and returning top 5 suggestions with pricing and images.
 *
 * @package ShopSphere\API
 */

declare(strict_types=1);

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=UTF-8');

// Load prerequisites
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$query = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$limit = isset($_GET['limit']) ? min(10, max(1, (int) $_GET['limit'])) : 5;

// If query is empty or too short, return empty array immediately
if (mb_strlen($query, 'UTF-8') < 2) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = get_db_connection();

    $sql = "SELECT 
        p.id, 
        p.name, 
        p.slug, 
        p.price,
        (SELECT pi.image_path 
         FROM product_images pi 
         WHERE pi.product_id = p.id 
         ORDER BY pi.is_primary DESC, pi.sort_order ASC 
         LIMIT 1) AS image_path
    FROM products p
    WHERE p.status = 'approved' 
      AND (p.name LIKE :search_title OR p.short_description LIKE :search_desc OR p.sku LIKE :search_sku)
    ORDER BY p.sales_count DESC, p.views_count DESC, p.id DESC
    LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $searchWildcard = '%' . $query . '%';
    $stmt->bindValue(':search_title', $searchWildcard, PDO::PARAM_STR);
    $stmt->bindValue(':search_desc', $searchWildcard, PDO::PARAM_STR);
    $stmt->bindValue(':search_sku', $searchWildcard, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($rows as $row) {
        $image = !empty($row['image_path']) ? asset($row['image_path']) : asset('images/misc/placeholder.svg');
        $results[] = [
            'id'    => (int) $row['id'],
            'name'  => (string) $row['name'],
            'slug'  => (string) $row['slug'],
            'price' => format_price($row['price']),
            'image' => $image,
        ];
    }

    echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('[ShopSphere Search API Error] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
    exit;
}
