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

function smtpCommand($socket, $command, $okPrefixes = ['2', '3'], &$rawResponse = null) {
    fwrite($socket, $command . "\r\n");
    $response = smtpReadResponse($socket);
    $rawResponse = $response;
    if ($response === '') {
        return false;
    }
    $first = $response[0] ?? '';
    return in_array($first, $okPrefixes, true);
}

function sendEmailViaSmtp($toEmail, $subject, $plainBody, $config, $htmlBody = '', &$errorMessage = null) {
    $errorMessage = null;
    $host = trim($config['smtp_host'] ?? '');
    $port = (int)($config['smtp_port'] ?? 465);
    $username = trim($config['smtp_username'] ?? '');
    $password = trim($config['smtp_password'] ?? '');
    $secure = strtolower(trim($config['smtp_secure'] ?? 'ssl'));
    $timeout = (int)($config['smtp_timeout'] ?? 15);
    $fromEmail = trim($config['mail_from'] ?? $username);
    $fromName = trim($config['mail_from_name'] ?? 'DMS LGU');
    $isGmail = (stripos($host, 'gmail.com') !== false);

    // Gmail app passwords are commonly copied with spaces (e.g. "abcd efgh ijkl mnop").
    if ($isGmail && $password !== '') {
        $password = preg_replace('/\s+/', '', $password);
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $username;
    }
    // Gmail SMTP is strict about envelope sender; fallback to authenticated account.
    if ($isGmail && strcasecmp($fromEmail, $username) !== 0) {
        $fromEmail = $username;
    }

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        $errorMessage = 'Missing SMTP configuration.';
        return false;
    }

    $verifyPeer = false;
    if (array_key_exists('smtp_verify_peer', $config)) {
        $parsed = filter_var($config['smtp_verify_peer'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            $verifyPeer = $parsed;
        }
    }
    $verifyPeerName = $verifyPeer;
    if (array_key_exists('smtp_verify_peer_name', $config)) {
        $parsed = filter_var($config['smtp_verify_peer_name'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            $verifyPeerName = $parsed;
        }
    }
    $allowSelfSigned = !$verifyPeer;
    if (array_key_exists('smtp_allow_self_signed', $config)) {
        $parsed = filter_var($config['smtp_allow_self_signed'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($parsed !== null) {
            $allowSelfSigned = $parsed;
        }
    }

    $contextOptions = [
        'ssl' => [
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeerName,
            'allow_self_signed' => $allowSelfSigned,
        ],
    ];
    $caFile = trim((string)($config['smtp_cafile'] ?? ''));
    if ($caFile !== '' && is_file($caFile)) {
        $contextOptions['ssl']['cafile'] = $caFile;
    }
    $context = stream_context_create($contextOptions);

    $transportHost = ($secure === 'ssl') ? ('ssl://' . $host) : ('tcp://' . $host);
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $last = error_get_last();
        $lastText = trim((string)($last['message'] ?? ''));
        $errorMessage = 'SMTP connect failed (' . (int)$errno . '): ' . (string)$errstr;
        if ($lastText !== '') {
            $errorMessage .= ' | ' . $lastText;
        }
        return false;
    }
    stream_set_timeout($socket, $timeout);

    $greeting = smtpReadResponse($socket);
    if ($greeting === '' || ($greeting[0] ?? '') !== '2') {
        $errorMessage = 'SMTP greeting rejected: ' . trim((string)$greeting);
        fclose($socket);
        return false;
    }

    $smtpResp = '';
    if (!smtpCommand($socket, 'EHLO localhost', ['2', '3'], $smtpResp)) {
        $errorMessage = 'EHLO failed: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }

    if ($secure === 'tls') {
        if (!smtpCommand($socket, 'STARTTLS', ['2'], $smtpResp)) {
            $errorMessage = 'STARTTLS failed: ' . trim((string)$smtpResp);
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $errorMessage = 'TLS crypto negotiation failed.';
            fclose($socket);
            return false;
        }
        if (!smtpCommand($socket, 'EHLO localhost', ['2', '3'], $smtpResp)) {
            $errorMessage = 'EHLO after STARTTLS failed: ' . trim((string)$smtpResp);
            fclose($socket);
            return false;
        }
    }

    if (!smtpCommand($socket, 'AUTH LOGIN', ['3'], $smtpResp)) {
        $errorMessage = 'AUTH LOGIN failed: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, base64_encode($username), ['3'], $smtpResp)) {
        $errorMessage = 'SMTP username rejected: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, base64_encode($password), ['2'], $smtpResp)) {
        $errorMessage = 'SMTP password rejected: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }

    if (!smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', ['2', '3'], $smtpResp)) {
        $errorMessage = 'MAIL FROM rejected: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', ['2', '3'], $smtpResp)) {
        $errorMessage = 'RCPT TO rejected: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }
    if (!smtpCommand($socket, 'DATA', ['3'], $smtpResp)) {
        $errorMessage = 'DATA command failed: ' . trim((string)$smtpResp);
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
    if (!smtpCommand($socket, $message, ['2', '3'], $smtpResp)) {
        $errorMessage = 'Message body rejected: ' . trim((string)$smtpResp);
        fclose($socket);
        return false;
    }

    smtpCommand($socket, 'QUIT', ['2']);
    fclose($socket);
    return true;
}

