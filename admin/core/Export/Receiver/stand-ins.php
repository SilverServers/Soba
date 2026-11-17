<?php

declare(strict_types=1);

/**
 * THE CMS'S OWN CLASS NAMES, AS A STATIC COPY'S FORM HANDLER ANSWERS THEM.
 *
 * The copy's handler (`forms/send.php` in the copy) runs the CMS's form classes
 * as they are — the guard, the captcha, the field types, the logic, the
 * answers, the notification — so a form in the copy is checked and mailed the
 * way the site checks and mails it. Those classes ask a few others for what a
 * site has: its settings, its submissions, its forms, its theme, its mailer.
 * The copy has no database and no CMS, so each of those is answered here: from
 * what the export wrote down (`config/config.php`, `config/forms.php`, through
 * `Receiver\Handler`) and from the handler's own files (`Receiver\Store`).
 *
 * Each class has only the calls the copied classes make. A call one of them
 * gains that is not answered here is a fatal in every copy made after it, so
 * a change to those classes is a change to this file too.
 *
 * NEVER LOADED BY THE CMS. These are the CMS's own class names: only the
 * copy's `send.php` requires this file, before anything asks for one of them.
 * Composer's class map leaves them out, as they are not where their names say
 * (PSR-4).
 */

namespace Cms\Models {

    use Cms\Export\Receiver\Handler;
    use Cms\Export\Receiver\Store;

    /** The site's settings, as the export wrote them down: the ones the form classes read, and nothing secret. */
    final class Option
    {
        public static function get(string $key, ?string $default = null): ?string
        {
            $options = Handler::config()['options'] ?? [];
            return array_key_exists($key, $options) && $options[$key] !== null ? (string) $options[$key] : $default;
        }

        /** @param array<string, string|null> $keysWithDefaults */
        public static function getMany(array $keysWithDefaults): array
        {
            $out = [];
            foreach ($keysWithDefaults as $key => $default) {
                $out[$key] = self::get((string) $key, $default === null ? null : (string) $default);
            }
            return $out;
        }
    }

    /** The submissions the guard asks about, as the handler's files hold them. */
    final class FormSubmission
    {
        public static function byToken(string $hash): ?int
        {
            return Store::receivedWith($hash) ? 1 : null;
        }

        public static function spamWithToken(string $hash): int
        {
            return Store::spamWith($hash);
        }

        public static function receivedFromSince(string $ip, int $minutes): int
        {
            return Store::count($ip, 'received', $minutes);
        }

        public static function rateKeptSince(string $ip, int $minutes): int
        {
            return Store::count($ip, 'rate', $minutes);
        }
    }

    /** The copy's forms, as the export wrote them down. */
    final class Form
    {
        public static function find(int $id): ?array
        {
            return Handler::forms()[$id] ?? null;
        }

        public static function all(): array
        {
            return array_values(Handler::forms());
        }
    }
}

namespace Cms\Helpers {

    use Cms\Export\Receiver\Handler;

    /** A copy has no theme to run: a site's own hook file is not carried into it. */
    final class Theme
    {
        public static function file(string $name): ?string
        {
            return null;
        }
    }

    /** Every module the copy's forms use was on when the copy was made. */
    final class Modules
    {
        public static function enabled(string $module): bool
        {
            return true;
        }
    }

    final class Asset
    {
        public static function sitePath(string $relative): string
        {
            return rtrim((string) (Handler::config()['base_path'] ?? ''), '/') . '/' . ltrim($relative, '/');
        }
    }
}

namespace Cms\Mail {

    use Cms\Export\Receiver\Handler;
    use PHPMailer\PHPMailer\Exception as PHPMailerException;
    use PHPMailer\PHPMailer\PHPMailer;

    /**
     * THE SITE'S MAILER, AS THE COPY SENDS: through the host's own mail
     * program, with the sender Settings → Email names, or `noreply@` the
     * copy's own domain. No password leaves the CMS with a copy, so its SMTP
     * server or mail service is not used unless somebody types one into
     * `config/config.php` here. The visitor's files go with the message
     * (`attach()`), as there is no media library to keep them in.
     */
    final class Mailer
    {
        /** @var list<array{0: string, 1: string}> path, name — for the next message only */
        private static array $files = [];

        /** @param list<array{0: string, 1: string}> $files */
        public static function attach(array $files): void
        {
            self::$files = $files;
        }

        public static function send(array $to, string $subject, string $body, array $opts = []): bool
        {
            $files = self::$files;
            self::$files = [];

            $recipients = [];
            foreach ($to as $address) {
                $address = trim((string) $address);
                if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $address;
                }
            }
            if ($recipients === []) {
                return false;
            }

            $config = Handler::config();
            $mail   = new PHPMailer(true);
            try {
                $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
                if (trim((string) ($smtp['host'] ?? '')) !== '' && (string) ($smtp['password'] ?? '') !== '') {
                    $mail->isSMTP();
                    $mail->Host     = (string) $smtp['host'];
                    $mail->SMTPAuth = true;
                    $mail->Username = (string) ($smtp['username'] ?? '');
                    $mail->Password = (string) $smtp['password'];
                    $mail->Port     = (int) ($smtp['port'] ?? 587);
                    $encryption     = (string) ($smtp['encryption'] ?? 'tls');
                    if ($encryption === 'tls') {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    } elseif ($encryption === 'ssl') {
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    }
                }

                $fromEmail = trim((string) ($opts['from_email'] ?? \Cms\Models\Option::get('smtp_from_email', '')));
                $fromName  = trim((string) ($opts['from_name'] ?? ((\Cms\Models\Option::get('smtp_from_name', '') ?: (string) ($config['site_name'] ?? '')))));
                if ($fromEmail !== '') {
                    $mail->setFrom($fromEmail, $fromName);
                    $mail->Sender = $fromEmail;
                } elseif (($fallback = self::defaultFrom()) !== '') {
                    $mail->setFrom($fallback, $fromName, false);
                }

                $toNames = is_array($opts['to_names'] ?? null) ? $opts['to_names'] : [];
                foreach ($recipients as $address) {
                    $mail->addAddress($address, trim((string) ($toNames[$address] ?? '')));
                }

                $replyTo = trim((string) ($opts['reply_to'] ?? ''));
                if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                    $mail->addReplyTo($replyTo, trim((string) ($opts['reply_to_name'] ?? '')));
                }

                foreach ($files as [$path, $name]) {
                    $mail->addAttachment($path, $name);
                }

                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->isHTML(false);
                $mail->CharSet = 'UTF-8';
                $mail->send();

                return true;
            } catch (PHPMailerException $e) {
                error_log('Form handler mail error: ' . $e->getMessage());

                return false;
            }
        }

        /** A message a site's own composer wrote: a copy carries no composer, so none arrives here. */
        public static function sendRaw(array $to, string $subject, string $headers, string $body, string $envelopeFrom = ''): bool
        {
            return false;
        }

        public static function defaultFrom(): string
        {
            $host = strtolower((string) parse_url((string) (Handler::config()['address'] ?? ''), PHP_URL_HOST));
            $host = (string) preg_replace('/^www\./', '', $host);
            if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return '';
            }
            $address = 'noreply@' . $host;

            return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : '';
        }

        public static function adminAddress(): string
        {
            $value = trim((string) \Cms\Models\Option::get('smtp_from_email', ''));
            return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
        }
    }
}
