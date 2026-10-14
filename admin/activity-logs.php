<?php
/**
 * ShopSphere - System & Administrative Audit Logs
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

// Handle Log Cleanup Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'cleanup_logs') {
        $days = max(7, (int)($_POST['older_than_days'] ?? 30));
        $stmt = $db->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
        $stmt->execute(['days' => $days]);
        $deletedCount = $stmt->rowCount();

        log_audit($db, 'cleanup_audit_logs', 'audit_logs', null, null, ['days' => $days, 'deleted' => $deletedCount]);
        flash('success', "Audit log cleanup completed. Removed {$deletedCount} records older than {$days} days.");
        redirect(url('/admin/activity-logs.php'));
    }
}

// Search & Filter Parameters
$actionFilter = trim($_GET['action_filter'] ?? '');
$entityFilter = trim($_GET['entity_filter'] ?? '');
$userIdFilter = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = ["1=1"];
$params = [];

if ($actionFilter !== '') {
    $where[] = "al.action = :action";
    $params['action'] = $actionFilter;
}

if ($entityFilter !== '') {
    $where[] = "al.entity_type = :entity";
    $params['entity'] = $entityFilter;
}

if ($userIdFilter !== null && $userIdFilter > 0) {
    $where[] = "al.user_id = :uid";
    $params['uid'] = $userIdFilter;
}

if ($dateFrom !== '') {
    $where[] = "al.created_at >= :dfrom";
    $params['dfrom'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = "al.created_at <= :dto";
    $params['dto'] = $dateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// Total Logs Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$whereClause}");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();

$pagination = paginate($totalLogs, $perPage, $page);

// Fetch Paginated Logs
$query = "
    SELECT al.*, u.first_name, u.last_name, u.email, u.role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE {$whereClause}
    ORDER BY al.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Distinct Action Names and Entities for Dropdown
$distinctActions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$distinctEntities = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

// Stat Count
$totalAllLogs = (int)$db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

$page_title = 'System Audit Logs';
$current_page = 'logs';
require_once __DIR__ . '/header.php';
?>

<!-- Header with Log Purge Modal Trigger -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h2 class="h4 mb-1">Administrative Audit Trail</h2>
        <p class="text-ss-muted small mb-0">Immutable records of changes made across users, merchants, catalog, rules, and orders.</p>
    </div>
    <div>
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cleanupModal">
            <i class="bi bi-trash3"></i> Purge Old Logs
        </button>
    </div>
</div>

<!-- Filters Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/activity-logs.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-3">
                <select name="action_filter" class="form-select">
                    <option value="">All Actions</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?php echo e($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>>
                            <?php echo e(str_replace('_', ' ', $act)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="entity_filter" class="form-select">
                    <option value="">All Entities</option>
                    <?php foreach ($distinctEntities as $ent): ?>
                        <option value="<?php echo e($ent); ?>" <?php echo $entityFilter === $ent ? 'selected' : ''; ?>>
                            <?php echo e($ent); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>" placeholder="From">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>" placeholder="To">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <?php if ($actionFilter !== '' || $entityFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="<?php echo url('/admin/activity-logs.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Audit Logs Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Audit Records (<?php echo number_format($totalLogs); ?> / <?php echo number_format($totalAllLogs); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-journal-text fs-1 d-block mb-2"></i>
                No audit log records found for the specified filters.
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User / Operator</th>
                            <th>Action Performed</th>
                            <th>Entity Target</th>
                            <th>IP Address</th>
                            <th class="text-end">Payload Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $lg): ?>
                            <tr>
                                <td style="width: 170px;">
                                    <strong><?php echo format_date($lg['created_at'], 'M d, Y'); ?></strong>
                                    <div class="small text-ss-muted"><?php echo format_date($lg['created_at'], 'h:i:s A'); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($lg['first_name'])): ?>
                                        <strong class="d-block text-dark"><?php echo e($lg['first_name'] . ' ' . $lg['last_name']); ?></strong>
                                        <span class="badge bg-light text-dark border" style="font-size: 10px;"><?php echo e($lg['role'] ?? 'user'); ?></span>
                                    <?php else: ?>
                                        <span class="text-ss-muted fst-italic">System Daemon</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary text-white font-monospace">
                                        <?php echo e($lg['action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo e($lg['entity_type']); ?></strong>
                                    <?php if ($lg['entity_id']): ?>
                                        <span class="text-ss-muted">#<?php echo (int)$lg['entity_id']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="small"><?php echo e($lg['ip_address'] ?? '127.0.0.1'); ?></code>
                                </td>
                                <td class="text-end">
                                    <?php if (!empty($lg['old_values']) || !empty($lg['new_values'])): ?>
                                        <button type="button" class="btn btn-light border btn-sm" data-bs-toggle="modal" data-bs-target="#payloadModal<?php echo $lg['id']; ?>">
                                            <i class="bi bi-code-slash"></i> Inspect Diff
                                        </button>

                                        <!-- Payload Diff Modal -->
                                        <div class="modal fade text-start" id="payloadModal<?php echo $lg['id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            Log #<?php echo $lg['id']; ?>: <?php echo e($lg['action']); ?> on <?php echo e($lg['entity_type']); ?> #<?php echo (int)$lg['entity_id']; ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <div class="small text-ss-muted mb-1">User Agent</div>
                                                            <code><?php echo e($lg['user_agent'] ?? 'Unknown'); ?></code>
                                                        </div>
                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <h6 class="text-danger"><i class="bi bi-dash-circle"></i> Old Snapshot Values</h6>
                                                                <pre class="bg-light p-3 rounded border small" style="max-height: 250px; overflow-y: auto;"><code><?php 
                                                                    echo e($lg['old_values'] ? json_encode(json_decode($lg['old_values']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'None');
                                                                ?></code></pre>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <h6 class="text-success"><i class="bi bi-plus-circle"></i> New Snapshot Values</h6>
                                                                <pre class="bg-light p-3 rounded border small" style="max-height: 250px; overflow-y: auto;"><code><?php 
                                                                    echo e($lg['new_values'] ? json_encode(json_decode($lg['new_values']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'None');
                                                                ?></code></pre>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="small text-ss-muted">No state payload</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="px-4 pb-3">
                <?php echo render_admin_pagination($pagination, url('/admin/activity-logs.php'), [
                    'action_filter' => $actionFilter,
                    'entity_filter' => $entityFilter,
                    'user_id'       => $userIdFilter,
                    'date_from'     => $dateFrom,
                    'date_to'       => $dateTo,
                ]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Purge Old Logs -->
<div class="modal fade text-start" id="cleanupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('/admin/activity-logs.php'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="cleanup_logs">
                <div class="modal-header">
                    <h5 class="modal-title">Purge Historical Audit Logs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger small">
                        <i class="bi bi-exclamation-triangle"></i> This operation will permanently delete older log entries to free up database storage.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Delete records older than</label>
                        <select name="older_than_days" class="form-select" required>
                            <option value="30">30 Days</option>
                            <option value="60">60 Days</option>
                            <option value="90" selected>90 Days (Recommended)</option>
                            <option value="180">180 Days (6 Months)</option>
                            <option value="365">365 Days (1 Year)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Log Purge</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
