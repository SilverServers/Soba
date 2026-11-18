<?php

declare(strict_types=1);

namespace Cms\Mail;

/**
 * MAIL KEPT IN A FILE, NOT SENT, for a staging copy or a development install.
 * Chosen in config.php only:
 *
 *     'mail_transport' => 'log',
 *
 * Every message the CMS would send (`Mailer::send()`, `Mailer::sendRaw()`) is
 * written to admin/logs/mail.log instead, and nothing leaves the server.
 *
 * A MESSAGE WRITTEN IS A MESSAGE HANDLED: the caller hears it was sent, so a
 * submission is stored as notified and an order's notes say its email went,
 * as they would on the site the copy was made from. Only a file that cannot
 * be written is a failure, said the way any transport's is.
 *
 * THE FILE IS AN MBOX (RFC 4155): each message starts with a "From " line,
 * then its headers, a blank line and its body, with a body line that starts
 * "From " written as ">From ". Any mail reader opens it, and it reads as text.
 *
 * It holds every message in full, sign-in links and codes included, so it is
 * the site's alone: admin/ is never served, and an update never touches
 * admin/logs/.
 */
final class MailLog
{
    /** The file, in admin/logs/. */
    public const FILE = 'mail.log';

    /** Why the last message was not written; '' after one that was. */
    private string $error = '';

    private function __construct(private string $dir)
    {
    }

    /**
     * The log config.php chooses, kept in `$dir`, or null when it chooses
     * another transport (`mail_transport` is not 'log').
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, string $dir): ?self
    {
        if (strtolower(trim((string) ($config['mail_transport'] ?? ''))) !== 'log') {
            return null;
        }

        return new self(rtrim($dir, '/'));
    }

    /** Why the last `write()` failed, as it was logged; '' when it did not. */
    public function error(): string
    {
        return $this->error;
    }

    /** The file the messages go to. */
    public function path(): string
    {
        return $this->dir . '/' . self::FILE;
    }

    /**
     * One address as a header carries it: `Name <address>`, or the address
     * alone when there is no name.
     */
    public static function address(string $email, string $name = ''): string
    {
        $name = self::oneLine($name);

        return $name !== '' ? '"' . addcslashes($name, '"\\') . '" <' . $email . '>' : $email;
    }

    /**
     * Write one message. True when it is in the file.
     *
     * @param string       $sender   the envelope's sender, for the "From " line ('' for none)
     * @param list<string> $headers  "Name: value" lines, as the message carries them
     */
    public function write(string $sender, array $headers, string $body): bool
    {
        $this->error = '';
        $sender = self::oneLine($sender);
        $lines  = ['From ' . ($sender !== '' && !str_contains($sender, ' ') ? $sender : 'MAILER-DAEMON') . ' ' . gmdate('D M j H:i:s Y')];
        $dated  = false;
        foreach ($headers as $header) {
            // A header that holds a line break is kept as one folded header,
            // never as a second header or the start of the body.
            $header  = (string) preg_replace('/\r\n|\r/', "\n", rtrim($header, "\r\n"));
            $lines[] = (string) preg_replace('/\n(?![ \t])/', "\n ", $header);
            $dated   = $dated || stripos($header, 'Date:') === 0;
        }
        if (!$dated) {
            $lines[] = 'Date: ' . date(DATE_RFC2822);
        }
        $text    = (string) preg_replace('/\r\n|\r/', "\n", $body);
        // ">From " FOR "From ": a body line that begins as a message does
        // (or as one already escaped) gains one ">", so the file splits where
        // its messages do.
        $text    = (string) preg_replace('/^(>*From )/m', '>$1', $text);
        $entry   = implode("\n", $lines) . "\n\n" . rtrim($text, "\n") . "\n\n";

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return $this->fail('config.php keeps mail in admin/logs/' . self::FILE . ', and the folder admin/logs could not be made; nothing was sent');
        }
        if (@file_put_contents($this->path(), $entry, FILE_APPEND | LOCK_EX) === false) {
            return $this->fail('config.php keeps mail in admin/logs/' . self::FILE . ', and it could not be written; nothing was sent');
        }

        return true;
    }

    /** Not written, and why: kept for `error()` and logged. */
    private function fail(string $why): bool
    {
        $this->error = $why;
        error_log('CMS mail error: ' . $why);

        return false;
    }

    /** Text with no line break or control character in it. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }
}
