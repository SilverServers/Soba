<?php

declare(strict_types=1);

namespace Cms\Mail;

use Cms\Models\Option;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * THE ONE PLACE THAT SENDS MAIL.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 * Forms and orders both send mail. With a transport each, Settings → Email
 * would configure one of them and silently not the other, and nobody would find
 * out until a shop's confirmations stopped arriving.
 *
 * It owns the TRANSPORT and nothing else. Each caller composes its own subject
 * and body, because a form notification and an order confirmation have nothing
 * in common but the envelope. One mechanism means one transport, not two
 * different messages pretending to be one.
 *
 * ── AN EMPTY SMTP HOST IS THE SWITCH, NOT A MISCONFIGURATION ────────────────
 * Blank means "use the server's own mail program", which is what PHPMailer does
 * when `isSMTP()` is never called, and what the Settings → Email hint says.
 *
 * ── AND IT NEVER THROWS ─────────────────────────────────────────────────────
 * `send()` returns false and logs. A mail server that is down must not take the
 * order with it: the money and the customer's address are already committed by
 * the time anything is sent, and an exception here would show the buyer a 500
 * for an order that was placed successfully — they would order again.
 *
 * The RETURN VALUE is the point of it being a bool rather than void. The form
 * path has nowhere to put a failure and swallows it; the order path writes
 * "the confirmation could not be sent" into the order's own notes, so the owner
 * finds out on the screen they already read rather than in a log they do not.
 */
final class Mailer
{
    /**
     * Send one message.
     *
     * @param list<string>         $to      addresses; invalid ones are skipped
     * @param array{reply_to?: string, reply_to_name?: string, from_email?: string, from_name?: string} $opts
     * @return bool false when nothing was sent, for any reason
     */
    public static function send(array $to, string $subject, string $body, array $opts = []): bool
    {
        $recipients = [];
        foreach ($to as $address) {
            $address = trim((string) $address);
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $address;
            }
        }

        // NO VALID RECIPIENT IS NOT AN ERROR TO LOG LOUDLY. A form with no
        // notification address configured is a perfectly ordinary form, and a
        // shop with no admin address set has simply not set one. Returning
        // false lets the caller decide whether that matters.
        if ($recipients === []) {
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $smtpHost = (string) Option::get('smtp_host', '');
            if ($smtpHost !== '') {
                $mail->isSMTP();
                $mail->Host     = $smtpHost;
                $mail->SMTPAuth = true;
                $mail->Username = (string) Option::get('smtp_username', '');
                $mail->Password = (string) Option::get('smtp_password', '');
                $mail->Port     = (int) Option::get('smtp_port', '587');

                $encryption = (string) Option::get('smtp_encryption', 'tls');
                if ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                }
            }

            $fromEmail = trim((string) ($opts['from_email'] ?? Option::get('smtp_from_email', '')));
            $fromName  = trim((string) ($opts['from_name']  ?? (Option::get('smtp_from_name', '') ?: \Cms\SEO\Head::globalTokens()['site_name'])));
            if ($fromEmail !== '') {
                $mail->setFrom($fromEmail, $fromName);
                // THE ENVELOPE FOLLOWS THE FROM:
                // a relay that authenticates the sender checks the envelope,
                // and the server's default — the web user at the host — is
                // refused.
                $mail->Sender = $fromEmail;
            } elseif (($fallback = self::defaultFrom()) !== '') {
                // NOBODY NAMED IN SETTINGS → EMAIL: the site's own domain
                // (`defaultFrom()`), never an empty From header. The envelope
                // stays the server's — nobody chose an address to vouch for.
                $mail->setFrom($fallback, $fromName, false);
            }

            foreach ($recipients as $address) {
                $mail->addAddress($address);
            }

            $replyTo = trim((string) ($opts['reply_to'] ?? ''));
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                // Under the sender's name, so a reply is addressed to a person.
                $mail->addReplyTo($replyTo, trim((string) ($opts['reply_to_name'] ?? '')));
            }

            $mail->Subject = $subject;
            $mail->Body    = $body;
            // PLAIN TEXT, DELIBERATELY. An HTML email is a second rendering of
            // every message in this system, with its own escaping to get wrong
            // and its own inlined CSS to maintain against a dozen clients that
            // each support a different subset. Nothing here needs it, and a
            // receipt that is a legible plain-text table is not a worse receipt.
            $mail->isHTML(false);
            $mail->CharSet = 'UTF-8';

            $mail->send();

            return true;
        } catch (PHPMailerException $e) {
            error_log('CMS mail error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Send a message a site composed itself, exactly as written
     * (`Forms\Notify`): its own headers and
     * MIME body, through the server's own mail program, which is how a
     * site's own lead handler may already send. The SMTP settings do not apply.
     *
     * The composer is the site's own trusted code, but the subject is still
     * refused if it holds a line break that is not header folding (CRLF and a
     * space or tab): that is what a header injection looks like.
     *
     * @param list<string> $to  addresses; invalid ones are skipped
     */
    public static function sendRaw(array $to, string $subject, string $headers, string $body, string $envelopeFrom = ''): bool
    {
        $recipients = array_values(array_filter(array_map('trim', $to),
            static fn (string $a): bool => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($recipients === [] || preg_match('/\r\n(?![ \t])|\r(?!\n)|(?<!\r)\n/', $subject) === 1) {
            return false;
        }
        $params = $envelopeFrom !== '' && filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL) ? '-f' . $envelopeFrom : '';
        $sent   = @mail(implode(', ', $recipients), $subject, $body, $headers, $params);
        if (!$sent) {
            error_log('Mail error: the server\'s mail program refused a composed message');
        }

        return $sent;
    }

    /**
     * Who mail is from when Settings → Email names nobody: noreply@ the site's
     * own domain. Left empty, PHPMailer writes an empty From header, which a
     * receiving server refuses.
     * '' when the site has no domain to send from (an IP address, a bare host
     * name).
     *
     * A SENDER, NOT A RECIPIENT: nothing is sent to this address and replies
     * go to Reply-To, so it is not the guessed `admin@` `adminAddress()` will
     * not write to.
     */
    public static function defaultFrom(): string
    {
        $host = strtolower((string) parse_url(defined('BASE_URL') ? (string) BASE_URL : '', PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);
        if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return '';
        }
        $address = 'noreply@' . $host;

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '';
    }

    /**
     * The address the shop writes to when it has something to report.
     *
     * Falls back through: an explicitly configured shop address, then the
     * sender address from Settings → Email, then nothing. NOT to a guessed
     * `admin@` — a bounced order notification is worse than a missing one,
     * because it looks like it was delivered.
     */
    public static function adminAddress(): string
    {
        foreach (['commerce_admin_email', 'smtp_from_email'] as $key) {
            $value = trim((string) Option::get($key, ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return '';
    }
}
