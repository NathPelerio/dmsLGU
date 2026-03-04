<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
$sidebar_active = 'users';
$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
$userId = trim($_GET['id'] ?? '');
if ($userId === '') {
    header('Location: users.php');
    exit;
}
require_once __DIR__ . '/_account_helpers.php';
require_once __DIR__ . '/_activity_logger.php';
$welcomeUsername = getUserUsername($_SESSION['user_id'] ?? '') ?: ($_SESSION['user_username'] ?? $_SESSION['user_name'] ?? 'User');

$user = null;
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch() ?: null;
} catch (Exception $e) {
    $user = null;
}
if (!$user) {
    header('Location: users.php?msg=' . urlencode('User not found') . '&ok=0');
    exit;
}
$displayName = trim($user['name'] ?? '') ?: (trim($user['username'] ?? '') ?: trim($user['email'] ?? 'Unknown'));
$msg = $_GET['msg'] ?? null;
$msgOk = isset($_GET['ok']) && $_GET['ok'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $username = trim((string)($_POST['username'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $role = strtolower(trim((string)($_POST['role'] ?? 'user')));
    $newPassword = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $allowedRoles = ['superadmin', 'admin', 'user', 'staff', 'departmenthead', 'department_head', 'dept_head'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'user';
    }

    if ($username === '' || $email === '') {
        header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Username and email are required.') . '&ok=0');
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Please enter a valid email address.') . '&ok=0');
        exit;
    }
    if ($newPassword !== '' && !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $newPassword)) {
        header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Password must be at least 8 characters and include uppercase, lowercase, number, and special character.') . '&ok=0');
        exit;
    }
    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Password and retype password do not match.') . '&ok=0');
        exit;
    }

    try {
        $pdo = dbPdo($config);
        $check = $pdo->prepare('SELECT id FROM users WHERE (username = :username OR email = :email) AND id <> :id LIMIT 1');
        $check->execute([
            ':username' => $username,
            ':email' => $email,
            ':id' => $userId,
        ]);
        if ($check->fetch()) {
            header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Username or email already exists.') . '&ok=0');
            exit;
        }

        if ($newPassword !== '') {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET username = :username, name = :name, email = :email, role = :role,
                     password = :password, updated_at = :updated_at
                 WHERE id = :id'
            );
            $ok = $stmt->execute([
                ':username' => $username,
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':updated_at' => dbNowUtcString(),
                ':id' => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users
                 SET username = :username, name = :name, email = :email, role = :role, updated_at = :updated_at
                 WHERE id = :id'
            );
            $ok = $stmt->execute([
                ':username' => $username,
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':updated_at' => dbNowUtcString(),
                ':id' => $userId,
            ]);
        }

        if (!$ok) {
            header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Failed to update user.') . '&ok=0');
            exit;
        }

        activityLog($config, 'user_edit', [
            'module' => 'super_admin_users',
            'target_user_id' => $userId,
            'target_name' => $name !== '' ? $name : $username,
            'target_username' => $username,
            'target_email' => $email,
            'target_role' => $role,
            'password_changed' => $newPassword !== '' ? 'yes' : 'no',
        ]);

        header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('User updated successfully.') . '&ok=1');
        exit;
    } catch (Exception $e) {
        header('Location: edit_user.php?id=' . urlencode($userId) . '&msg=' . urlencode('Error: ' . $e->getMessage()) . '&ok=0');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User – DMS LGU</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="sidebar_super_admin.css">
    <link rel="stylesheet" href="../Admin Side/admin-dashboard.css">
    <style>
        body { margin: 0; background: #f8fafc; }
        .main-content { padding: 1.5rem 2rem; }
        .edit-user-wrap { max-width: 760px; }
        .edit-user-card {
            background: #fff;
            border-radius: 14px;
            padding: 1.2rem 1.2rem 1.4rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .edit-user-head h2 { margin: 0; font-size: 1.25rem; color: #1e293b; }
        .edit-user-subtitle { margin: 6px 0 0; color: #64748b; font-size: 0.9rem; }
        .edit-user-form { margin-top: 1rem; display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .field { display: grid; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        .field label { font-size: 13px; color: #334155; font-weight: 600; }
        .field input, .field select {
            width: 100%;
            height: 40px;
            border: 1px solid #dbe2ea;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            color: #0f172a;
            background: #fff;
            box-sizing: border-box;
        }
        .field input:focus, .field select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }
        .password-input-wrap { position: relative; }
        .password-input-wrap input { padding-right: 44px; }
        .password-toggle-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #64748b;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            cursor: pointer;
        }
        .password-toggle-btn:hover { background: #f1f5f9; color: #1e293b; }
        .password-toggle-btn svg { width: 18px; height: 18px; }
        .password-help { margin: 0; color: #64748b; font-size: 12px; line-height: 1.4; }
        .password-strength-msg { margin: -2px 0 0; font-size: 12px; font-weight: 600; }
        .password-strength-msg.weak { color: #dc2626; }
        .password-strength-msg.strong { color: #166534; }
        .password-match-msg { margin: -2px 0 0; font-size: 12px; font-weight: 600; }
        .password-match-msg.match { color: #166534; }
        .password-match-msg.mismatch { color: #dc2626; }
        .form-actions { grid-column: 1 / -1; margin-top: 6px; display: flex; justify-content: flex-end; gap: 10px; }
        .btn {
            border: 1px solid transparent;
            border-radius: 10px;
            min-height: 40px;
            padding: 0 14px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-secondary { background: #f8fafc; color: #334155; border-color: #e2e8f0; }
        .btn-primary { background: #2563eb; color: #fff; }
        .edit-toast {
            margin-top: 0.85rem;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 0.9rem;
        }
        .edit-toast.ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .edit-toast.err { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        @media (max-width: 720px) {
            .main-content { padding: 1rem; }
            .edit-user-form { grid-template-columns: 1fr; }
            .edit-user-card { padding: 1rem; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include __DIR__ . '/_sidebar_super_admin.php'; ?>
        <div class="main-content">
            <div class="edit-user-wrap">
                <div class="edit-user-card">
                    <div class="edit-user-head">
                        <h2>Edit User</h2>
                        <p class="edit-user-subtitle">Update account profile and role for <?= htmlspecialchars($displayName) ?>.</p>
                    </div>
                    <?php if ($msg !== null): ?>
                    <div class="edit-toast <?= $msgOk ? 'ok' : 'err' ?>"><?= htmlspecialchars($msg) ?></div>
                    <?php endif; ?>
                    <form method="post" class="edit-user-form" id="edit-user-form">
                        <input type="hidden" name="action" value="update_user">
                        <div class="field">
                            <label for="edit-username">Username <span class="required">*</span></label>
                            <input type="text" id="edit-username" name="username" required value="<?= htmlspecialchars($user['username'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="edit-name">Name</label>
                            <input type="text" id="edit-name" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="edit-email">Email <span class="required">*</span></label>
                            <input type="email" id="edit-email" name="email" required value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="edit-role">Role</label>
                            <select id="edit-role" name="role">
                                <?php $roleVal = strtolower(trim((string)($user['role'] ?? 'user'))); ?>
                                <option value="user" <?= $roleVal === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="admin" <?= $roleVal === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="superadmin" <?= $roleVal === 'superadmin' ? 'selected' : '' ?>>Super Admin</option>
                                <option value="staff" <?= $roleVal === 'staff' ? 'selected' : '' ?>>Staff</option>
                                <option value="departmenthead" <?= in_array($roleVal, ['departmenthead', 'department_head', 'dept_head'], true) ? 'selected' : '' ?>>Department Head</option>
                            </select>
                        </div>
                        <div class="field full">
                            <label for="edit-password">New Password (optional)</label>
                            <div class="password-input-wrap">
                                <input type="password" id="edit-password" name="password" placeholder="Leave blank to keep current password" autocomplete="new-password">
                                <button type="button" class="password-toggle-btn js-toggle-password" data-target="edit-password" aria-label="Show password" aria-pressed="false">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <small class="password-help">Use at least 8 characters with uppercase, lowercase, number, and special character.</small>
                            <p class="password-strength-msg" id="password-strength-msg" hidden></p>
                        </div>
                        <div class="field full">
                            <label for="edit-confirm-password">Retype New Password</label>
                            <div class="password-input-wrap">
                                <input type="password" id="edit-confirm-password" name="confirm_password" placeholder="Retype new password" autocomplete="new-password">
                                <button type="button" class="password-toggle-btn js-toggle-password" data-target="edit-confirm-password" aria-label="Show password" aria-pressed="false">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <p class="password-match-msg" id="password-match-msg" hidden></p>
                        </div>
                        <div class="form-actions">
                            <a href="users.php" class="btn btn-secondary">Back</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var form = document.getElementById('edit-user-form');
        var pwd = document.getElementById('edit-password');
        var confirmPwd = document.getElementById('edit-confirm-password');
        var strengthMsg = document.getElementById('password-strength-msg');
        var matchMsg = document.getElementById('password-match-msg');

        function isStrongPassword(value) {
            return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(value || '');
        }
        function updatePasswordStrengthStatus() {
            if (!strengthMsg || !pwd) return;
            var value = pwd.value || '';
            if (!value) {
                strengthMsg.hidden = true;
                strengthMsg.textContent = '';
                strengthMsg.classList.remove('weak', 'strong');
                return;
            }
            strengthMsg.hidden = false;
            if (isStrongPassword(value)) {
                strengthMsg.textContent = 'Password strength: Strong';
                strengthMsg.classList.add('strong');
                strengthMsg.classList.remove('weak');
            } else {
                strengthMsg.textContent = 'Password strength: Weak';
                strengthMsg.classList.add('weak');
                strengthMsg.classList.remove('strong');
            }
        }
        function updatePasswordMatchStatus() {
            if (!matchMsg || !pwd || !confirmPwd) return;
            if (!confirmPwd.value) {
                matchMsg.hidden = true;
                matchMsg.textContent = '';
                matchMsg.classList.remove('match', 'mismatch');
                return;
            }
            matchMsg.hidden = false;
            if (pwd.value === confirmPwd.value) {
                matchMsg.textContent = 'Passwords match.';
                matchMsg.classList.add('match');
                matchMsg.classList.remove('mismatch');
            } else {
                matchMsg.textContent = 'Passwords do not match.';
                matchMsg.classList.add('mismatch');
                matchMsg.classList.remove('match');
            }
        }

        if (pwd) {
            pwd.addEventListener('input', updatePasswordStrengthStatus);
            pwd.addEventListener('input', updatePasswordMatchStatus);
        }
        if (confirmPwd) {
            confirmPwd.addEventListener('input', updatePasswordMatchStatus);
        }
        document.querySelectorAll('.js-toggle-password').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetId = btn.getAttribute('data-target');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;
                var isVisible = input.type === 'text';
                input.type = isVisible ? 'password' : 'text';
                btn.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                btn.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            });
        });
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!pwd || !confirmPwd) return;
                var pwdValue = pwd.value || '';
                if (pwdValue !== '' && !isStrongPassword(pwdValue)) {
                    e.preventDefault();
                    alert('Password must be at least 8 characters and include uppercase, lowercase, number, and special character.');
                    return;
                }
                if (pwdValue !== '' && pwdValue !== confirmPwd.value) {
                    e.preventDefault();
                    alert('Password and retype password do not match.');
                    return;
                }
            });
        }
    })();
    </script>
    <script src="sidebar_super_admin.js"></script>
</body>
</html>
