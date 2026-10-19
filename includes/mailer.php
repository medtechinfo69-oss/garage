<?php

/**
 * Envoi d'e-mails avec gabarit HTML + pièce jointe PDF.
 *
 * Deux chemins d'envoi :
 * 1. SMTP (recommandé sous XAMPP/Windows) : client SMTP natif (TLS +
 *    AUTH LOGIN) configuré dans Admin → Paramètres → E-mail (SMTP).
 *    Gmail : smtp.gmail.com:587 + un « mot de passe d'application ».
 * 2. mail() PHP en secours si le SMTP n'est pas configuré.
 *
 * Si l'envoi échoue, l'application le signale (avec la cause SMTP) et propose
 * le téléchargement direct du PDF (l'utilisateur peut le joindre manuellement).
 */

declare(strict_types=1);

/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }

/** Adresse d'expédition validée : smtp_from > smtp_user > email_from > secours. */
function smtp_from_address(): string
{
    $co = company_info();
    $candidates = [
        (string) get_setting('smtp_from', ''),
        (string) get_setting('smtp_user', ''),
        (string) get_setting('email_from', ''),
        (string) $co['email'],
    ];
    foreach ($candidates as $c) {
        $c = trim($c);
        if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
            return $c;
        }
    }
    return 'no-reply@localhost';
}

/** Le SMTP est-il paramétré (hôte + utilisateur) ? */
function smtp_configured(): bool
{
    return trim((string) get_setting('smtp_host', '')) !== ''
        && trim((string) get_setting('smtp_user', '')) !== '';
}

/** En-tête RFC 2047 (UTF-8 base64) si la valeur contient des non-ASCII. */
function smtp_mime_header(string $name, string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $name . ': ' . $value;
    }
    return $name . ': =?UTF-8?B?' . base64_encode($value) . '?=';
}

/** Lit une réponse SMTP (gère les réponses multilignes « 250- »). */
function smtp_read_response($fp): array
{
    $data = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return [(int) substr($data, 0, 3), $data];
}

/** Envoie une commande SMTP et retourne [code, réponse]. */
function smtp_cmd($fp, string $cmd): array
{
    fwrite($fp, $cmd . "\r\n");
    return smtp_read_response($fp);
}

/**
 * Livraison SMTP directe (TLS + AUTH LOGIN) d'un message MIME complet.
 * Retour : [ok: bool, error: string]
 */
function smtp_deliver(string $to, array $headers, string $body): array
{
    $host = trim((string) get_setting('smtp_host', ''));
    $user = trim((string) get_setting('smtp_user', ''));
    $pass = (string) get_setting('smtp_pass', '');
    if ($host === '' || $user === '') {
        return [false, 'SMTP non configuré (Paramètres → E-mail SMTP).'];
    }
    $port = (int) get_setting('smtp_port', '587');
    if ($port <= 0 || $port > 65535) {
        $port = 587;
    }
    $secure = strtolower(trim((string) get_setting('smtp_secure', 'tls')));

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return [false, 'Connexion SMTP impossible : ' . $errstr . ' (' . $errno . ')'];
    }
    stream_set_timeout($fp, 20);

    [$code, $resp] = smtp_read_response($fp);
    if ($code !== 220) { fclose($fp); return [false, 'Bannière SMTP : ' . trim($resp)]; }

    [$code, $resp] = smtp_cmd($fp, 'EHLO localhost');
    if ($code !== 250) { fclose($fp); return [false, 'EHLO : ' . trim($resp)]; }

    if ($secure === 'tls') {
        [$code, $resp] = smtp_cmd($fp, 'STARTTLS');
        if ($code !== 220) { fclose($fp); return [false, 'STARTTLS refusé : ' . trim($resp)]; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return [false, 'Négociation TLS échouée.'];
        }
        [$code, $resp] = smtp_cmd($fp, 'EHLO localhost');
        if ($code !== 250) { fclose($fp); return [false, 'EHLO (après TLS) : ' . trim($resp)]; }
    }

    [$code, $resp] = smtp_cmd($fp, 'AUTH LOGIN');
    if ($code !== 334) { fclose($fp); return [false, 'AUTH LOGIN refusé : ' . trim($resp)]; }
    [$code, $resp] = smtp_cmd($fp, base64_encode($user));
    if ($code !== 334) { fclose($fp); return [false, 'Identifiant SMTP refusé : ' . trim($resp)]; }
    [$code, $resp] = smtp_cmd($fp, base64_encode($pass));
    if ($code !== 235) {
        fclose($fp);
        return [false, 'Authentification refusée (' . trim($resp) . ') — pour Gmail, utilisez un mot de passe d\'application.'];
    }

    $from = smtp_from_address();
    [$code, $resp] = smtp_cmd($fp, 'MAIL FROM:<' . $from . '>');
    if ($code !== 250) { fclose($fp); return [false, 'MAIL FROM refusé : ' . trim($resp)]; }
    [$code, $resp] = smtp_cmd($fp, 'RCPT TO:<' . $to . '>');
    if ($code !== 250 && $code !== 251) { fclose($fp); return [false, 'Destinataire refusé : ' . trim($resp)]; }
    [$code, $resp] = smtp_cmd($fp, 'DATA');
    if ($code !== 354) { fclose($fp); return [false, 'DATA refusé : ' . trim($resp)]; }

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    fwrite($fp, $data);
    [$code, $resp] = smtp_read_response($fp);
    if ($code !== 250) { fclose($fp); return [false, 'Serveur a refusé le message : ' . trim($resp)]; }

    @smtp_cmd($fp, 'QUIT');
    fclose($fp);
    return [true, ''];
}


/**
 * Envoie un e-mail HTML avec pièce jointe PDF.
 * Retour : [ok: bool, error: string]
 */
function send_document_email(
    string $to,
    string $subject,
    string $html,
    ?string $pdfPath,
    string $pdfName,
    ?int $docId = null,
    string $docModule = 'devis',
    string $docNumber = ''
): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, t('error.invalid_email')];
    }
    $co = company_info();
    $from = smtp_from_address();
    $replyTo = (string) get_setting('email_from', '');
    if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = $from;
    }
    $fromName = preg_match('/^[\x20-\x7E]*$/', (string) $co['name']) ? (string) $co['name'] : '';

    $boundary = 'b_' . bin2hex(random_bytes(10));
    $headers = [
        ($fromName !== '' ? 'From: ' . $fromName . ' <' . $from . '>' : 'From: ' . $from),
        'Reply-To: ' . $replyTo,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@localhost>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];

    $body = '--' . $boundary . "\r\n"
        . 'Content-Type: text/html; charset="UTF-8"' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "\r\n--" . $boundary . "\r\n";

    if ($pdfPath !== null && is_file($pdfPath)) {
        $pdfData = (string) file_get_contents($pdfPath);
        $safeName = preg_replace('/[^\w.\-]+/', '_', $pdfName);
        $body .= 'Content-Type: application/pdf; name="' . $safeName . '"' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n"
            . 'Content-Disposition: attachment; filename="' . $safeName . '"' . "\r\n\r\n"
            . chunk_split(base64_encode($pdfData))
            . "\r\n";
    }
    $body .= '--' . $boundary . '--';

    $err = '';
    if (smtp_configured()) {
        /* --- Chemin principal : SMTP (Gmail, etc.) --- */
        $smtpHeaders = array_merge(['To: ' . $to, smtp_mime_header('Subject', $subject)], $headers);
        [$ok, $err] = smtp_deliver($to, $smtpHeaders, $body);
        if (!$ok) {
            error_log('[garage] SMTP delivery failed -> ' . $to . ' : ' . $err);
        }
    } else {
        /* --- Secours : mail() PHP (souvent inactif sous XAMPP) --- */
        $ok = @mail($to, $subject, $body, implode("\r\n", $headers));
        if (!$ok) {
            $err = 'mail() indisponible — configurez le SMTP dans Paramètres → E-mail (SMTP).';
            error_log('[garage] mail() failed -> ' . $to);
        }
    }

    if ($ok) {
        log_activity('doc_email_sent', $docModule, $docId, t('log.doc_email', $docNumber !== '' ? $docNumber : 'doc', $to));
    }
    return [$ok, $err];
}
