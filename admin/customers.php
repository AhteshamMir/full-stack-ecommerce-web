<?php
/**
 * ShopSphere - Customer Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/helpers.php';

require_role('admin');
$db = get_db();

// Handle Status Toggle & Update Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        flash('error', 'Invalid customer ID provided.');
        redirect(url('/admin/customers.php'));
    }

    // Verify user is a customer
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, status FROM users WHERE id = :id AND role = 'customer'");
    $stmt->execute(['id' => $userId]);
    $targetCustomer = $stmt->fetch();

    if (!$targetCustomer) {
        flash('error', 'Customer not found or unauthorized entity.');
        redirect(url('/admin/customers.php'));
    }

    if ($action === 'toggle_status') {
        $newStatus = ($targetCustomer['status'] === 'active') ? 'suspended' : 'active';
        $updateStmt = $db->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id");
        $updateStmt->execute(['status' => $newStatus, 'id' => $userId]);

        log_audit(
            $db,
            'toggle_customer_status',
            'users',
            $userId,
            ['status' => $targetCustomer['status']],
            ['status' => $newStatus]
        );

        flash('success', "Customer status successfully updated to {$newStatus}.");
        redirect(url('/admin/customers.php'));
    } elseif ($action === 'set_status') {
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['active', 'suspended', 'pending_verification'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            flash('error', 'Invalid status selected.');
            redirect(url('/admin/customers.php'));
        }

        $updateStmt = $db->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id");
        $updateStmt->execute(['status' => $newStatus, 'id' => $userId]);

        log_audit(
            $db,
            'update_customer_status',
            'users',
            $userId,
            ['status' => $targetCustomer['status']],
            ['status' => $newStatus]
        );

        flash('success', "Customer status set to {$newStatus}.");
        redirect(url('/admin/customers.php'));
    }
}

// Search and Filter Parameters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

// Build Query
$where = ["u.role = 'customer'"];
$params = [];

if ($search !== '') {
    $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.phone LIKE :search)";
    $params['search'] = "%{$search}%";
}

if ($status !== '' && in_array($status, ['active', 'suspended', 'pending_verification'], true)) {
    $where[] = "u.status = :status";
    $params['status'] = $status;
}

$whereClause = implode(' AND ', $where);

// Count Total
$countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$whereClause}");
$countStmt->execute($params);
$totalCustomers = (int)$countStmt->fetchColumn();

$pagination = paginate($totalCustomers, $perPage, $page);

// Fetch Paginated Customers with Aggregates
$query = "
    SELECT 
        u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at, u.last_login_at,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders_count,
        (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = u.id AND o.payment_status = 'paid') AS total_spent,
        (SELECT COUNT(*) FROM addresses a WHERE a.user_id = u.id) AS address_count
    FROM users u
    WHERE {$whereClause}
    ORDER BY u.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll();

// Quick Overview Stats
$statActive = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'active'")->fetchColumn();
$statSuspended = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'suspended'")->fetchColumn();
$statPending = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'pending_verification'")->fetchColumn();

$page_title = 'Customer Management';
$current_page = 'customers';
require_once __DIR__ . '/header.php';
?>

<!-- Quick Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-people"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statActive + $statSuspended + $statPending); ?></h3>
                <p>Total Customers</p>
                <div class="small text-ss-muted">Platform Shoppers</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-person-check"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statActive); ?></h3>
                <p>Active Accounts</p>
                <div class="trend up"><i class="bi bi-check-circle"></i> In good standing</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-person-x"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statSuspended); ?></h3>
                <p>Suspended Accounts</p>
                <div class="small text-danger"><i class="bi bi-shield-x"></i> Access blocked</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-clock-history"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statPending); ?></h3>
                <p>Pending Verification</p>
                <div class="small text-ss-muted">Awaiting email confirm</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter and Search Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/customers.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search by customer name, email, or phone..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="suspended" <?php echo $status === 'suspended' ? 'selected' : ''; ?>>Suspended Only</option>
                    <option value="pending_verification" <?php echo $status === 'pending_verification' ? 'selected' : ''; ?>>Pending Verification</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply Filter</button>
                <?php if ($search !== '' || $status !== ''): ?>
                    <a href="<?php echo url('/admin/customers.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Customers Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Customer Directory (<?php echo number_format($totalCustomers); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($customers)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-people fs-1 d-block mb-2"></i>
                <p class="mb-0">No customers found matching the search criteria.</p>
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Orders</th>
                            <th>Total Spent</th>
                            <th>Joined</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $cust): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center rounded-circle border" style="width: 40px; height: 40px;">
                                            <?php echo strtoupper(substr($cust['first_name'], 0, 1) . substr($cust['last_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong class="d-block text-dark"><?php echo e($cust['first_name'] . ' ' . $cust['last_name']); ?></strong>
                                            <span class="small text-ss-muted">ID: #<?php echo (int)$cust['id']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><i class="bi bi-envelope text-ss-muted me-1"></i> <?php echo e($cust['email']); ?></div>
                                    <?php if (!empty($cust['phone'])): ?>
                                        <div class="small text-ss-muted"><i class="bi bi-telephone text-ss-muted me-1"></i> <?php echo e($cust['phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo (int)$cust['orders_count']; ?> orders</span>
                                </td>
                                <td>
                                    <strong class="text-dark"><?php echo format_price($cust['total_spent']); ?></strong>
                                </td>
                                <td>
                                    <span title="<?php echo e($cust['created_at']); ?>"><?php echo format_date($cust['created_at'], 'M d, Y'); ?></span>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($cust['status']) {
                                        'active' => 'success',
                                        'suspended' => 'danger',
                                        'pending_verification' => 'warning',
                                        default => 'default'
                                    }; ?>">
                                        <i class="bi bi-circle-fill" style="font-size: 6px;"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $cust['status'])); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <!-- Quick Toggle Form with CSRF -->
                                        <form method="POST" action="<?php echo url('/admin/customers.php'); ?>" onsubmit="return confirm('Are you sure you want to <?php echo $cust['status'] === 'active' ? 'suspend' : 'activate'; ?> this customer?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$cust['id']; ?>">
                                            <?php if ($cust['status'] === 'active'): ?>
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Suspend customer account">
                                                    <i class="bi bi-slash-circle"></i> Suspend
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-outline-success btn-sm" title="Activate customer account">
                                                    <i class="bi bi-check2-circle"></i> Activate
                                                </button>
                                            <?php endif; ?>
                                        </form>

                                        <!-- Dropdown Menu for More Options -->
                                        <div class="dropdown">
                                            <button class="btn btn-light border btn-sm" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                <li>
                                                    <a class="dropdown-item" href="<?php echo url('/admin/orders.php?search=' . urlencode($cust['email'])); ?>">
                                                        <i class="bi bi-bag-check me-2 text-primary"></i> View Orders (<?php echo (int)$cust['orders_count']; ?>)
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" action="<?php echo url('/admin/customers.php'); ?>">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="set_status">
                                                        <input type="hidden" name="user_id" value="<?php echo (int)$cust['id']; ?>">
                                                        <input type="hidden" name="status" value="pending_verification">
                                                        <button type="submit" class="dropdown-item text-warning">
                                                            <i class="bi bi-clock-history me-2"></i> Mark Pending Verification
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 pb-3">
                <?php echo render_admin_pagination($pagination, url('/admin/customers.php'), ['search' => $search, 'status' => $status]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
