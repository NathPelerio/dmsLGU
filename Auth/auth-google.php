<?php
/**
 * Google OAuth 2.0 login.
 * Configure GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in config.php or environment.
 */
session_start();

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once dirname(__DIR__) . '/Super Admin Side/_activity_logger.php';
require_once __DIR__ . '/_trusted_device.php';
$clientId = $config['google_client_id'] ?? '';
$clientSecret = $config['google_client_secret'] ?? '';

if ($clientId === '' || $clientSecret === '') {
    header('Location: ../index.php?error=google_not_configured');
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script = $_SERVER['SCRIPT_NAME'] ?? '/Auth/auth-google.php';
$redirectUri = $scheme . '://' . $host . $script;
$baseAuthUrl = $scheme . '://' . $host . rtrim(str_replace('\\', '/', dirname($script)), '/');

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

function sendOtpEmail($toEmail, $otp, $config, $displayName = '') {
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }

    $subject = 'DMS LGU Solano - Your verification code';
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

// Step 1: No code yet — redirect to Google consent
if (empty($_GET['code'])) {
    $params = [
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header('Location: ' . $url);
    exit;
}

// Step 2: Exchange code for tokens
$code = $_GET['code'];
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenBody = http_build_query([
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

$tokenContext = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $tokenBody,
    ],
]);
$tokenResponse = @file_get_contents($tokenUrl, false, $tokenContext);
if ($tokenResponse === false) {
    header('Location: ../index.php?error=google_token_failed');
    exit;
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? null;
if (!$accessToken) {
    header('Location: ../index.php?error=google_token_failed');
    exit;
}

// Step 3: Get user info
$userInfoContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer " . $accessToken . "\r\n",
    ],
]);
$userInfoResponse = @file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, $userInfoContext);
if ($userInfoResponse === false) {
    header('Location: ../index.php?error=google_userinfo_failed');
    exit;
}

$userInfo = json_decode($userInfoResponse, true);
$email = strtolower(trim($userInfo['email'] ?? ''));
$name = trim($userInfo['name'] ?? $email);
$picture = trim($userInfo['picture'] ?? '');

if ($email === '') {
    header('Location: ../index.php?error=google_no_email');
    exit;
}

// Step 4: Only allow login if this email already exists in the database (no auto-create)
try {
    $pdo = dbPdo($config);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Email not in database — pass email to no-access page via session
        activityLog($config, 'login_blocked', [
            'module' => 'auth',
            'login_type' => 'google_sso',
            'reason' => 'google_email_not_authorized',
            'target_email' => $email,
        ], 'blocked', ['email' => $email, 'name' => $name, 'role' => 'guest']);
        $_SESSION['unauthorized_email'] = $email;
        header('Location: no-access.php?google=1');
        exit;
    }

    $resolvedActorName = trim((string)($user['name'] ?? ''));
    if ($resolvedActorName === '') {
        $resolvedActorName = trim((string)($user['username'] ?? ''));
    }
    if ($resolvedActorName === '') {
        $resolvedActorName = $email;
    }
    $resolvedActorRole = trim((string)($user['role'] ?? 'user'));

    $restriction = getAccountRestrictionMeta($user);
    if (is_array($restriction)) {
        if ($restriction['type'] === 'disabled') {
            activityLog($config, 'login_blocked', [
                'module' => 'auth',
                'login_type' => 'google_sso',
                'reason' => 'account_disabled',
                'target_email' => $email,
            ], 'blocked', ['id' => (string)($user['id'] ?? ''), 'email' => $email, 'name' => $resolvedActorName, 'role' => $resolvedActorRole]);
            $url = '../index.php?error=google_account_disabled';
            if (!empty($restriction['reason'])) {
                $url .= '&reason=' . urlencode($restriction['reason']);
            }
            header('Location: ' . $url);
            exit;
        }
        if ($restriction['type'] === 'suspended') {
            activityLog($config, 'login_blocked', [
                'module' => 'auth',
                'login_type' => 'google_sso',
                'reason' => 'account_suspended',
                'days' => (string)($restriction['days'] ?? ''),
                'target_email' => $email,
            ], 'blocked', ['id' => (string)($user['id'] ?? ''), 'email' => $email, 'name' => $resolvedActorName, 'role' => $resolvedActorRole]);
            $url = '../index.php?error=google_account_suspended&days=' . (int)$restriction['days'];
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

    $trustedDeviceOk = authTrustedConsume($config, (string)($user['id'] ?? ''));
    if ($trustedDeviceOk) {
        $confirmPayload = authTrustedConfirmCreate($config, (string)($user['id'] ?? ''), 10);
        if (!is_array($confirmPayload)) {
            header('Location: ../index.php?error=google_trusted_confirm_send_failed');
            exit;
        }
        $tokenBaseUrl = $baseAuthUrl . '/verify-trusted-login.php?uid='
            . urlencode((string)($user['id'] ?? ''))
            . '&selector=' . urlencode((string)$confirmPayload['selector'])
            . '&token=' . urlencode((string)$confirmPayload['token']);
        $confirmUrl = $tokenBaseUrl . '&action=allow';
        $denyUrl = $tokenBaseUrl . '&action=deny';
        $confirmName = trim((string)($user['name'] ?? ''));
        if ($confirmName === '') {
            $confirmName = trim((string)($user['username'] ?? ''));
        }
        if ($confirmName === '') {
            $confirmName = $email;
        }
        if (!sendTrustedLoginConfirmationEmail($email, $confirmUrl, $denyUrl, $config, $confirmName, 10)) {
            header('Location: ../index.php?error=google_trusted_confirm_send_failed');
            exit;
        }

        $_SESSION['google_trusted_pending_user'] = [
            'user_id' => (string)($user['id'] ?? ''),
            'user_email' => $user['email'] ?? $email,
            'user_name' => $user['name'] ?? $name,
            'user_username' => $user['username'] ?? '',
            'user_role' => $user['role'] ?? 'user',
            'user_photo' => $user['photo'] ?? $picture,
            'user_signature' => $user['signature'] ?? '',
        ];
        $_SESSION['google_trusted_pending_selector'] = (string)$confirmPayload['selector'];
        activityLog($config, 'login_challenge_sent', [
            'module' => 'auth',
            'login_type' => 'google_sso_trusted_device',
            'challenge' => 'email_confirmation_link',
            'target_email' => $email,
        ], 'success', [
            'id' => (string)($user['id'] ?? ''),
            'email' => $email,
            'name' => $confirmName,
            'role' => (string)($user['role'] ?? 'user'),
        ]);
        header('Location: verify-trusted-login.php');
        exit;
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiryMinutes = (int)($config['otp_expiry_minutes'] ?? 5);
    if ($expiryMinutes <= 0) {
        $expiryMinutes = 5;
    }

    $_SESSION['google_otp_pending_user'] = [
        'user_id' => (string)($user['id'] ?? ''),
        'user_email' => $user['email'] ?? $email,
        'user_name' => $user['name'] ?? $name,
        'user_username' => $user['username'] ?? '',
        'user_role' => $user['role'] ?? 'user',
        'user_photo' => $user['photo'] ?? $picture,
        'user_signature' => $user['signature'] ?? '',
    ];
    $_SESSION['google_otp_code_hash'] = password_hash($otp, PASSWORD_DEFAULT);
    $_SESSION['google_otp_expires_at'] = time() + ($expiryMinutes * 60);
    $_SESSION['google_otp_resend_at'] = time() + 30;
    $otpName = trim($user['name'] ?? '') ?: (trim($user['username'] ?? '') ?: $email);
    if (!sendOtpEmail($email, $otp, $config, $otpName)) {
        unset(
            $_SESSION['google_otp_pending_user'],
            $_SESSION['google_otp_code_hash'],
            $_SESSION['google_otp_expires_at'],
            $_SESSION['google_otp_resend_at']
        );
        header('Location: ../index.php?error=google_otp_send_failed');
        exit;
    }

    header('Location: verify-google-otp.php');
    exit;
} catch (Exception $e) {
    header('Location: ../index.php?error=google_login_failed');
    exit;
}
