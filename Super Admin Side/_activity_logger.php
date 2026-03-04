<?php
/**
 * Central activity logging helper (MySQL-backed).
 */
require_once dirname(__DIR__) . '/db.php';

if (!function_exists('activityLog')) {
    function activityLog($config, $action, $details = [], $status = 'success', $actor = []) {
        $action = trim((string)$action);
        if ($action === '') {
            return false;
        }

        $actorId = trim((string)($actor['id'] ?? ($_SESSION['user_id'] ?? '')));
        $actorEmail = trim((string)($actor['email'] ?? ($_SESSION['user_email'] ?? '')));
        $actorName = trim((string)($actor['name'] ?? ($_SESSION['user_name'] ?? $actorEmail)));
        $actorRole = strtolower(trim((string)($actor['role'] ?? ($_SESSION['user_role'] ?? 'guest'))));
        if ($actorName === '') {
            $actorName = $actorEmail !== '' ? $actorEmail : 'Unknown';
        }

        $module = trim((string)($details['module'] ?? ''));
        if ($module === '') {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $module = $script !== '' ? basename(dirname($script)) : 'app';
        }

        $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $status = strtolower(trim((string)$status));
        if ($status === '') {
            $status = 'success';
        }

        $safeDetails = [];
        if (is_array($details)) {
            foreach ($details as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                if (is_scalar($v) || $v === null) {
                    $safeDetails[$k] = (string)$v;
                } elseif (is_array($v)) {
                    $safeDetails[$k] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        try {
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare(
                'INSERT INTO activity_logs
                    (action, status, module, details, actor_id, actor_name, actor_email, actor_role, ip_address, user_agent, created_at)
                 VALUES
                    (:action, :status, :module, :details, :actor_id, :actor_name, :actor_email, :actor_role, :ip_address, :user_agent, :created_at)'
            );
            $stmt->execute([
                ':action' => $action,
                ':status' => $status,
                ':module' => $module,
                ':details' => json_encode($safeDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':actor_id' => $actorId,
                ':actor_name' => $actorName,
                ':actor_email' => $actorEmail,
                ':actor_role' => $actorRole,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':created_at' => dbNowUtcString(),
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('getActivityLogs')) {
    function activityLogHumanize($text) {
        $text = trim((string)$text);
        if ($text === '') return '';
        $text = str_replace(['_', '-'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?: $text;
        return ucwords($text);
    }

    function activityRoleText($role) {
        $r = strtolower(trim((string)$role));
        $map = [
            'superadmin' => 'Super Admin',
            'admin' => 'Admin',
            'departmenthead' => 'Department Head',
            'department_head' => 'Department Head',
            'dept_head' => 'Department Head',
            'staff' => 'Front Desk',
            'user' => 'User',
            'guest' => 'Unknown User',
        ];
        return $map[$r] ?? activityLogHumanize($r);
    }

    function activityModuleText($module) {
        $m = strtolower(trim((string)$module));
        $map = [
            'auth' => 'Sign-In & Access',
            'super_admin_users' => 'User Management',
            'super_admin_documents' => 'Super Admin Documents',
            'super_admin_offices' => 'Department Management',
            'admin_documents' => 'Admin Documents',
            'admin_offices' => 'Admin Office Management',
            'front_desk_documents' => 'Front Desk Documents',
        ];
        return $map[$m] ?? activityLogHumanize($m);
    }

    function activityStatusText($status) {
        $s = strtolower(trim((string)$status));
        $map = [
            'success' => 'Success',
            'blocked' => 'Blocked',
            'failed' => 'Failed',
            'error' => 'Error',
        ];
        return $map[$s] ?? activityLogHumanize($s);
    }

    function activityReasonText($reason) {
        $r = strtolower(trim((string)$reason));
        $map = [
            'account_disabled' => 'Account is disabled',
            'account_suspended' => 'Account is suspended',
            'google_email_not_authorized' => 'Google account is not authorized',
        ];
        return $map[$r] ?? $reason;
    }

    function activityLogActionText($action, $details) {
        $action = trim((string)$action);
        $d = is_array($details) ? $details : [];
        $targetName = trim((string)($d['target_name'] ?? $d['target_username'] ?? $d['target_email'] ?? ''));
        $docTitle = trim((string)($d['document_title'] ?? ''));
        $officeName = trim((string)($d['office_name'] ?? ''));
        $loginType = trim((string)($d['login_type'] ?? ''));

        switch ($action) {
            case 'login_success':
                if ($loginType === 'google_sso') return 'Signed in with Google';
                if (str_starts_with($loginType, 'manual')) return 'Signed in with email and password';
                return 'Signed in';
            case 'login_blocked':
                $reason = activityReasonText((string)($d['reason'] ?? ''));
                return $reason !== '' ? ('Sign-in blocked: ' . $reason) : 'Sign-in blocked';
            case 'logout':
                return 'Signed out';
            case 'google_otp_sent':
                return 'Sent Google sign-in verification code';
            case 'user_add':
                return $targetName !== '' ? ('Added user: ' . $targetName) : 'Added a user';
            case 'user_disable':
                return $targetName !== '' ? ('Disabled user: ' . $targetName) : 'Disabled a user';
            case 'user_suspend':
                return $targetName !== '' ? ('Suspended user: ' . $targetName) : 'Suspended a user';
            case 'user_enable':
                return $targetName !== '' ? ('Enabled user: ' . $targetName) : 'Enabled a user';
            case 'office_add':
                return $officeName !== '' ? ('Added office: ' . $officeName) : 'Added an office';
            case 'office_update':
                return $officeName !== '' ? ('Updated office: ' . $officeName) : 'Updated an office';
            case 'office_assign_head':
                return $officeName !== '' ? ('Assigned office head: ' . $officeName) : 'Assigned an office head';
            case 'office_delete':
                return $officeName !== '' ? ('Deleted office: ' . $officeName) : 'Deleted an office';
            case 'document_add':
                return $docTitle !== '' ? ('Added document: ' . $docTitle) : 'Added a document';
            case 'document_archive':
                return $docTitle !== '' ? ('Archived document: ' . $docTitle) : 'Archived a document';
            case 'document_send_to_admin':
                return $docTitle !== '' ? ('Sent document to admin: ' . $docTitle) : 'Sent document to admin';
            case 'document_send_to_super_admin':
                return $docTitle !== '' ? ('Sent document to super admin: ' . $docTitle) : 'Sent document to super admin';
            case 'document_send_to_department_heads':
                return $docTitle !== '' ? ('Sent document to department heads: ' . $docTitle) : 'Sent document to department heads';
            default:
                return 'Performed action: ' . activityLogHumanize($action);
        }
    }

    function activityBuildSqlFilter($search, $fromDate, $toDate, &$params) {
        $where = [];
        $params = [];
        $search = trim((string)$search);
        if ($search !== '') {
            $where[] = '(actor_name LIKE :search OR actor_email LIKE :search OR actor_role LIKE :search OR action LIKE :search OR module LIKE :search OR status LIKE :search OR JSON_UNQUOTE(JSON_EXTRACT(details, "$.reason")) LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $fromDate = trim((string)$fromDate);
        if ($fromDate !== '') {
            $fromTs = strtotime($fromDate . ' 00:00:00');
            if ($fromTs !== false) {
                $where[] = 'created_at >= :from_date';
                $params[':from_date'] = date('Y-m-d H:i:s', (int)$fromTs);
            }
        }
        $toDate = trim((string)$toDate);
        if ($toDate !== '') {
            $toTs = strtotime($toDate . ' 23:59:59');
            if ($toTs !== false) {
                $where[] = 'created_at <= :to_date';
                $params[':to_date'] = date('Y-m-d H:i:s', (int)$toTs);
            }
        }
        return empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
    }

    function activityRowsToViewRows($rows) {
        $result = [];
        foreach ($rows as $arr) {
            $createdFmt = '—';
            $createdTs = dbToTimestamp($arr['created_at'] ?? null);
            if ($createdTs !== null) {
                $createdFmt = (new DateTime('@' . $createdTs))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
            }

            $detailsRaw = $arr['details'] ?? '';
            $details = [];
            if (is_string($detailsRaw) && $detailsRaw !== '') {
                $decoded = json_decode($detailsRaw, true);
                if (is_array($decoded)) {
                    $details = $decoded;
                }
            } elseif (is_array($detailsRaw)) {
                $details = $detailsRaw;
            }

            $detailSummary = [];
            $keyLabels = [
                'reason' => 'Reason',
                'target_name' => 'Affected User',
                'target_username' => 'Affected Username',
                'target_email' => 'Affected Email',
                'target_role' => 'Affected Role',
                'duration_value' => 'Duration Value',
                'duration_unit' => 'Duration Unit',
                'office_name' => 'Office',
                'document_title' => 'Document',
                'document_code' => 'Document Code',
                'file_name' => 'File',
                'days' => 'Days',
                'target_count' => 'Recipients',
                'login_type' => 'Sign-In Method',
            ];
            foreach ($details as $k => $v) {
                if ($v === '' || $k === 'module') continue;
                if (str_ends_with((string)$k, '_id')) continue;
                $label = $keyLabels[$k] ?? activityLogHumanize((string)$k);
                $value = (string)$v;
                if ($k === 'reason') $value = activityReasonText($value);
                if ($k === 'login_type') {
                    if ($value === 'google_sso' || $value === 'google_sso_otp') $value = 'Google Sign-In';
                    elseif ($value === 'manual_admin' || $value === 'manual_user') $value = 'Email and Password';
                }
                $detailSummary[] = $label . ': ' . $value;
            }
            $module = trim((string)($arr['module'] ?? ''));
            if ($module === '') $module = 'app';
            $status = trim((string)($arr['status'] ?? 'success'));
            if ($status === '') $status = 'success';

            $result[] = [
                'actor_name' => trim((string)($arr['actor_name'] ?? 'Unknown')),
                'actor_role' => trim((string)($arr['actor_role'] ?? '')),
                'actor_role_text' => activityRoleText((string)($arr['actor_role'] ?? '')),
                'action' => trim((string)($arr['action'] ?? '')),
                'action_text' => activityLogActionText((string)($arr['action'] ?? ''), $details),
                'module' => $module,
                'module_text' => activityModuleText($module),
                'status' => $status,
                'status_text' => activityStatusText($status),
                'ip_address' => trim((string)($arr['ip_address'] ?? '')),
                'created_at_formatted' => $createdFmt,
                'details_summary' => implode(' | ', $detailSummary),
            ];
        }
        return $result;
    }

    function getActivityLogs($config, $search = '', $fromDate = '', $toDate = '', $limit = 500) {
        $limit = (int)$limit;
        if ($limit <= 0) $limit = 500;
        if ($limit > 2000) $limit = 2000;

        try {
            $pdo = dbPdo($config);
            $params = [];
            $where = activityBuildSqlFilter($search, $fromDate, $toDate, $params);
            $sql = 'SELECT * FROM activity_logs ' . $where . ' ORDER BY created_at DESC LIMIT ' . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return activityRowsToViewRows($stmt->fetchAll());
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('getActivityLogsPage')) {
    function getActivityLogsPage($config, $search = '', $fromDate = '', $toDate = '', $page = 1, $perPage = 20) {
        $page = (int)$page;
        if ($page <= 0) $page = 1;
        $perPage = (int)$perPage;
        if ($perPage <= 0) $perPage = 20;
        if ($perPage > 100) $perPage = 100;
        $skip = ($page - 1) * $perPage;

        try {
            $pdo = dbPdo($config);
            $params = [];
            $where = activityBuildSqlFilter($search, $fromDate, $toDate, $params);
            $countSql = 'SELECT COUNT(*) AS total_count FROM activity_logs ' . $where;
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $countRow = $countStmt->fetch();
            $total = (int)($countRow['total_count'] ?? 0);

            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
                $skip = ($page - 1) * $perPage;
            }

            $sql = 'SELECT * FROM activity_logs ' . $where . ' ORDER BY created_at DESC LIMIT :offset, :limit';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':offset', (int)$skip, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
            $stmt->execute();
            $rows = activityRowsToViewRows($stmt->fetchAll());

            return [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
            ];
        } catch (Exception $e) {
            return [
                'rows' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => $perPage,
                'total_pages' => 1,
            ];
        }
    }
}
