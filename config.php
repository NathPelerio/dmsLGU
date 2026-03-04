<?php
/**
 * Application config (loaded from .env at project root).
 */

$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '') {
            continue;
        }
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

return [
    'mysql_host'           => getenv('MYSQL_HOST') ?: '127.0.0.1',
    'mysql_port'           => (int)(getenv('MYSQL_PORT') ?: 3306),
    'mysql_database'       => getenv('MYSQL_DATABASE') ?: 'dmslgu',
    'mysql_user'           => getenv('MYSQL_USER') ?: 'root',
    'mysql_password'       => getenv('MYSQL_PASSWORD') ?: '',
    'google_client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
    'google_client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    'mail_from'            => getenv('MAIL_FROM') ?: 'no-reply@dmslgu.local',
    'mail_from_name'       => getenv('MAIL_FROM_NAME') ?: 'DMS LGU',
    'otp_expiry_minutes'   => (int)(getenv('OTP_EXPIRY_MINUTES') ?: 5),
    'smtp_host'            => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'smtp_port'            => (int)(getenv('SMTP_PORT') ?: 465),
    'smtp_secure'          => getenv('SMTP_SECURE') ?: 'ssl',
    'smtp_username'        => getenv('SMTP_USERNAME') ?: '',
    'smtp_password'        => getenv('SMTP_PASSWORD') ?: '',
    'smtp_timeout'         => (int)(getenv('SMTP_TIMEOUT') ?: 15),
];
