<?php

declare(strict_types=1);

namespace Cms\Mail;

/**
 * MAIL THROUGH BREVO'S TRANSACTIONAL API, for a site whose mail goes out that
 * way: one HTTPS POST a message, with an API key, and no SMTP at all.
 *
 * CHOSEN IN config.php ONLY, with its key:
 *
 *     'mail_transport' => 'brevo',
 *     'brevo_api_key'  => 'xkeysib-…',
 *
 * The key is never in the database, the admin, a log or the repository; the
 * site's config.php is the one place it is. With it set, every message the
 * CMS sends goes this way (`Mailer::send()`), from the From address on
 * Settings → Email, which Brevo must know as a sender of the account's.
 *
 * Sent is a 2xx answer. Anything else is not sent: `send()` returns false and
 * logs Brevo's status and the start of its answer, never the key. A
 * submission's stored "notified" flag is that result.
 */
final class Brevo
{
    /** Brevo's endpoint for one transactional message. */
    public const URL = 'https://api.brevo.com/v3/smtp/email';

    /** Seconds for the whole request, and for the connection alone. */
    private const TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 5;

    /** How much of Brevo's answer a failure logs. */
    private const LOGGED = 200;

    /** Why the last message was not sent, for a screen to show; '' after one that was. */
    private string $error = '';

    private function __construct(private string $key, private string $url)
    {
    }

    /** Why the last `send()` failed, as it was logged (never the key); '' when it did not. */
    public function error(): string
    {
        return $this->error;
    }

    /**
     * The transport config.php chooses, or null when it chooses none
     * (`mail_transport` is not 'brevo'). A missing key is still Brevo: every
     * send then fails and says why, rather than quietly going another way.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): ?self
    {
        if (strtolower(trim((string) ($config['mail_transport'] ?? ''))) !== 'brevo') {
            return null;
        }
        // `brevo_api_url` is left out of config.example.php on purpose: it
        // points the transport at another endpoint, such as a local stand-in
        // that records requests for a test. A site has no reason to set it.
        $url = trim((string) ($config['brevo_api_url'] ?? ''));

        return new self(trim((string) ($config['brevo_api_key'] ?? '')), $url !== '' ? $url : self::URL);
    }

    /** Whether config.php gives a key. */
    public function hasKey(): bool
    {
        return $this->key !== '';
    }

    /**
     * Send one message as plain text. True when Brevo answered 2xx.
     *
     * @param array{email: string, name?: string}       $sender
     * @param list<array{email: string, name?: string}> $to
     * @param array{email: string, name?: string}|null  $replyTo
     */
    public function send(array $sender, array $to, ?array $replyTo, string $subject, string $text): bool
    {
        $this->error = '';
        if ($this->key === '') {
            return $this->fail('config.php sends mail through Brevo and gives no brevo_api_key; nothing was sent');
        }
        if (!function_exists('curl_init')) {
            return $this->fail('config.php sends mail through Brevo, and this server\'s PHP has no curl');
        }

        $payload = ['sender' => self::party($sender), 'to' => array_map([self::class, 'party'], $to)];
        if ($replyTo !== null) {
            $payload['replyTo'] = self::party($replyTo);
        }
        $payload['subject']     = self::oneLine($subject);
        $payload['textContent'] = $text;
        // A BYTE THAT IS NOT UTF-8 BECOMES U+FFFD. Without the flag the whole
        // message encodes to false; with one that drops it, the body can be
        // empty, which Brevo refuses with a 400.
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return $this->fail('a message for Brevo could not be encoded: ' . json_last_error_msg());
        }

        $handle = curl_init($this->url);
        if ($handle === false) {
            return $this->fail('curl could not start a request to Brevo');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $this->key,
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            // The key goes with the request: the certificate is checked.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $answer = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);

        if ($status >= 200 && $status < 300) {
            return true;
        }
        if ($answer === false || $status === 0) {
            return $this->fail('Brevo could not be reached: ' . $this->clean($error !== '' ? $error : 'no answer'));
        }

        return $this->fail('Brevo answered HTTP ' . $status . ': ' . $this->clean(substr((string) $answer, 0, self::LOGGED)));
    }

    /** Not sent, and why: kept for `error()` and logged, never with the key. */
    private function fail(string $why): bool
    {
        $this->error = $this->clean($why);
        error_log('CMS mail error: ' . $this->error);

        return false;
    }

    /**
     * One sender or recipient, as Brevo takes it: the address, and the name
     * only when there is one.
     *
     * @param array{email: string, name?: string} $party
     * @return array{email: string, name?: string}
     */
    private static function party(array $party): array
    {
        $out  = ['email' => trim((string) $party['email'])];
        $name = self::oneLine((string) ($party['name'] ?? ''));
        if ($name !== '') {
            $out['name'] = $name;
        }
        return $out;
    }

    /** Text with no line break or control character in it. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }

    /** What is logged: one line, and never the key, whatever an answer echoes. */
    private function clean(string $text): string
    {
        return self::oneLine($this->key !== '' ? str_replace($this->key, '[key]', $text) : $text);
    }
}
