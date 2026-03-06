<?php
session_start();

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once dirname(__DIR__) . '/Super Admin Side/_activity_logger.php';
require_once __DIR__ . '/_trusted_device.php';

function roleRedirectPath($role) {
    if ($role === 'superadmin') {
        return '../Super%20Admin%20Side/dashboard.php';
    } elseif ($role === 'admin') {
        return '../Admin%20Side/admin_dashboard.php';
    } elseif (in_array($role, ['departmenthead', 'department_head', 'dept_head'])) {
        return '../Department%20Heads%20Side/department_dashboard.php';
    } else {
        return '../Front%20Desk%20Side/staff_dashboard.php';
    }
}

function redirectByRole($role) {
    header('Location: ' . roleRedirectPath($role));
    exit;
}

function sendTrustedLoginConfirmationEmail($toEmail, $confirmUrl, $denyUrl, $config, $displayName = '', $expiryMinutes = 10) {
    $expiryMinutes = max(1, (int)$expiryMinutes);
    $subject = 'DMS LGU Solano - Confirm your sign-in';
    $nameLine = trim($displayName) !== '' ? trim($displayName) : 'User';
    $plainMessage = "Good day, {$nameLine}.\n\n"
        . "We noticed a sign-in attempt to your DMS LGU Solano account from a remembered device.\n"
        . "Please confirm this login by opening this link:\n"
        . $confirmUrl . "\n\n"
        . "If this was not you, secure your account by opening this link:\n"
        . $denyUrl . "\n\n"
        . "This confirmation link expires in {$expiryMinutes} minute(s).\n"
        . "If you are unsure, contact your system administrator immediately.\n\n"
        . "Regards,\n"
        . "DMS LGU Solano";
    $safeName = htmlspecialchars($nameLine, ENT_QUOTES, 'UTF-8');
    $safeConfirmUrl = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');
    $safeDenyUrl = htmlspecialchars($denyUrl, ENT_QUOTES, 'UTF-8');
    $buttonBaseStyle = 'display:inline-block;min-width:220px;padding:12px 18px;text-decoration:none;border-radius:12px;font-weight:700;text-align:center;font-family:Arial,sans-serif;';
    $confirmStyle = $buttonBaseStyle . 'background:#ffd400;color:#0b2545;border:1px solid #eab308;';
    $denyStyle = $buttonBaseStyle . 'background:#0b2545;color:#ffd400;border:1px solid #ffd400;';
    $htmlMessage = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#0f172a;">'
        . '<p>Good day, ' . $safeName . '.</p>'
        . '<p>We noticed a sign-in attempt to your DMS LGU Solano account from a remembered device.</p>'
        . '<div style="text-align:center;margin:20px 0;">'
        . '<a href="' . $safeConfirmUrl . '" style="' . $confirmStyle . '">Confirm Login</a>'
        . '</div>'
        . '<div style="text-align:center;margin:20px 0;">'
        . '<a href="' . $safeDenyUrl . '" style="' . $denyStyle . '">This wasn\'t me</a>'
        . '</div>'
        . '<p>This confirmation link expires in ' . (int)$expiryMinutes . ' minute(s).</p>'
        . '<p>If you are unsure, contact your system administrator immediately.</p>'
        . '<p>Regards,<br>DMS LGU Solano</p>'
        . '</div>';

    return sendEmailViaSmtp($toEmail, $subject, $plainMessage, $config, $htmlMessage);
}

function completeLoginFromUserRow($user) {
    $_SESSION['user_id'] = (string)($user['id'] ?? '');
    $_SESSION['user_email'] = (string)($user['email'] ?? '');
    $_SESSION['user_name'] = (string)($user['name'] ?? ($user['email'] ?? ''));
    $_SESSION['user_username'] = (string)($user['username'] ?? '');
    $_SESSION['user_role'] = (string)($user['role'] ?? 'user');
    $_SESSION['user_photo'] = (string)($user['photo'] ?? '');
    $_SESSION['user_signature'] = (string)($user['signature'] ?? '');
}

function accountStateBlocked($user) {
    $state = strtolower(trim((string)($user['account_state'] ?? 'active')));
    if ($state === '' || $state === 'active') {
        return false;
    }
    if ($state === 'suspended') {
        $remaining = dbSuspendRemainingSeconds($user);
        if ($remaining !== null && $remaining <= 0) {
            return false;
        }
    }
    return true;
}

$pending = $_SESSION['google_trusted_pending_user'] ?? null;
$pendingSelector = trim((string)($_SESSION['google_trusted_pending_selector'] ?? ''));
$error = '';
$notice = '';
$emailActionCompleted = '';

if (!empty($_SESSION['user_id'])) {
    redirectByRole((string)($_SESSION['user_role'] ?? 'user'));
}
if (isset($_SESSION['google_trusted_notice'])) {
    $notice = (string)$_SESSION['google_trusted_notice'];
    unset($_SESSION['google_trusted_notice']);
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = $_SERVER['SCRIPT_NAME'] ?? '/Auth/verify-trusted-login.php';
$baseAuthUrl = $scheme . '://' . $host . rtrim(str_replace('\\', '/', dirname($script)), '/');

$userId = trim((string)($_GET['uid'] ?? ''));
if ($userId === '' && is_array($pending)) {
    $userId = trim((string)($pending['user_id'] ?? ''));
}

if (isset($_GET['poll']) && $_GET['poll'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!is_array($pending) || $pendingSelector === '') {
        echo json_encode(['status' => 'none']);
        exit;
    }
    $pendingUserId = trim((string)($pending['user_id'] ?? ''));
    if ($pendingUserId === '') {
        echo json_encode(['status' => 'none']);
        exit;
    }
    try {
        $decision = authTrustedConfirmDecision($config, $pendingUserId, $pendingSelector);
        if ($decision === null) {
            echo json_encode(['status' => 'pending']);
            exit;
        }
        if ($decision === 'deny') {
            unset($_SESSION['google_trusted_pending_user'], $_SESSION['google_trusted_pending_selector']);
            echo json_encode(['status' => 'denied', 'redirect' => '../index.php']);
            exit;
        }
        if ($decision === 'expired') {
            echo json_encode(['status' => 'expired']);
            exit;
        }
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $pendingUserId]);
        $user = $stmt->fetch();
        if (!$user || accountStateBlocked($user)) {
            unset($_SESSION['google_trusted_pending_user'], $_SESSION['google_trusted_pending_selector']);
            echo json_encode(['status' => 'denied', 'redirect' => '../index.php']);
            exit;
        }
        completeLoginFromUserRow($user);
        authTrustedIssue($config, (string)($_SESSION['user_id'] ?? ''), 30);
        unset($_SESSION['google_trusted_pending_user'], $_SESSION['google_trusted_pending_selector']);
        echo json_encode(['status' => 'approved', 'redirect' => roleRedirectPath((string)($_SESSION['user_role'] ?? 'user'))]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'pending']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    if (!is_array($pending) || trim((string)($pending['user_id'] ?? '')) === '') {
        $error = 'Your login session expired. Please sign in again.';
    } else {
        $pendingEmail = (string)($pending['user_email'] ?? '');
        $pendingUserId = (string)($pending['user_id'] ?? '');
        $pendingName = trim((string)($pending['user_name'] ?? ''));
        if ($pendingName === '') {
            $pendingName = trim((string)($pending['user_username'] ?? ''));
        }
        if ($pendingName === '') {
            $pendingName = $pendingEmail !== '' ? $pendingEmail : 'User';
        }
        $confirmPayload = authTrustedConfirmCreate($config, $pendingUserId, 10);
        if (!is_array($confirmPayload)) {
            $error = 'Could not create a confirmation request. Please sign in again.';
        } else {
            $tokenBaseUrl = $baseAuthUrl . '/verify-trusted-login.php?uid='
                . urlencode($pendingUserId)
                . '&selector=' . urlencode((string)$confirmPayload['selector'])
                . '&token=' . urlencode((string)$confirmPayload['token']);
            $_SESSION['google_trusted_pending_selector'] = (string)$confirmPayload['selector'];
            $confirmUrl = $tokenBaseUrl . '&action=allow';
            $denyUrl = $tokenBaseUrl . '&action=deny';
            if (sendTrustedLoginConfirmationEmail($pendingEmail, $confirmUrl, $denyUrl, $config, $pendingName, 10)) {
                $notice = 'A new confirmation link was sent to your Gmail.';
            } else {
                $error = 'Could not send the confirmation email. Please try again.';
            }
        }
    }
}

$selector = trim((string)($_GET['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
$action = strtolower(trim((string)($_GET['action'] ?? 'allow')));
if ($selector !== '' && $token !== '' && $userId !== '') {
    try {
        $pdo = dbPdo($config);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = 'This confirmation link is invalid.';
        } elseif (accountStateBlocked($user)) {
            $error = 'Your account is not allowed to sign in at this time.';
        } elseif (!authTrustedConfirmConsume($config, $userId, $selector, $token, $action)) {
            $error = 'This confirmation link is invalid or expired. Please request a new one.';
        } elseif ($action === 'deny') {
            authTrustedRevokeAll($config, (string)($user['id'] ?? ''));
            unset($_SESSION['google_trusted_pending_user'], $_SESSION['google_trusted_pending_selector']);
            activityLog($config, 'login_blocked', [
                'module' => 'auth',
                'login_type' => 'google_sso_trusted_device',
                'reason' => 'trusted_device_login_denied_by_user',
                'target_email' => (string)($user['email'] ?? ''),
            ], 'blocked', [
                'id' => (string)($user['id'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'name' => (string)($user['name'] ?? ''),
                'role' => (string)($user['role'] ?? 'user'),
            ]);
            $notice = 'This login attempt was denied. Trusted devices were removed for your account. Please sign in again and complete OTP.';
            $_SESSION['google_trusted_notice'] = $notice;
            $emailActionCompleted = 'deny';
        } else {
            $hasSamePendingSession = is_array($pending) && trim((string)($pending['user_id'] ?? '')) === (string)$userId;
            if ($hasSamePendingSession) {
                completeLoginFromUserRow($user);
                authTrustedIssue($config, (string)($_SESSION['user_id'] ?? ''), 30);
                unset($_SESSION['google_trusted_pending_user'], $_SESSION['google_trusted_pending_selector']);
                activityLog($config, 'login_success', [
                    'module' => 'auth',
                    'login_type' => 'google_sso_trusted_device_email_confirmed',
                    'role' => (string)($_SESSION['user_role'] ?? ''),
                ], 'success', [
                    'id' => (string)($_SESSION['user_id'] ?? ''),
                    'email' => (string)($_SESSION['user_email'] ?? ''),
                    'name' => (string)($_SESSION['user_name'] ?? ''),
                    'role' => (string)($_SESSION['user_role'] ?? ''),
                ]);
            }
            $notice = 'Login confirmed. Your original sign-in page should continue automatically.';
            $emailActionCompleted = 'allow';
        }
    } catch (Exception $e) {
        $error = 'Could not verify this login request. Please try signing in again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Confirm Trusted Login - DMS LGU</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #081b2e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.35);
        }
        h1 { margin: 0 0 0.5rem; font-size: 1.4rem; }
        p { margin: 0 0 1rem; color: #cfe6ff; font-size: 0.95rem; line-height: 1.45; }
        .msg {
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 0.9rem;
            font-size: 0.9rem;
        }
        .msg.error { background: rgba(239, 68, 68, 0.2); color: #fecaca; border: 1px solid rgba(248, 113, 113, 0.45); }
        .msg.ok { background: rgba(34, 197, 94, 0.18); color: #bbf7d0; border: 1px solid rgba(74, 222, 128, 0.4); }
        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 1rem;
        }
        button, a {
            border: 0;
            border-radius: 10px;
            padding: 12px 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s ease;
            font-size: 0.92rem;
        }
        .btn-primary { background: #ffd400; color: #0b2545; }
        .btn-primary:hover { background: #facc15; transform: translateY(-1px); }
        .btn-secondary {
            background: transparent;
            color: #cfe6ff;
            border: 1px solid #38587b;
        }
        .btn-secondary:hover {
            background: #16314f;
            border-color: #4f77a3;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Confirm This Login</h1>
        <p>For remembered devices, we now send a Gmail confirmation link before allowing access. Open the confirmation link to continue, or use "This wasn't me" in the email to block the attempt.</p>
        <?php if ($error !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="msg ok"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <div class="actions">
            <form method="post">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn-primary">Resend Email Link</button>
            </form>
            <a class="btn-secondary" href="../index.php">Back to Login</a>
        </div>
    </div>
    <script>
    (function() {
        var completedAction = <?= json_encode($emailActionCompleted) ?>;
        if (!completedAction) {
            // Waiting page: react in real time when email-tab confirms/denies.
            var pageLoadedAt = Date.now();
            function shouldReloadFromEvent(raw) {
                if (!raw) return false;
                try {
                    var payload = JSON.parse(raw);
                    if (!payload || !payload.type || !payload.ts) return false;
                    return Number(payload.ts) >= pageLoadedAt;
                } catch (e) {
                    return false;
                }
            }
            window.addEventListener('storage', function(ev) {
                if (ev.key !== 'dms_trusted_login_event') return;
                if (shouldReloadFromEvent(ev.newValue)) {
                    window.location.reload();
                }
            });

            var pollBusy = false;
            function pollServerDecision() {
                if (pollBusy) return;
                pollBusy = true;
                fetch('verify-trusted-login.php?poll=1', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                }).then(function(resp) {
                    return resp.json();
                }).then(function(data) {
                    if (!data || !data.status) return;
                    if (data.status === 'approved' && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    if (data.status === 'denied' && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    if (data.status === 'expired') {
                        window.location.reload();
                    }
                }).catch(function() {
                    // Ignore transient polling errors.
                }).finally(function() {
                    pollBusy = false;
                });
            }

            setInterval(function() {
                var raw = localStorage.getItem('dms_trusted_login_event');
                if (shouldReloadFromEvent(raw)) {
                    window.location.reload();
                }
            }, 1500);
            setInterval(pollServerDecision, 1500);
            pollServerDecision();
            return;
        }

        // Email-tab flow: push signal to waiting page then try close this tab.
        try {
            localStorage.setItem('dms_trusted_login_event', JSON.stringify({
                type: completedAction,
                ts: Date.now()
            }));
        } catch (e) {}

        try {
            if (window.opener && !window.opener.closed) {
                window.opener.location.reload();
            }
        } catch (e) {}

        setTimeout(function() {
            window.close();
        }, 400);
    })();
    </script>
</body>
</html>
