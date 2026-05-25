<?php
/**
 * Helper email per fatturapp.
 *
 * Usa PHP mail() — richiede un MTA configurato sul server (es. Postfix).
 * Per SMTP transazionale (Gmail, Mailgun, ecc.) impostare nel .env:
 *   SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD
 * e questa funzione utilizzerà una connessione socket diretta.
 */

/**
 * Invia un'email HTML (con fallback plain-text automatico).
 */
function sendMail(string $to, string $subject, string $bodyHtml): bool {
    $from      = SMTP_FROM;
    $from_name = SMTP_FROM_NAME;
    $bodyText  = strip_tags(preg_replace('/<br\s*\/?>/', "\n", $bodyHtml));
    $boundary  = md5(uniqid((string)rand(), true));

    $headers  = "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: fatturapp/1.0\r\n";

    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$bodyText}\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$bodyHtml}\r\n";
    $body .= "--{$boundary}--\r\n";

    $smtpHost = getenv('SMTP_HOST');
    if ($smtpHost) {
        return _sendSmtp($to, $subject, $body, $headers, $smtpHost);
    }

    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/** Invio via socket SMTP (TLS/STARTTLS). */
function _sendSmtp(string $to, string $subject, string $body, string $headers, string $host): bool {
    $port = (int)(getenv('SMTP_PORT') ?: 587);
    $user = getenv('SMTP_USERNAME') ?: '';
    $pass = getenv('SMTP_PASSWORD') ?: '';
    $from = SMTP_FROM;

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $proto = ($port === 465) ? 'ssl://' : '';
    $sock = @stream_socket_client("{$proto}{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) { error_log("SMTP connect error: {$errstr}"); return false; }

    $r = fn() => fgets($sock, 512);
    $w = fn(string $s) => fwrite($sock, $s . "\r\n");

    $r(); // greeting
    $w("EHLO fatturapp.it"); for ($i = 0; $i < 5; $i++) { $line = fgets($sock, 512); if ($line && $line[3] === ' ') break; }
    if ($port === 587) {
        $w("STARTTLS"); $r();
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $w("EHLO fatturapp.it"); for ($i = 0; $i < 5; $i++) { $line = fgets($sock, 512); if ($line && $line[3] === ' ') break; }
    }
    $w("AUTH LOGIN"); $r();
    $w(base64_encode($user)); $r();
    $w(base64_encode($pass)); $resp = $r();
    if (!str_starts_with((string)$resp, '235')) { fclose($sock); return false; }

    $w("MAIL FROM:<{$from}>"); $r();
    $w("RCPT TO:<{$to}>"); if (!str_starts_with((string)$r(), '250')) { fclose($sock); return false; }
    $w("DATA"); $r();
    fwrite($sock, "To: {$to}\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n{$headers}\r\n{$body}\r\n.\r\n");
    $r(); $w("QUIT"); fclose($sock);
    return true;
}

/** Template HTML email di verifica account. */
function mailVerificationHtml(string $link): string {
    return _mailTemplate(
        'Verifica il tuo indirizzo email',
        '<p>Grazie per esserti registrato a <strong>fatturapp</strong>!</p>
         <p>Clicca il bottone qui sotto per verificare il tuo indirizzo email e attivare il <strong>trial gratuito di 30 giorni</strong>:</p>',
        'Verifica email e inizia il trial',
        $link,
        'Questo link scade tra 24 ore. Se non hai richiesto la registrazione, ignora questa email.'
    );
}

/** Template HTML email reset password. */
function mailPasswordResetHtml(string $link): string {
    return _mailTemplate(
        'Reset della password',
        '<p>Hai richiesto il reset della password per il tuo account <strong>fatturapp</strong>.</p>',
        'Reimposta password',
        $link,
        'Questo link scade tra 1 ora. Se non hai richiesto il reset, ignora questa email.'
    );
}

/** Template HTML: promemoria trial in scadenza. */
function mailTrialReminderHtml(string $upgradeLink, int $daysLeft): string {
    $urgency = $daysLeft <= 1 ? 'Ultimo giorno!' : ($daysLeft <= 3 ? 'Ultimi giorni!' : "Ancora $daysLeft giorni");
    return _mailTemplate(
        "⏰ Il tuo trial fatturapp scade tra $daysLeft " . ($daysLeft === 1 ? 'giorno' : 'giorni'),
        "<p>Ciao! Il tuo periodo di prova gratuita di <strong>fatturapp Pro</strong> sta per scadere.</p>
         <p><strong>$urgency</strong> — non perdere l'accesso a:</p>
         <ul style='color:#374151;padding-left:20px'>
           <li>Fatture pro-forma illimitate</li>
           <li>Clienti illimitati</li>
           <li>Upload fatture elettroniche PDF + XML</li>
           <li>Statistiche avanzate</li>
         </ul>
         <p>Abbonati a <strong>€ 7/mese</strong> e continua senza interruzioni.</p>",
        '🚀 Attiva Piano Pro — € 7/mese',
        $upgradeLink,
        'Puoi cancellare l\'abbonamento in qualsiasi momento. Nessun impegno a lungo termine.'
    );
}

/** Template HTML: trial scaduto → downgrade a Free. */
function mailTrialExpiredHtml(string $upgradeLink): string {
    return _mailTemplate(
        'Il tuo trial fatturapp è scaduto',
        '<p>Il tuo periodo di prova gratuita di <strong>fatturapp Pro</strong> è terminato.</p>
         <p>Il tuo account è ora sul piano <strong>Free</strong> con le seguenti limitazioni:</p>
         <ul style="color:#374151;padding-left:20px">
           <li>Massimo 3 fatture pro-forma al mese</li>
           <li>Massimo 2 clienti</li>
         </ul>
         <p>Abbonati a <strong>€ 7/mese</strong> per tornare alle funzionalità complete.</p>',
        'Riattiva Piano Pro — € 7/mese',
        $upgradeLink,
        'I tuoi dati esistenti sono al sicuro e rimangono accessibili.'
    );
}

/** Template HTML generico. */
function _mailTemplate(string $title, string $body, string $btnLabel, string $btnLink, string $footer): string {
    $app = 'fatturapp';
    $escaped_link = htmlspecialchars($btnLink, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f6f9;font-family:Arial,sans-serif">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:36px 32px;border:1px solid #e3e8ef">
    <div style="margin-bottom:24px">
      <span style="font-size:1.4rem;font-weight:700;color:#2563eb">{$app}</span>
    </div>
    <h2 style="font-size:1.1rem;margin:0 0 16px;color:#1e293b">{$title}</h2>
    {$body}
    <div style="margin:28px 0">
      <a href="{$escaped_link}"
         style="display:inline-block;background:#2563eb;color:#fff;padding:13px 28px;
                border-radius:6px;text-decoration:none;font-weight:700;font-size:.95rem">
        {$btnLabel}
      </a>
    </div>
    <p style="color:#64748b;font-size:.82rem;margin:0">{$footer}</p>
    <hr style="border:none;border-top:1px solid #f1f5f9;margin:24px 0">
    <p style="color:#94a3b8;font-size:.75rem;margin:0">{$app} · <a href="{$escaped_link}" style="color:#94a3b8">{$escaped_link}</a></p>
  </div>
</body>
</html>
HTML;
}
