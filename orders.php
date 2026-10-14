<?php
/**
 * ShopSphere - Complete Customer Order History
 *
 * Provides a paginated, filterable list of all orders placed by the authenticated customer,
 * complete with item summaries, status indicators, and detail view navigation.
 *
 * @package ShopSphere\Customer
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/validation.php';

// Enforce customer login
require_login();

$userId = (int) get_current_user_id();
$pdo = get_db_connection();

// Status filter handling
$selectedStatus = strtolower(sanitize_string($_GET['status'] ?? 'all'));
$allowedStatuses = ['all', 'pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = 'all';
}

// Search filter handling (order number search)
$searchQuery = sanitize_string($_GET['search'] ?? '');

// Current page handling
$currentPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$perPage = 10;

// Build query conditions
$conditions = ['o.user_id = :uid'];
$params = [':uid' => $userId];

if ($selectedStatus !== 'all') {
    $conditions[] = 'o.order_status = :status';
    $params[':status'] = $selectedStatus;
}

if ($searchQuery !== '') {
    $conditions[] = 'o.order_number LIKE :search';
    $params[':search'] = '%' . $searchQuery . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// Count total matching orders
$totalOrders = 0;
try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM orders o {$whereClause}");
    $stmtCount->execute($params);
    $totalOrders = (int) $stmtCount->fetchColumn();
} catch (Throwable $e) {
    error_log('[ShopSphere Orders Count Error] ' . $e->getMessage());
}

// Calculate pagination
$pagination = paginate($totalOrders, $perPage, $currentPage);
$offset = $pagination['offset'];

// Fetch paginated orders
$orders = [];
try {
    $sql = "SELECT 
        o.id,
        o.order_number,
        o.subtotal,
        o.total_amount,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.created_at,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
        (SELECT GROUP_CONCAT(oi.product_name SEPARATOR ', ') FROM (SELECT product_name, order_id FROM order_items LIMIT 3) oi WHERE oi.order_id = o.id) AS item_preview
    FROM orders o
    {$whereClause}
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // If nested subquery with LIMIT fails on MySQL version, fallback to simpler subquery
    try {
        $sqlFallback = "SELECT 
            o.id,
            o.order_number,
            o.subtotal,
            o.total_amount,
            o.payment_method,
            o.payment_status,
            o.order_status,
            o.created_at,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count,
            (SELECT oi.product_name FROM order_items oi WHERE oi.order_id = o.id LIMIT 1) AS item_preview
        FROM orders o
        {$whereClause}
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT :limit OFFSET :offset";

        $stmtFallback = $pdo->prepare($sqlFallback);
        foreach ($params as $k => $v) {
            $stmtFallback->bindValue($k, $v);
        }
        $stmtFallback->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmtFallback->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtFallback->execute();
        $orders = $stmtFallback->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        error_log('[ShopSphere Orders Fetch Error] ' . $e2->getMessage());
        $orders = [];
    }
}

// Status counts for filter pills
$statusCounts = ['all' => 0, 'pending' => 0, 'processing' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0];
try {
    $stmtCounts = $pdo->prepare('SELECT order_status, COUNT(*) AS cnt FROM orders WHERE user_id = :uid GROUP BY order_status');
    $stmtCounts->execute([':uid' => $userId]);
    $totalAll = 0;
    while ($row = $stmtCounts->fetch(PDO::FETCH_ASSOC)) {
        $st = strtolower((string) $row['order_status']);
        $c = (int) $row['cnt'];
        $totalAll += $c;
        if (isset($statusCounts[$st])) {
            $statusCounts[$st] = $c;
        }
    }
    $statusCounts['all'] = $totalAll;
} catch (Throwable $e) {
    // Keep defaults
}

$page_title = 'My Orders';
$active_page = 'orders';

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav class="ss-breadcrumb mb-4" aria-label="breadcrumb">
        <ol>
            <li><a href="<?= url('/') ?>">Home</a></li>
            <li><a href="<?= url('account.php') ?>">My Account</a></li>
            <li>My Orders</li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3 col-md-4">
            <?php require __DIR__ . '/includes/account-sidebar.php'; ?>
        </div>

        <!-- Main Orders Content -->
        <div class="col-lg-9 col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h4 class="fw-bold mb-1"><i class="bi bi-box-seam-fill text-ss-accent me-2"></i>Order History</h4>
                    <p class="text-muted small mb-0">Track shipments, view invoices, and review past purchases.</p>
                </div>
                <!-- Search bar -->
                <form action="<?= url('orders.php') ?>" method="GET" class="d-flex gap-2">
                    <?php if ($selectedStatus !== 'all'): ?>
                        <input type="hidden" name="status" value="<?= e($selectedStatus) ?>">
                    <?php endif; ?>
                    <div class="input-group input-group-sm" style="max-width: 250px;">
                        <input type="search" name="search" class="form-control" placeholder="Search Order #" value="<?= e($searchQuery) ?>" aria-label="Search order number">
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                    <?php if ($searchQuery !== ''): ?>
                        <a href="<?= url('orders.php' . ($selectedStatus !== 'all' ? '?status=' . urlencode($selectedStatus) : '')) ?>" class="btn btn-sm btn-outline-danger" title="Clear Search">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Status Filter Pills -->
            <div class="ss-card mb-4 shadow-sm border-0">
                <div class="ss-card-body p-2 d-flex flex-wrap gap-2 overflow-x-auto">
                    <?php
                    $filterTabs = [
                        'all'        => 'All Orders',
                        'pending'    => 'Pending',
                        'processing' => 'Processing',
                        'shipped'    => 'Shipped',
                        'delivered'  => 'Delivered',
                        'cancelled'  => 'Cancelled',
                    ];
                    ?>
                    <?php foreach ($filterTabs as $key => $label): ?>
                        <?php
                        $isActive = ($selectedStatus === $key);
                        $count = $statusCounts[$key] ?? 0;
                        $queryStr = http_build_query(array_filter([
                            'status' => $key === 'all' ? null : $key,
                            'search' => $searchQuery !== '' ? $searchQuery : null,
                        ]));
                        $url = url('orders.php' . ($queryStr ? '?' . $queryStr : ''));
                        ?>
                        <a href="<?= $url ?>" class="btn btn-sm <?= $isActive ? 'btn-ss-primary' : 'btn-light text-muted' ?> rounded-pill px-3 py-1">
                            <?= e($label) ?> <span class="badge <?= $isActive ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $count ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Orders Table Card -->
            <div class="ss-card shadow-sm">
                <div class="ss-card-body p-0">
                    <?php if (empty($orders)): ?>
                        <div class="ss-empty-state py-5">
                            <i class="bi bi-bag-x"></i>
                            <h5>No Orders Found</h5>
                            <?php if ($selectedStatus !== 'all' || $searchQuery !== ''): ?>
                                <p>No orders match the selected filters. Try changing your search or status filter.</p>
                                <a href="<?= url('orders.php') ?>" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filters
                                </a>
                            <?php else: ?>
                                <p>You haven't placed any orders yet. Discover our quality products at great prices!</p>
                                <a href="<?= url('/') ?>" class="btn btn-ss-primary btn-sm">
                                    <i class="bi bi-shop me-1"></i> Start Shopping
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table ss-table mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Date</th>
                                        <th>Items Summary</th>
                                        <th>Total Amount</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <?php
                                        $statusClass = match (strtolower((string) $order['order_status'])) {
                                            'delivered'  => 'ss-badge-success',
                                            'shipped'    => 'ss-badge-info',
                                            'processing' => 'ss-badge-warning',
                                            'confirmed'  => 'ss-badge-info',
                                            'cancelled', 'returned' => 'ss-badge-danger',
                                            default      => 'ss-badge-warning',
                                        };

                                        $paymentStatusClass = match (strtolower((string) $order['payment_status'])) {
                                            'paid'      => 'text-success',
                                            'failed'    => 'text-danger',
                                            'refunded'  => 'text-info',
                                            default     => 'text-warning',
                                        };
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?= url('order-details.php?id=' . $order['id']) ?>" class="fw-bold text-ss-accent text-decoration-none">
                                                    <?= e($order['order_number']) ?>
                                                </a>
                                            </td>
                                            <td class="text-muted small">
                                                <?= e(format_date($order['created_at'], 'M d, Y')) ?><br>
                                                <span style="font-size: 0.72rem;"><?= e(format_date($order['created_at'], 'h:i A')) ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-1">
                                                    <span class="badge bg-light text-dark border me-1">
                                                        <?= (int) ($order['item_count'] ?? 1) ?> item(s)
                                                    </span>
                                                </div>
                                                <?php if (!empty($order['item_preview'])): ?>
                                                    <small class="text-muted text-truncate d-block" style="max-width: 190px;" title="<?= e($order['item_preview']) ?>">
                                                        <?= e(truncate($order['item_preview'], 35)) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark">
                                                    <?= format_price($order['total_amount']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="text-uppercase small fw-semibold">
                                                    <?= e($order['payment_method'] ?? 'COD') ?>
                                                </span>
                                                <small class="d-block <?= $paymentStatusClass ?>" style="font-size: 0.72rem;">
                                                    &bull; <?= e(ucfirst((string) ($order['payment_status'] ?? 'pending'))) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="ss-badge <?= $statusClass ?>">
                                                    <?= e(ucfirst((string) $order['order_status'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?= url('order-details.php?id=' . $order['id']) ?>" class="btn btn-outline-primary" title="View Order Details">
                                                        <i class="bi bi-eye"></i> Details
                                                    </a>
                                                    <a href="<?= url('track-order.php?order_number=' . urlencode($order['order_number'])) ?>" class="btn btn-outline-secondary" title="Track Live Status">
                                                        <i class="bi bi-truck"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Controls -->
                        <?php if ($pagination['total_pages'] > 1): ?>
                            <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <small class="text-muted">
                                    Showing <?= $pagination['from'] ?> to <?= $pagination['to'] ?> of <?= $pagination['total'] ?> orders
                                </small>
                                <nav aria-label="Order pagination">
                                    <ul class="ss-pagination mb-0 mt-0">
                                        <!-- Previous Page Link -->
                                        <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                                            <?php
                                            $prevParams = array_filter([
                                                'page'   => $pagination['prev_page'],
                                                'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
                                                'search' => $searchQuery !== '' ? $searchQuery : null,
                                            ]);
                                            ?>
                                            <a class="page-link" href="<?= $pagination['has_prev'] ? url('orders.php?' . http_build_query($prevParams)) : '#' ?>" aria-label="Previous">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>

                                        <!-- Page Number Links -->
                                        <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                                            <?php
                                            // Show first, last, and window around current page
                                            if ($p === 1 || $p === $pagination['total_pages'] || abs($p - $currentPage) <= 2):
                                                $pParams = array_filter([
                                                    'page'   => $p,
                                                    'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
                                                    'search' => $searchQuery !== '' ? $searchQuery : null,
                                                ]);
                                            ?>
                                                <li class="page-item">
                                                    <a class="page-link <?= $p === $currentPage ? 'active' : '' ?>" href="<?= url('orders.php?' . http_build_query($pParams)) ?>">
                                                        <?= $p ?>
                                                    </a>
                                                </li>
                                            <?php elseif ($p === 2 && $currentPage > 4): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php elseif ($p === $pagination['total_pages'] - 1 && $currentPage < $pagination['total_pages'] - 3): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php endif; ?>
                                        <?php endfor; ?>

                                        <!-- Next Page Link -->
                                        <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                                            <?php
                                            $nextParams = array_filter([
                                                'page'   => $pagination['next_page'],
                                                'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
                                                'search' => $searchQuery !== '' ? $searchQuery : null,
                                            ]);
                                            ?>
                                            <a class="page-link" href="<?= $pagination['has_next'] ? url('orders.php?' . http_build_query($nextParams)) : '#' ?>" aria-label="Next">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
