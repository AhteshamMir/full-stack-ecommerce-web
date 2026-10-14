<?php
/**
 * ShopSphere - Admin Panel Helper Functions
 */

declare(strict_types=1);

if (!function_exists('log_audit')) {
    /**
     * Record an administrative activity in the audit_logs table.
     *
     * @param PDO $db Active PDO connection
     * @param string $action Action key (e.g., 'approve_seller', 'toggle_customer_status')
     * @param string $entityType Entity model/table name (e.g., 'users', 'seller_profiles')
     * @param int|null $entityId Affected record primary ID
     * @param array|null $oldValues Previous snapshot values
     * @param array|null $newValues Updated snapshot values
     * @param int|null $userId Admin user performing the action
     * @return bool
     */
    function log_audit(
        PDO $db,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): bool {
        try {
            $userId = $userId ?? get_current_user_id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Agent', 0, 255);

            $stmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (:user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, :user_agent, NOW())
            ");

            return $stmt->execute([
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_values'  => $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                'new_values'  => $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $ip,
                'user_agent'  => $ua,
            ]);
        } catch (Throwable $e) {
            error_log('[ShopSphere Audit Log Error] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('render_admin_pagination')) {
    /**
     * Render a clean pagination block for admin tables.
     *
     * @param array $p Pagination array from paginate()
     * @param string $baseUrl Base URL path
     * @param array $params Query parameters to preserve
     * @return string
     */
    function render_admin_pagination(array $p, string $baseUrl, array $params = []): string
    {
        if ($p['total_pages'] <= 1) {
            return '';
        }

        unset($params['page']);

        $buildUrl = function(int $page) use ($baseUrl, $params): string {
            $query = array_merge($params, ['page' => $page]);
            return e($baseUrl . '?' . http_build_query($query));
        };

        $html = '<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mt-4 pt-2 border-top">';
        $html .= '<div class="text-ss-muted small">';
        $html .= 'Showing <strong>' . $p['from'] . '</strong> to <strong>' . $p['to'] . '</strong> of <strong>' . $p['total'] . '</strong> results';
        $html .= '</div>';

        $html .= '<nav aria-label="Page navigation">';
        $html .= '<ul class="pagination pagination-sm mb-0">';

        // Previous button
        if ($p['has_prev']) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl($p['prev_page']) . '"><i class="bi bi-chevron-left"></i> Prev</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link"><i class="bi bi-chevron-left"></i> Prev</span></li>';
        }

        // Page number links with sliding window
        $start = max(1, $p['current_page'] - 2);
        $end = min($p['total_pages'], $p['current_page'] + 2);

        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl(1) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $p['current_page']) {
                $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl($i) . '">' . $i . '</a></li>';
            }
        }

        if ($end < $p['total_pages']) {
            if ($end < $p['total_pages'] - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl($p['total_pages']) . '">' . $p['total_pages'] . '</a></li>';
        }

        // Next button
        if ($p['has_next']) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $buildUrl($p['next_page']) . '">Next <i class="bi bi-chevron-right"></i></a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Next <i class="bi bi-chevron-right"></i></span></li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';
        $html .= '</div>';

        return $html;
    }
}
