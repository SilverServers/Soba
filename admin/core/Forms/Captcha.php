<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Database\Database;
use Cms\Models\Option;

/**
 * CAPTCHA — three providers, one of which needs nothing from anybody.
 *
 * ── THE DEFAULT IS IN-HOUSE, AND THAT IS THE POINT ──────────────────────────
 * reCAPTCHA is a Google script that runs on every page the form is embedded
 * in, watching every visitor, whether or not the form is ever used. The CMS
 * does not SHIP that — nothing is fetched from a third party at runtime.
 *
 * So the built-in challenge is what a Captcha field IS unless somebody changes
 * it, and hCaptcha and reCAPTCHA are there for a site owner who wants them. A
 * third-party script is never in the page because the CMS put it there. It is
 * in the page because the person who owns the site went to Settings, chose a
 * provider, and pasted their own keys in. The CMS ships zero dependencies; an
 * owner may add one.
 *
 * **No provider's script is ever emitted without a configured key.** A half-set
 * provider falls back to the built-in challenge rather than rendering a widget
 * that cannot verify — which is a form nobody can submit, and the worst of the
 * three outcomes.
 *
 * ── HOW THE BUILT-IN ONE WORKS ──────────────────────────────────────────────
 * A small arithmetic question whose ANSWER IS NEVER IN THE PAGE. The form
 * carries the question and a sealed token — a nonce, an expiry, and a keyed
 * hash of the answer under the forms' own secret (`Guard::mac()`) — so a bot
 * that reads the HTML learns nothing it can post, and a visitor needs no
 * session for it (a public page sets no cookie).
 * Each question is answered once (`captcha_spent`, migration 071).
 *
 * That is weaker than a behavioural service and it is not pretending otherwise.
 * It stops the volume traffic — scripted posts that never load the page — which
 * is what the honeypot already in `renderForm()` is for too. Somebody being
 * targeted deliberately should turn on one of the services.
 *
 * ── WHY NOT SIMPLY THE HONEYPOT ─────────────────────────────────────────────
 * There already is one, and it stays. It is invisible, so it costs a real
 * visitor nothing — but it is also invisible to the OWNER, who cannot point at
 * it and cannot tell a client the form is protected. A visible challenge is a
 * different job: it is the one you can show somebody.
 */
final class Captcha
{
    /** Options keys, so the settings screen and this class cannot disagree. */
    public const PROVIDER_KEY = 'captcha_provider';
    public const SITE_KEY     = 'captcha_site_key';
    public const SECRET_KEY   = 'captcha_secret_key';

    /** How long a question may be answered: a long form, filled slowly. */
    public const TTL = 7200;

    /** @return array<string, string> value => label, for the settings select. */
    public static function providers(): array
    {
        return [
            'builtin'   => 'Built in (a simple question)',
            'hcaptcha'  => 'hCaptcha',
            'recaptcha' => 'reCAPTCHA',
        ];
    }

    /**
     * The provider actually in force.
     *
     * A service with no keys is NOT in force, however it is configured: the
     * built-in one answers instead. A widget that cannot verify is a form
     * nobody can submit, and that failure is silent to everybody except the
     * visitor, who simply leaves.
     */
    public static function provider(): string
    {
        $chosen = (string) (Option::get(self::PROVIDER_KEY, 'builtin') ?? 'builtin');
        if (!isset(self::providers()[$chosen]) || $chosen === 'builtin') {
            return 'builtin';
        }

        $site   = trim((string) (Option::get(self::SITE_KEY, '') ?? ''));
        $secret = trim((string) (Option::get(self::SECRET_KEY, '') ?? ''));

        return ($site !== '' && $secret !== '') ? $chosen : 'builtin';
    }

    /** Is a third-party service in force? The template asks before linking JS. */
    public static function isRemote(): bool
    {
        return self::provider() !== 'builtin';
    }

    /** The vendor's script URL, or '' when the built-in challenge is in force. */
    public static function scriptUrl(): string
    {
        return match (self::provider()) {
            'hcaptcha'  => 'https://js.hcaptcha.com/1/api.js',
            'recaptcha' => 'https://www.google.com/recaptcha/api.js',
            default     => '',
        };
    }

    /**
     * The challenge's markup.
     *
     * For a service that is the vendor's own div; for the built-in one it is a
     * question, a box, and the question's sealed token.
     */
    public static function render(string $inputId): string
    {
        $provider = self::provider();

        if ($provider !== 'builtin') {
            $site  = htmlspecialchars(trim((string) (Option::get(self::SITE_KEY, '') ?? '')));
            $class = $provider === 'hcaptcha' ? 'h-captcha' : 'g-recaptcha';

            return '<div class="' . $class . '" data-sitekey="' . $site . '"></div>';
        }

        [$token, $question] = self::mint();

        return '<div class="cms-form__captcha">'
             . '<label class="cms-form__captcha-q" for="' . $inputId . '">'
             . htmlspecialchars($question) . '</label>'
             . '<input type="text" class="cms-form__input cms-form__captcha-a"'
             . ' id="' . $inputId . '" name="_captcha" inputmode="numeric"'
             . ' autocomplete="off" required>'
             . '<input type="hidden" name="_captcha_nonce" value="' . htmlspecialchars($token) . '">'
             . '</div>';
    }

    /**
     * A new question, and its sealed token.
     *
     * THE ANSWER NEVER REACHES THE PAGE — only inside a keyed hash. A bot that
     * parses the HTML has the sum to do, which is the entire point — otherwise
     * it would simply read the answer out of a hidden input, which is how most
     * hand-rolled captchas are defeated.
     *
     * @return array{0: string, 1: string} token, question
     */
    /**
     * The built-in question, for a page that draws its own field — the
     * sign-in page (`Security\Challenge`), which never loads a provider's
     * script. Its answer is checked by `passesBuiltin()`.
     *
     * @return array{0: string, 1: string} [token, question]
     */
    public static function challenge(): array
    {
        return self::mint();
    }

    private static function mint(): array
    {
        // SMALL NUMBERS, WRITTEN AS WORDS ON ONE SIDE. A visitor should answer
        // without thinking; a naive scraper matching /\d+ \+ \d+/ should not.
        $words = ['zero', 'one', 'two', 'three', 'four', 'five',
                  'six', 'seven', 'eight', 'nine', 'ten'];
        $a = random_int(1, 9);
        $b = random_int(1, 9);

        $question = 'What is ' . $words[$a] . ' plus ' . $b . '?';

        return [self::seal($a + $b, bin2hex(random_bytes(16)), time() + self::TTL), $question];
    }

    /** `{nonce}.{expires}.{mac}`: the answer is in the hash, under the forms' key. */
    public static function seal(int $answer, string $nonce, int $expires): string
    {
        return $nonce . '.' . $expires . '.' . Guard::mac('captcha|' . $nonce . '|' . $expires . '|' . $answer);
    }

    /**
     * What a token was minted with — or null for one that is not ours, or has
     * expired. Every sum the question can have (2 to 18) is tried, so a forged
     * token matches none and is refused before the database is asked. Pure:
     * the caller hands it the time.
     *
     * @return array{nonce: string, expires: int, answer: int}|null
     */
    public static function open(string $token, int $now): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || preg_match('/^[0-9a-f]{32}$/', $parts[0]) !== 1
            || preg_match('/^[0-9]{1,12}$/', $parts[1]) !== 1 || (int) $parts[1] < $now) {
            return null;
        }
        for ($sum = 2; $sum <= 18; $sum++) {
            if (hash_equals(self::seal($sum, $parts[0], (int) $parts[1]), $token)) {
                return ['nonce' => $parts[0], 'expires' => (int) $parts[1], 'answer' => $sum];
            }
        }

        return null;
    }

    /**
     * Did this request answer the challenge?
     *
     * ── A CORRECT ANSWER IS SPENT ───────────────────────────────────────────
     * The nonce is removed whether the answer was right or wrong, so one
     * solved question cannot be replayed to post a thousand times — which is
     * exactly what an attacker who solves one by hand would do.
     *
     * ── A SERVICE IS VERIFIED SERVER-SIDE, ALWAYS ───────────────────────────
     * The token in the request means nothing until the vendor confirms it; a
     * client-side "the widget said yes" is a header a bot writes itself.
     */
    /** The built-in question's answer, as posted — right or not, it is spent. */
    public static function passesBuiltin(): bool
    {
        $minted = self::open((string) ($_POST['_captcha_nonce'] ?? ''), time());
        $given  = trim((string) ($_POST['_captcha'] ?? ''));
        if ($minted === null || !self::spend($minted['nonce'], $minted['expires'])) {
            return false;
        }

        return $given !== '' && ctype_digit($given) && (int) $given === $minted['answer'];
    }

    /**
     * Note a question as answered; false when it already was. One row per
     * question answered, kept until it would have expired anyway.
     */
    private static function spend(string $nonce, int $expires): bool
    {
        $db = Database::getInstance();
        $db->exec('DELETE FROM captcha_spent WHERE expires_at < UTC_TIMESTAMP() LIMIT 100');
        $stmt = $db->prepare('INSERT IGNORE INTO captcha_spent (nonce_hash, expires_at) VALUES (:hash, :expires)');
        $stmt->execute([':hash' => hash('sha256', $nonce), ':expires' => gmdate('Y-m-d H:i:s', $expires)]);

        return $stmt->rowCount() === 1;
    }

    public static function passes(): bool
    {
        $provider = self::provider();

        if ($provider === 'builtin') {
            return self::passesBuiltin();
        }

        $token = (string) ($_POST[
            $provider === 'hcaptcha' ? 'h-captcha-response' : 'g-recaptcha-response'
        ] ?? '');
        if ($token === '') {
            return false;
        }

        return self::verifyRemote($provider, $token);
    }

    /**
     * Ask the vendor whether the token is good.
     *
     * ── IT FAILS CLOSED, AND THAT IS A CHOICE WORTH STATING ─────────────────
     * If the vendor is unreachable, this returns false and the submission is
     * rejected. The alternative — letting posts through when the check cannot
     * run — turns an outage at Google into an open door on every form on the
     * site, and nobody would notice until the spam arrived. An owner who wants
     * the form to keep working through an outage should use the built-in
     * challenge, which has no outage.
     */
    private static function verifyRemote(string $provider, string $token): bool
    {
        $endpoint = $provider === 'hcaptcha'
            ? 'https://api.hcaptcha.com/siteverify'
            : 'https://www.google.com/recaptcha/api/siteverify';

        $body = http_build_query([
            'secret'   => trim((string) (Option::get(self::SECRET_KEY, '') ?? '')),
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                // A form submission waits on this. Ten seconds is already a
                // long time to stare at a button that has stopped responding.
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($endpoint, false, $ctx);
        if ($raw === false) {
            return false;
        }

        $json = json_decode($raw, true);

        return is_array($json) && !empty($json['success']);
    }
}
