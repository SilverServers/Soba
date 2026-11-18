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
 * ── UNLESS config.php SENDS IT THROUGH BREVO ────────────────────────────────
 * `'mail_transport' => 'brevo'` with its `brevo_api_key` sends every message
 * through Brevo's API instead (`Mail\Brevo`), and the SMTP settings do not
 * apply. The sender is still Settings → Email's.
 *
 * ── OR KEEPS IT IN A LOG ────────────────────────────────────────────────────
 * `'mail_transport' => 'log'` sends nothing: every message, composed raw ones
 * included, is written to admin/logs/mail.log (`Mail\MailLog`), for a staging
 * copy or a development install. The SMTP settings do not apply there either.
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
    /** Why the last message was not sent; '' after one that was. */
    private static string $lastError = '';

    /** How the last message went, whether it did, and its own id: `lastResult()`. */
    private static string $lastVia = '';
    private static bool $lastSent = false;
    private static string $lastId = '';

    /**
     * WHY THE LAST MESSAGE WAS NOT SENT, in words a screen can show (Settings
     * → Email's test, a submission sent again): the transport's own reason —
     * Brevo's status and the start of its answer, the SMTP server's refusal —
     * never a key or a password. '' when the last one went.
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * WHAT HAPPENED TO THE LAST MESSAGE, for a record a screen can show (a
     * form submission's emails): the way it went (brevo, smtp, server or log,
     * as `transport()` names them; '' when it went nowhere), whether it was
     * sent (or kept, in the log), the id its transport gave it (Brevo's
     * messageId, the Message-ID the mail program sent it under; '' where
     * there is none) and why it was not (`lastError()`).
     *
     * @return array{transport: string, sent: bool, id: string, error: string}
     */
    public static function lastResult(): array
    {
        return ['transport' => self::$lastVia, 'sent' => self::$lastSent, 'id' => self::$lastId, 'error' => self::$lastError];
    }

    /**
     * No message yet, as far as `lastResult()` says: for a caller about to ask
     * something else to send, which may send nothing at all (a form with no
     * address to send to), so that what it reads after is that one's result.
     */
    public static function clearResult(): void
    {
        self::$lastError = '';
        self::went('', false);
    }

    /** The last message went (or not) this way, under this id: `lastResult()`'s. */
    private static function went(string $via, bool $sent, string $id = ''): bool
    {
        self::$lastVia  = $via;
        self::$lastSent = $sent;
        self::$lastId   = $sent ? mb_substr(trim($id), 0, 255) : '';

        return $sent;
    }

    /**
     * Send one message.
     *
     * @param list<string>         $to      addresses; invalid ones are skipped
     * @param array{reply_to?: string, reply_to_name?: string, from_email?: string, from_name?: string, to_names?: array<string, string>} $opts
     *        `to_names`: a recipient's name, by its address
     * @return bool false when nothing was sent, for any reason
     */
    public static function send(array $to, string $subject, string $body, array $opts = []): bool
    {
        self::$lastError = '';
        self::went('', false);
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
            self::$lastError = 'there is no address to send it to';
            return false;
        }
        $toNames = is_array($opts['to_names'] ?? null) ? $opts['to_names'] : [];

        $log = self::log();
        if ($log !== null) {
            return self::sendLog($log, $recipients, $toNames, $subject, $body, $opts);
        }
        $brevo = Brevo::fromConfig(self::config());
        if ($brevo !== null) {
            return self::sendBrevo($brevo, $recipients, $toNames, $subject, $body, $opts);
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
                $mail->addAddress($address, trim((string) ($toNames[$address] ?? '')));
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

            return self::went($smtpHost !== '' ? 'smtp' : 'server', true, $mail->getLastMessageID());
        } catch (PHPMailerException $e) {
            error_log('CMS mail error: ' . $e->getMessage());
            self::$lastError = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : 'the mail program gave no reason';

            return self::went((string) Option::get('smtp_host', '') !== '' ? 'smtp' : 'server', false);
        }
    }

    /**
     * The same message through Brevo (`send()`'s, already checked): from
     * Settings → Email's sender, or the site's own `noreply@` when it names
     * none, as the server's mail goes.
     *
     * @param list<string>          $recipients
     * @param array<string, string> $toNames
     * @param array<string, mixed>  $opts
     */
    private static function sendBrevo(Brevo $brevo, array $recipients, array $toNames, string $subject, string $body, array $opts): bool
    {
        $fromEmail = trim((string) ($opts['from_email'] ?? Option::get('smtp_from_email', '')));
        $fromEmail = $fromEmail !== '' ? $fromEmail : self::defaultFrom();
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            self::$lastError = 'mail goes through Brevo and there is no From address to send it from (Settings → Email)';
            error_log('CMS mail error: ' . self::$lastError);
            return self::went('brevo', false);
        }
        $fromName = trim((string) ($opts['from_name'] ?? (Option::get('smtp_from_name', '') ?: \Cms\SEO\Head::globalTokens()['site_name'])));

        $to = [];
        foreach ($recipients as $address) {
            $to[] = ['email' => $address, 'name' => trim((string) ($toNames[$address] ?? ''))];
        }
        $replyTo = trim((string) ($opts['reply_to'] ?? ''));
        $reply   = $replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)
            ? ['email' => $replyTo, 'name' => trim((string) ($opts['reply_to_name'] ?? ''))]
            : null;

        $sent = $brevo->send(['email' => $fromEmail, 'name' => $fromName], $to, $reply, $subject, $body);
        self::$lastError = $sent ? '' : $brevo->error();

        return self::went('brevo', $sent, $brevo->messageId());
    }

    /**
     * The same message kept in the mail log (`send()`'s, already checked),
     * with the sender, recipients and Reply-To the server's own mail would
     * have given it.
     *
     * @param list<string>          $recipients
     * @param array<string, string> $toNames
     * @param array<string, mixed>  $opts
     */
    private static function sendLog(MailLog $log, array $recipients, array $toNames, string $subject, string $body, array $opts): bool
    {
        $fromEmail = trim((string) ($opts['from_email'] ?? Option::get('smtp_from_email', '')));
        $fromEmail = $fromEmail !== '' ? $fromEmail : self::defaultFrom();
        $fromName  = trim((string) ($opts['from_name'] ?? (Option::get('smtp_from_name', '') ?: \Cms\SEO\Head::globalTokens()['site_name'])));

        $headers = $fromEmail !== '' ? ['From: ' . MailLog::address($fromEmail, $fromName)] : [];
        $headers[] = 'To: ' . implode(', ', array_map(
            static fn (string $address): string => MailLog::address($address, trim((string) ($toNames[$address] ?? ''))), $recipients));
        $replyTo = trim((string) ($opts['reply_to'] ?? ''));
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . MailLog::address($replyTo, trim((string) ($opts['reply_to_name'] ?? '')));
        }
        $headers[] = 'Subject: ' . trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject));
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        $kept = $log->write($fromEmail, $headers, $body);
        self::$lastError = $kept ? '' : $log->error();

        return self::went('log', $kept);
    }

    /** The mail log, when config.php keeps mail in one instead of sending it. */
    private static function log(): ?MailLog
    {
        return MailLog::fromConfig(self::config(), defined('ROOT_PATH') ? ROOT_PATH . '/logs' : '');
    }

    /**
     * Where mail goes, for Settings → Email to say: 'log' when config.php
     * keeps it in the mail log, 'brevo' when it sends it through Brevo, 'smtp'
     * when Settings → Email names a host, and 'server' for the server's own
     * mail program.
     */
    public static function transport(): string
    {
        if (self::log() !== null) {
            return 'log';
        }
        if (Brevo::fromConfig(self::config()) !== null) {
            return 'brevo';
        }
        return (string) Option::get('smtp_host', '') !== '' ? 'smtp' : 'server';
    }

    /**
     * Where mail goes, in words: "the mail log (admin/logs/mail.log)",
     * "Brevo", "the SMTP server mail.example.com", "the server's own mail
     * program".
     */
    public static function transportName(): string
    {
        return match (self::transport()) {
            'log'   => 'the mail log (admin/logs/' . MailLog::FILE . ')',
            'brevo' => 'Brevo',
            'smtp'  => 'the SMTP server ' . trim((string) Option::get('smtp_host', '')),
            default => "the server's own mail program",
        };
    }

    /** Whether config.php gives Brevo a key, for Settings → Email to say. */
    public static function brevoHasKey(): bool
    {
        return Brevo::fromConfig(self::config())?->hasKey() ?? false;
    }

    /** config.php, read once; empty where there is none. @return array<string, mixed> */
    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $read   = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            $config = is_array($read) ? $read : [];
        }
        return $config;
    }

    /**
     * Send a message a site composed itself, exactly as written
     * (`Forms\Notify`): its own headers and
     * MIME body, through the server's own mail program, which is how a
     * site's own lead handler may already send. The SMTP settings do not apply.
     * With mail kept in the log, it is written there as composed instead.
     *
     * The composer is the site's own trusted code, but the subject is still
     * refused if it holds a line break that is not header folding (CRLF and a
     * space or tab): that is what a header injection looks like.
     *
     * @param list<string> $to  addresses; invalid ones are skipped
     */
    public static function sendRaw(array $to, string $subject, string $headers, string $body, string $envelopeFrom = ''): bool
    {
        self::$lastError = '';
        self::went('', false);
        $recipients = array_values(array_filter(array_map('trim', $to),
            static fn (string $a): bool => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($recipients === []) {
            self::$lastError = 'there is no address to send it to';
            return false;
        }
        if (preg_match('/\r\n(?![ \t])|\r(?!\n)|(?<!\r)\n/', $subject) === 1) {
            self::$lastError = 'the site\'s composed subject has a line break in it';
            return false;
        }
        $log = self::log();
        if ($log !== null) {
            // AS mail() WOULD HAND IT ON: the recipients and subject, then the
            // composer's own headers, a folded one kept with the line it
            // continues.
            $lines = ['To: ' . implode(', ', $recipients), 'Subject: ' . (string) preg_replace('/\r\n[ \t]+/', ' ', $subject)];
            foreach (preg_split('/\r\n(?![ \t])|\r(?!\n)|(?<!\r)\n(?![ \t])/', trim($headers, "\r\n")) ?: [] as $line) {
                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }
            $kept = $log->write(filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL) !== false ? $envelopeFrom : '', $lines, $body);
            self::$lastError = $kept ? '' : $log->error();

            return self::went('log', $kept);
        }
        $params = $envelopeFrom !== '' && filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL) ? '-f' . $envelopeFrom : '';
        $sent   = @mail(implode(', ', $recipients), $subject, $body, $headers, $params);
        if (!$sent) {
            self::$lastError = 'the server\'s mail program refused the site\'s composed message';
            error_log('Mail error: the server\'s mail program refused a composed message');
        }

        // ITS ID IS THE ONE ITS COMPOSER WROTE, when it wrote one.
        return self::went('server', $sent, preg_match('/^Message-ID:[ \t]*(\S[^\r\n]*)/mi', $headers, $m) === 1 ? $m[1] : '');
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
