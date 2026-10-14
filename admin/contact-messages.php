<?php
/**
 * ShopSphere - Customer Inquiries & Helpdesk
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

// Handle Status & Response Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $msgId = (int)($_POST['message_id'] ?? 0);

    if ($msgId <= 0) {
        flash('error', 'Invalid message identifier.');
        redirect(url('/admin/contact-messages.php'));
    }

    $stmt = $db->prepare("SELECT * FROM contact_messages WHERE id = :id");
    $stmt->execute(['id' => $msgId]);
    $msg = $stmt->fetch();

    if (!$msg) {
        flash('error', 'Inquiry message not found.');
        redirect(url('/admin/contact-messages.php'));
    }

    if ($action === 'set_status') {
        $newStatus = $_POST['status'] ?? '';
        if (in_array($newStatus, ['new', 'read', 'replied', 'archived'], true)) {
            $db->prepare("UPDATE contact_messages SET status = :s WHERE id = :id")->execute(['s' => $newStatus, 'id' => $msgId]);
            log_audit($db, 'update_contact_message_status', 'contact_messages', $msgId, ['status' => $msg['status']], ['status' => $newStatus]);
            flash('success', "Message status marked as {$newStatus}.");
        }
        redirect(url('/admin/contact-messages.php'));

    } elseif ($action === 'reply_note') {
        $notes = trim($_POST['admin_notes'] ?? '');
        $stmt = $db->prepare("
            UPDATE contact_messages 
            SET admin_notes = :notes, status = 'replied', replied_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute(['notes' => $notes, 'id' => $msgId]);

        log_audit($db, 'reply_contact_message', 'contact_messages', $msgId, null, ['admin_notes' => $notes]);
        flash('success', 'Helpdesk note recorded and message marked as replied.');
        redirect(url('/admin/contact-messages.php'));

    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM contact_messages WHERE id = :id")->execute(['id' => $msgId]);
        log_audit($db, 'delete_contact_message', 'contact_messages', $msgId);
        flash('success', 'Message deleted.');
        redirect(url('/admin/contact-messages.php'));
    }
}

// Search & Filter Parameters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = ["1=1"];
$params = [];

if ($status !== '' && in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
    $where[] = "status = :status";
    $params['status'] = $status;
}

if ($search !== '') {
    $where[] = "(name LIKE :search OR email LIKE :search OR subject LIKE :search OR message LIKE :search)";
    $params['search'] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);

// Total Counts
$countStmt = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE {$whereClause}");
$countStmt->execute($params);
$totalMessages = (int)$countStmt->fetchColumn();

$pagination = paginate($totalMessages, $perPage, $page);

// Fetch Messages
$stmt = $db->prepare("
    SELECT * 
    FROM contact_messages 
    WHERE {$whereClause} 
    ORDER BY CASE WHEN status = 'new' THEN 0 WHEN status = 'read' THEN 1 ELSE 2 END, created_at DESC 
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue(":{$k}", $v);
}
$stmt->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll();

// Overview Counts
$statNew = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn();
$statRead = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'read'")->fetchColumn();
$statReplied = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'replied'")->fetchColumn();
$statArchived = (int)$db->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'archived'")->fetchColumn();

$page_title = 'Helpdesk Inquiries';
$current_page = 'messages';
require_once __DIR__ . '/header.php';
?>

<!-- Metrics Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon red"><i class="bi bi-envelope-exclamation"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statNew); ?></h3>
                <p>New Unread Inquiries</p>
                <?php if ($statNew > 0): ?>
                    <div class="small text-danger fw-semibold"><i class="bi bi-bell"></i> Requires customer response</div>
                <?php else: ?>
                    <div class="small text-ss-muted">All inquiries reviewed</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon yellow"><i class="bi bi-envelope-open"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statRead); ?></h3>
                <p>Read / In Progress</p>
                <div class="small text-ss-muted">Under investigation</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon green"><i class="bi bi-reply-all"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statReplied); ?></h3>
                <p>Replied & Resolved</p>
                <div class="trend up"><i class="bi bi-check2"></i> Customer contacted</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="ss-stat-card">
            <div class="ss-stat-icon blue"><i class="bi bi-archive"></i></div>
            <div class="ss-stat-info">
                <h3><?php echo number_format($statArchived); ?></h3>
                <p>Archived Tickets</p>
                <div class="small text-ss-muted">Historical records</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="ss-card mb-4">
    <div class="ss-card-body p-3">
        <form method="GET" action="<?php echo url('/admin/contact-messages.php'); ?>" class="row g-3 align-items-center">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control" placeholder="Search inquiry text, subject, name, or customer email..." value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="new" <?php echo $status === 'new' ? 'selected' : ''; ?>>New Only (<?php echo $statNew; ?>)</option>
                    <option value="read" <?php echo $status === 'read' ? 'selected' : ''; ?>>Read Only</option>
                    <option value="replied" <?php echo $status === 'replied' ? 'selected' : ''; ?>>Replied</option>
                    <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-ss-primary flex-grow-1"><i class="bi bi-funnel"></i> Apply Filter</button>
                <?php if ($search !== '' || $status !== ''): ?>
                    <a href="<?php echo url('/admin/contact-messages.php'); ?>" class="btn btn-light border"><i class="bi bi-x-circle"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Inquiries Table -->
<div class="ss-card">
    <div class="ss-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Helpdesk Inquiries (<?php echo number_format($totalMessages); ?>)</h5>
    </div>
    <div class="ss-card-body p-0">
        <?php if (empty($messages)): ?>
            <div class="p-5 text-center text-ss-muted">
                <i class="bi bi-envelope fs-1 d-block mb-2"></i>
                No messages found.
            </div>
        <?php else: ?>
            <div class="ss-table-responsive">
                <table class="ss-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Sender & Date</th>
                            <th>Subject</th>
                            <th>Message Preview</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $m): ?>
                            <tr>
                                <td>
                                    <strong class="d-block text-dark"><?php echo e($m['name']); ?></strong>
                                    <div><a href="mailto:<?php echo e($m['email']); ?>" class="small"><?php echo e($m['email']); ?></a></div>
                                    <div class="small text-ss-muted"><?php echo format_date($m['created_at'], 'M d, Y h:i A'); ?></div>
                                </td>
                                <td>
                                    <strong class="text-dark"><?php echo e($m['subject']); ?></strong>
                                    <?php if (!empty($m['replied_at'])): ?>
                                        <div class="small text-success"><i class="bi bi-check2"></i> Replied <?php echo format_date($m['replied_at'], 'M d'); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 320px;">
                                    <p class="small mb-0 text-dark"><?php echo truncate(e($m['message']), 90); ?></p>
                                    <?php if (!empty($m['admin_notes'])): ?>
                                        <div class="small text-ss-muted fst-italic mt-1">
                                            <strong>Note:</strong> <?php echo truncate(e($m['admin_notes']), 50); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ss-badge ss-badge-<?php echo match($m['status']) {
                                        'new' => 'danger',
                                        'read' => 'warning',
                                        'replied' => 'success',
                                        'archived' => 'default',
                                        default => 'default'
                                    }; ?>">
                                        <?php echo ucfirst($m['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <!-- View & Respond Modal Trigger -->
                                        <button type="button" class="btn btn-ss-outline btn-sm" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $m['id']; ?>" title="View Message Details">
                                            <i class="bi bi-eye"></i> View
                                        </button>

                                        <!-- Mark Read Quick Action -->
                                        <?php if ($m['status'] === 'new'): ?>
                                            <form method="POST" action="<?php echo url('/admin/contact-messages.php'); ?>" class="d-inline">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                                <input type="hidden" name="status" value="read">
                                                <button type="submit" class="btn btn-light border btn-sm" title="Mark as Read">
                                                    <i class="bi bi-envelope-open"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Delete Message -->
                                        <form method="POST" action="<?php echo url('/admin/contact-messages.php'); ?>" onsubmit="return confirm('Delete this inquiry?');" class="d-inline">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Message Detail & Reply Modal -->
                                    <div class="modal fade text-start" id="viewModal<?php echo $m['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <form method="POST" action="<?php echo url('/admin/contact-messages.php'); ?>">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="reply_note">
                                                    <input type="hidden" name="message_id" value="<?php echo (int)$m['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Inquiry from <?php echo e($m['name']); ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row g-3 mb-3">
                                                            <div class="col-md-6">
                                                                <label class="text-ss-muted small fw-bold">Customer Email</label>
                                                                <div>
                                                                    <a href="mailto:<?php echo e($m['email']); ?>?subject=Re:%20<?php echo urlencode($m['subject']); ?>" class="fw-bold">
                                                                        <i class="bi bi-envelope-at"></i> <?php echo e($m['email']); ?>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="text-ss-muted small fw-bold">Submission Timestamp</label>
                                                                <div><?php echo format_date($m['created_at'], 'l, F j, Y \a\t h:i A'); ?></div>
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="text-ss-muted small fw-bold">Subject Line</label>
                                                                <div class="fw-bold fs-6"><?php echo e($m['subject']); ?></div>
                                                            </div>
                                                        </div>

                                                        <div class="p-3 bg-light rounded border mb-4">
                                                            <label class="text-ss-muted small fw-bold text-uppercase d-block mb-2">Message Body</label>
                                                            <div class="text-dark" style="white-space: pre-wrap; font-size: 0.95rem;"><?php echo e($m['message']); ?></div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Admin Internal Notes & Resolution Summary</label>
                                                            <textarea name="admin_notes" class="form-control" rows="3" placeholder="Enter resolution details or notes on how this was handled..."><?php echo e($m['admin_notes'] ?? ''); ?></textarea>
                                                            <div class="form-text small">Saving will mark ticket as Replied & Resolved.</div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <a href="mailto:<?php echo e($m['email']); ?>?subject=Re:%20<?php echo urlencode($m['subject']); ?>" class="btn btn-outline-primary">
                                                            <i class="bi bi-envelope-paper"></i> Open Mail Client
                                                        </a>
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-ss-primary">Save Notes & Mark Replied</button>
                                                    </div>
                                                </form>
                                            </div>
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
                <?php echo render_admin_pagination($pagination, url('/admin/contact-messages.php'), ['search' => $search, 'status' => $status]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
