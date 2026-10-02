<?php
// Email delivery - the transport layer.
//
// Two interchangeable backends behind one mailer_send():
//   * SMTP     - the real thing (Gmail app password or any relay), via PHPMailer
//                when that optional library is installed.
//   * outbox   - writes a readable .eml per message into data/outbox/.
//
// The fallback is not a stub: every notification path is fully exercisable with
// no credentials at all, which is what keeps real passwords out of git history.
// Sending is synchronous (after the queue transaction commits) - PHP's request
// model has no place for the background thread the Flask app used, so the route
// pays the SMTP handshake. A send failure is logged, never fatal.

declare(strict_types=1);

const MAILER_MAX_ATTEMPTS = 3;
const MAILER_RETRY_DELAYS = [2, 8]; // seconds, between attempts 1-2 and 2-3 (SMTP only)

/** A message will never be deliverable (bad auth, refused recipient) - no retry. */
class MailerPermanentError extends RuntimeException {}

/** Build a message record. entry_id may be null (e.g. a general notice). */
function mailer_message(string $to, string $subject, string $body, string $event, ?int $entry_id = null): array
{
    return ['to' => $to, 'subject' => $subject, 'body' => $body, 'event' => $event, 'entry_id' => $entry_id];
}

/** Render one message as an RFC-822 text/plain email (headers, blank line, body). */
function mailer_compose(array $message, string $sender, string $from_name): string
{
    $from = $from_name !== '' ? sprintf('%s <%s>', $from_name, $sender) : $sender;
    $message_id = '<' . bin2hex(random_bytes(16)) . '@freelancequeue.local>';
    $headers = [
        'From: ' . $from,
        'To: ' . $message['to'],
        'Subject: ' . $message['subject'],
        'Date: ' . date('r'),                 // RFC 2822, local time
        'Message-ID: ' . $message_id,
        'Auto-Submitted: auto-generated',     // stops vacation autoresponders
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    // RFC-822 line endings are CRLF; the body's own \n are normalised too.
    $body = str_replace(["\r\n", "\r", "\n"], "\r\n", $message['body']);
    return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n";
}

/**
 * True when SMTP is switched on AND has somewhere to connect. A half-filled
 * [smtp] section falls back to the outbox rather than failing every send.
 */
function mailer_smtp_selected(Settings $s): bool
{
    return $s->smtp_enabled && $s->smtp_host !== '' && $s->smtp_username !== '';
}

/** A short human label for logs / the mailer status line. */
function mailer_backend_label(Settings $s): string
{
    if (mailer_smtp_selected($s)) {
        return sprintf('smtp://%s:%d', $s->smtp_host, $s->smtp_port);
    }
    return 'outbox://' . OUTBOX_DIR;
}

/**
 * Deliver one message. Throws MailerPermanentError for un-retryable failures
 * and RuntimeException for transient ones (so the caller can retry).
 */
function mailer_send(array $message): void
{
    $s = settings();
    if (mailer_smtp_selected($s)) {
        mailer_send_smtp($message, $s);
        return;
    }
    mailer_send_outbox($message, $s);
}

/** Write the message to data/outbox/ as a readable .eml file. */
function mailer_send_outbox(array $message, Settings $s): void
{
    if (!is_dir(OUTBOX_DIR) && !mkdir(OUTBOX_DIR, 0775, true) && !is_dir(OUTBOX_DIR)) {
        throw new RuntimeException('Could not create outbox directory: ' . OUTBOX_DIR);
    }
    $eml = mailer_compose($message, $s->senderAddress(), $s->smtp_from_name);
    // Microseconds in the name keep two notifications fired by the same queue
    // mutation from overwriting each other.
    $stamp = (new DateTime('now'))->format('Ymd-His-u');
    $safe_to = substr((string)preg_replace('/[^A-Za-z0-9._@-]+/', '_', $message['to']), 0, 60);
    $safe_event = (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $message['event']);
    $path = OUTBOX_DIR . DIRECTORY_SEPARATOR . "{$stamp}-{$safe_event}-{$safe_to}.eml";
    if (file_put_contents($path, $eml) === false) {
        throw new RuntimeException('Could not write outbox file: ' . $path);
    }
}

/**
 * Send over SMTP using PHPMailer, loaded only when SMTP is enabled so the
 * default (outbox) demo needs no external library. If PHPMailer is not
 * installed, this is a permanent failure with an actionable message.
 */
function mailer_send_smtp(array $message, Settings $s): void
{
    $autoload = PROJECT_ROOT . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new MailerPermanentError(
            'SMTP is enabled but PHPMailer is not installed. Run '
            . '"composer require phpmailer/phpmailer" or disable [smtp].'
        );
    }
    require_once $autoload;
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new MailerPermanentError('PHPMailer classes not found after autoload.');
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $s->smtp_host;
        $mail->Port       = $s->smtp_port;
        $mail->SMTPAuth   = $s->smtp_username !== '';
        $mail->Username   = $s->smtp_username;
        $mail->Password   = $s->smtp_password;
        $mail->Timeout    = 20;
        if ($s->smtp_port === 465) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($s->smtp_use_tls) {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($s->senderAddress(), $s->smtp_from_name);
        $mail->addAddress($message['to']);
        $mail->Subject = $message['subject'];
        $mail->Body    = $message['body'];
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // Authentication / recipient problems will not fix themselves on retry.
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'authenticate') || str_contains($msg, 'recipient') || str_contains($msg, 'sender')) {
            throw new MailerPermanentError($e->getMessage(), 0, $e);
        }
        throw new RuntimeException($e->getMessage(), 0, $e);
    }
}
