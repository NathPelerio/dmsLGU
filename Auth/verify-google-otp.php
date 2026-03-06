<?php
session_start();

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once dirname(__DIR__) . '/Super Admin Side/_activity_logger.php';
require_once __DIR__ . '/_auth_rate_limiter.php';
require_once __DIR__ . '/_trusted_device.php';
$pending = $_SESSION['google_otp_pending_user'] ?? null;
if (!$pending || !is_array($pending)) {
    header('Location: ../index.php');
    exit;
}

$expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
if ($expiryMinutes <= 0) {
    $expiryMinutes = 5;
}

function sendOtpEmail($toEmail, $otp, $config, $displayName = '') {
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }

    $subject = 'DMS LGU Solano verification code';
    $nameLine = trim($displayName) !== '' ? trim($displayName) : 'User';
    $message = "Good day, {$nameLine}.\n\n"
        . "You requested to sign in to the DMS LGU Solano system using Google.\n"
        . "Your one-time verification code is: {$otp}\n\n"
        . "This code will expire in {$expiryMinutes} minute(s).\n"
        . "If you did not request this sign-in, please ignore this message.\n\n"
        . "Regards,\n"
        . "DMS LGU Solano";

    return sendEmailViaSmtp($toEmail, $subject, $message, $config);
}

function finalizeLoginFromPending($pending) {
    $_SESSION['user_id'] = $pending['user_id'] ?? '';
    $_SESSION['user_email'] = $pending['user_email'] ?? '';
    $_SESSION['user_name'] = $pending['user_name'] ?? '';
    $_SESSION['user_username'] = $pending['user_username'] ?? '';
    $_SESSION['user_role'] = $pending['user_role'] ?? 'user';
    $_SESSION['user_photo'] = $pending['user_photo'] ?? '';
    $_SESSION['user_signature'] = $pending['user_signature'] ?? '';

    unset(
        $_SESSION['google_otp_pending_user'],
        $_SESSION['google_otp_code_hash'],
        $_SESSION['google_otp_expires_at'],
        $_SESSION['google_otp_resend_at']
    );
}

function redirectByRole($role) {
    if ($role === 'superadmin') {
        header('Location: ../Super%20Admin%20Side/dashboard.php');
    } elseif ($role === 'admin') {
        header('Location: ../Admin%20Side/admin_dashboard.php');
    } elseif (in_array($role, ['departmenthead', 'department_head', 'dept_head'])) {
        header('Location: ../Department%20Heads%20Side/department_dashboard.php');
    } else {
        header('Location: ../Front%20Desk%20Side/staff_dashboard.php');
    }
    exit;
}

function getAccountRestrictionMeta($user) {
    $state = strtolower(trim((string)($user['account_state'] ?? 'active')));
    if ($state === '' || $state === 'active') {
        return null;
    }
    if ($state === 'disabled') {
        return [
            'type' => 'disabled',
            'reason' => trim((string)($user['disabled_reason'] ?? '')),
            'days' => 0,
        ];
    }
    if ($state === 'suspended') {
        $remainingSeconds = dbSuspendRemainingSeconds($user);
        if ($remainingSeconds !== null && $remainingSeconds <= 0) {
            return null;
        }
        $days = 1;
        if ($remainingSeconds !== null) {
            $days = (int)ceil(max(1, $remainingSeconds) / 86400);
        }
        return [
            'type' => 'suspended',
            'reason' => trim((string)($user['suspend_reason'] ?? '')),
            'days' => max(1, $days),
            'remaining_seconds' => $remainingSeconds !== null ? max(1, (int)$remainingSeconds) : null,
        ];
    }
    return null;
}

function clearPendingGoogleOtpState() {
    unset(
        $_SESSION['google_otp_pending_user'],
        $_SESSION['google_otp_code_hash'],
        $_SESSION['google_otp_expires_at'],
        $_SESSION['google_otp_resend_at']
    );
}

function redirectBlockedAccountToLogin($restriction) {
    if (!is_array($restriction)) {
        return;
    }
    if (($restriction['type'] ?? '') === 'disabled') {
        $url = '../index.php?error=google_account_disabled';
        if (!empty($restriction['reason'])) {
            $url .= '&reason=' . urlencode($restriction['reason']);
        }
        header('Location: ' . $url);
        exit;
    }
    if (($restriction['type'] ?? '') === 'suspended') {
        $url = '../index.php?error=google_account_suspended&days=' . (int)($restriction['days'] ?? 1);
        if (isset($restriction['remaining_seconds']) && $restriction['remaining_seconds'] !== null) {
            $url .= '&seconds=' . (int)$restriction['remaining_seconds'];
        }
        if (!empty($restriction['reason'])) {
            $url .= '&reason=' . urlencode($restriction['reason']);
        }
        header('Location: ' . $url);
        exit;
    }
}

$error = '';
$notice = '';
$rateScope = 'google_otp_verify';
$rateIdentifier = strtolower(trim((string)($pending['user_id'] ?? $pending['user_email'] ?? '')));
$clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$rateLimitSeconds = 0;
$rateLimitType = '';

if (isset($_POST['action']) && $_POST['action'] === 'resend') {
    $nextResendAt = (int)($_SESSION['google_otp_resend_at'] ?? 0);
    if ($nextResendAt > time()) {
        $wait = $nextResendAt - time();
        $error = 'Please wait ' . $wait . ' second(s) before requesting another code.';
    } else {
        $newOtp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['google_otp_code_hash'] = password_hash($newOtp, PASSWORD_DEFAULT);
        $_SESSION['google_otp_expires_at'] = time() + ($expiryMinutes * 60);
        $_SESSION['google_otp_resend_at'] = time() + 30;
        $otpName = trim($pending['user_name'] ?? '') ?: (trim($pending['user_username'] ?? '') ?: ($pending['user_email'] ?? 'User'));
        if (sendOtpEmail($pending['user_email'] ?? '', $newOtp, $config, $otpName)) {
            $notice = 'A new verification code was sent to your Gmail.';
        } else {
            $error = 'Failed to resend verification code. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'verify')) {
    $otpInput = trim($_POST['otp'] ?? '');
    $rememberDevice = !empty($_POST['remember_device']);
    $otpHash = $_SESSION['google_otp_code_hash'] ?? '';
    $otpExpiresAt = (int)($_SESSION['google_otp_expires_at'] ?? 0);
    $rateStatus = authRateLimiterStatus($config, $rateScope, $rateIdentifier, $clientIp);
    if (!empty($rateStatus['blocked'])) {
        $error = authRateLimiterMessage($rateStatus);
        $rateLimitSeconds = max(1, (int)($rateStatus['seconds_left'] ?? 0));
        $rateLimitType = (string)($rateStatus['type'] ?? '');
    } elseif (!preg_match('/^\d{6}$/', $otpInput)) {
        $failStatus = authRateLimiterFail($config, $rateScope, $rateIdentifier, $clientIp);
        $error = authRateLimiterMessage($failStatus);
        $rateLimitSeconds = max(1, (int)($failStatus['seconds_left'] ?? 0));
        $rateLimitType = (string)($failStatus['type'] ?? '');
    } elseif ($otpExpiresAt <= time()) {
        $error = 'Verification code expired. Please request a new code.';
    } elseif ($otpHash === '' || !password_verify($otpInput, $otpHash)) {
        $failStatus = authRateLimiterFail($config, $rateScope, $rateIdentifier, $clientIp);
        $error = authRateLimiterMessage($failStatus);
        $rateLimitSeconds = max(1, (int)($failStatus['seconds_left'] ?? 0));
        $rateLimitType = (string)($failStatus['type'] ?? '');
    } else {
        authRateLimiterReset($config, $rateScope, $rateIdentifier, $clientIp);

        try {
            $userId = trim((string)($pending['user_id'] ?? ''));
            if ($userId === '') {
                clearPendingGoogleOtpState();
                header('Location: ../index.php?error=google_login_failed');
                exit;
            }
            $pdo = dbPdo($config);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $latestUser = $stmt->fetch();
            if (!$latestUser) {
                clearPendingGoogleOtpState();
                header('Location: ../index.php?error=google_not_authorized');
                exit;
            }
            $restriction = getAccountRestrictionMeta($latestUser);
            if (is_array($restriction)) {
                activityLog($config, 'login_blocked', [
                    'module' => 'auth',
                    'login_type' => 'google_sso_otp',
                    'reason' => ($restriction['type'] ?? 'blocked'),
                    'target_email' => (string)($pending['user_email'] ?? ''),
                ], 'blocked', [
                    'id' => (string)($pending['user_id'] ?? ''),
                    'email' => (string)($pending['user_email'] ?? ''),
                    'name' => (string)($pending['user_name'] ?? ''),
                    'role' => (string)($pending['user_role'] ?? ''),
                ]);
                clearPendingGoogleOtpState();
                redirectBlockedAccountToLogin($restriction);
            }
        } catch (Exception $e) {
            clearPendingGoogleOtpState();
            header('Location: ../index.php?error=google_login_failed');
            exit;
        }

        finalizeLoginFromPending($pending);
        if ($rememberDevice) {
            authTrustedIssue($config, (string)($_SESSION['user_id'] ?? ''), 30);
        }
        activityLog($config, 'login_success', [
            'module' => 'auth',
            'login_type' => $rememberDevice ? 'google_sso_remembered_device' : 'google_sso',
            'role' => (string)($_SESSION['user_role'] ?? ''),
        ], 'success', [
            'id' => (string)($_SESSION['user_id'] ?? ''),
            'email' => (string)($_SESSION['user_email'] ?? ''),
            'name' => (string)($_SESSION['user_name'] ?? ''),
            'role' => (string)($_SESSION['user_role'] ?? ''),
        ]);
        redirectByRole($_SESSION['user_role'] ?? 'user');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Gmail Code - DMS LGU</title>
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
            max-width: 460px;
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.35);
        }
        h1 { margin: 0 0 0.5rem; font-size: 1.45rem; }
        p { margin: 0 0 1rem; color: #cfe6ff; font-size: 0.95rem; }
        .email { color: #ffd400; font-weight: 600; }
        .msg {
            border-radius: 10px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 0.9rem;
            font-size: 0.9rem;
        }
        .msg.error { background: rgba(239, 68, 68, 0.2); color: #fecaca; border: 1px solid rgba(248, 113, 113, 0.45); }
        .msg.ok { background: rgba(34, 197, 94, 0.18); color: #bbf7d0; border: 1px solid rgba(74, 222, 128, 0.4); }
        label { display: block; font-size: 0.9rem; color: #b8d4ee; margin: 0.35rem 0; }
        input[type="text"] {
            width: 100%;
            height: 46px;
            border-radius: 10px;
            border: 1px solid #1d3d62;
            background: #0a2036;
            color: #fff;
            padding: 0 12px;
            font-size: 1rem;
            letter-spacing: 0.22em;
            text-align: center;
        }
        .remember-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 0.75rem;
            color: #cfe6ff;
            font-size: 0.9rem;
        }
        .remember-wrap input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #ffd400;
            cursor: pointer;
        }
        .remember-wrap label {
            margin: 0;
            cursor: pointer;
            color: #cfe6ff;
        }
        .actions { display: flex; gap: 10px; margin-top: 1rem; }
        button {
            border: 0;
            border-radius: 10px;
            padding: 12px 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-primary { flex: 1; background: #ffd400; color: #0b2545; }
        .btn-primary:hover { background: #facc15; transform: translateY(-1px); }
        .secondary-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 0.8rem;
        }
        .btn-secondary {
            width: 100%;
            background: transparent;
            color: #cfe6ff;
            border: 1px solid #38587b;
        }
        .btn-secondary:hover {
            background: #16314f;
            border-color: #4f77a3;
            color: #ffffff;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            border-radius: 10px;
            border: 1px solid #38587b;
            color: #cfe6ff;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 12px 14px;
            background: rgba(10, 32, 54, 0.35);
            transition: all 0.2s ease;
        }
        .back-link:hover {
            background: #16314f;
            border-color: #4f77a3;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Verify Your Sign-In</h1>
        <p>We sent a 6-digit code to <span class="email"><?= htmlspecialchars($pending['user_email'] ?? '') ?></span>. Enter the code to continue.</p>

        <?php if ($error !== ''): ?>
            <div
                class="msg error"
                <?php if ($rateLimitSeconds > 0): ?>
                    data-rate-limit-seconds="<?= (int)$rateLimitSeconds ?>"
                    data-rate-limit-type="<?= htmlspecialchars($rateLimitType) ?>"
                <?php endif; ?>
            ><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="msg ok"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="verify">
            <label for="otp">Verification code</label>
            <input type="text" id="otp" name="otp" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="000000" required>
            <div class="remember-wrap">
                <input type="checkbox" id="remember_device" name="remember_device" value="1" checked>
                <label for="remember_device">Remember me on this device (30 days)</label>
            </div>
            <div class="actions">
                <button type="submit" class="btn-primary">Verify and Continue</button>
            </div>
        </form>

        <div class="secondary-actions">
            <form method="post">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="btn-secondary">Resend Code</button>
            </form>
            <a class="back-link" href="../index.php">Back to Login</a>
        </div>
    </div>
    <script>
    (function() {
        function formatLongCountdown(secondsLeft) {
            var minutes = Math.floor(secondsLeft / 60);
            var seconds = secondsLeft % 60;
            return minutes + ' minute(s) ' + seconds + ' second(s)';
        }

        function applyCountdownMessage(el, secondsLeft, type) {
            if (!el) return;
            if (secondsLeft <= 0) {
                el.textContent = 'You can try verifying again now.';
                return;
            }
            if (type === 'long') {
                el.textContent = 'Too many failed attempts. Please wait ' + formatLongCountdown(secondsLeft) + ' before trying again.';
            } else {
                el.textContent = 'Incorrect credentials. Please wait ' + secondsLeft + ' second(s) before trying again.';
            }
        }

        var el = document.querySelector('[data-rate-limit-seconds]');
        if (!el) return;
        var left = parseInt(el.getAttribute('data-rate-limit-seconds') || '0', 10);
        var type = (el.getAttribute('data-rate-limit-type') || 'short').toLowerCase();
        if (!left || left <= 0) return;
        applyCountdownMessage(el, left, type);
        var timer = setInterval(function() {
            left -= 1;
            if (left <= 0) {
                clearInterval(timer);
                applyCountdownMessage(el, 0, type);
                return;
            }
            applyCountdownMessage(el, left, type);
        }, 1000);
    })();
    </script>
</body>
</html>
