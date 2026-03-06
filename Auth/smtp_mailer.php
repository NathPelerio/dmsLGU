<?php

function smtpReadResponse($socket) {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        // Multi-line SMTP response ends when 4th char is a space.
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtpCommand($socket, $command, $okPrefixes = ['2', '3']) {
    fwrite($socket, $command . "\r\n");
    $response = smtpReadResponse($socket);
    if ($response === '') {
        return false;
    }
    $first = $response[0] ?? '';
    return in_array($first, $okPrefixes, true);
}

function sendEmailViaSmtp($toEmail, $subject, $plainBody, $config, $htmlBody = '') {
    $host = trim($config['smtp_host'] ?? '');
    $port = (int)($config['smtp_port'] ?? 465);
    $username = trim($config['smtp_username'] ?? '');
    $password = trim($config['smtp_password'] ?? '');
    $secure = strtolower(trim($config['smtp_secure'] ?? 'ssl'));
    $timeout = (int)($config['smtp_timeout'] ?? 15);
    $fromEmail = trim($config['mail_from'] ?? $username);
    $fromName = trim($config['mail_from_name'] ?? 'DMS LGU');

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        return false;
    }

    $transportHost = ($secure === 'ssl') ? ('ssl://' . $host) : $host;
    $socket = @fsockopen($transportHost, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return false;
    }
    stream_set_timeout($socket, $timeout);

    $greeting = smtpReadResponse($socket);
    if ($greeting === '' || ($greeting[0] ?? '') !== '2') {
        fclose($socket);
        return false;
    }

    if (!smtpCommand($socket, 'EHLO localhost')) {
        fclose($socket);
        return false;
    }

    if (!smtpCommand($socket, 'AUTH LOGIN', ['3'])) {
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, base64_encode($username), ['3'])) {
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, base64_encode($password), ['2'])) {
        fclose($socket);
        return false;
    }

    if (!smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>')) {
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>')) {
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, 'DATA', ['3'])) {
        fclose($socket);
        return false;
    }

    $safeSubject = str_replace(["\r", "\n"], '', $subject);
    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = [];
    $headers[] = 'From: ' . $encodedName . ' <' . $fromEmail . '>';
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: ' . $safeSubject;
    $headers[] = 'MIME-Version: 1.0';
    $isHtml = trim((string)$htmlBody) !== '';
    if ($isHtml) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    }
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    // Dot-stuffing per SMTP spec.
    $payload = $isHtml ? (string)$htmlBody : (string)$plainBody;
    $body = preg_replace('/^\./m', '..', $payload);
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    if (!smtpCommand($socket, $message)) {
        fclose($socket);
        return false;
    }

    smtpCommand($socket, 'QUIT', ['2']);
    fclose($socket);
    return true;
}

