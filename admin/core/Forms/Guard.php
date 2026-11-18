<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\Helpers\Theme;
use Cms\Models\FormSubmission;
use Cms\Models\Option;
use Cms\Security\ClientIp;

/**
 * A form's protections against automated submissions:
 *
 *   1. same-origin: a post whose Origin (or Referer) names another site;
 *   2. two decoys under opaque names derived from `app_secret`: one must come
 *      back blank (a fill-everything bot fills it), one must come back holding
 *      the value it was drawn with (a clear-or-rewrite bot changes it). Never a
 *      real word like "website": a browser or password manager fills those,
 *      and the visitor's message would vanish;
 *   3. a signed time-trap, `t` = <unix time>.<nonce>.<hmac>: forged or missing
 *      fails, under 3 seconds is a script (a form's `min_seconds` says
 *      otherwise: `minSeconds()`), over 2 hours is a stale page, and a token
 *      that already carried a submission cannot carry another;
 *   4. a rate limit per address: 5 received submissions per 10 minutes, across
 *      every form (options `forms_rate_max`, `forms_rate_minutes`);
 *   5. content: a known bot's signature ("google" for a company, with a
 *      made-up name), more than a form's `max_links` links (4 when unset), a
 *      BBCode link, a link typed as a name, an address on a domain that is
 *      reserved for examples and can never receive mail.
 *
 * ── CERTAIN OR SUSPICIOUS ───────────────────────────────────────────────────
 * Every turn-away says which. CERTAIN is what no real lead has looked like: a
 * decoy filled, a token forged or missing, a post from another site, a page
 * replayed again and again, a flood, forum link code, a link for a name, a post
 * sent within 3 seconds of its page loading, an address that can never receive
 * mail, a known bot's signature. SUSPICIOUS is what a person does send, now and
 * then: a tab left open overnight, a double click, a busy office on one
 * address, a message with many links. The form handler discards what is
 * certain (counted, never stored — Settings can keep it instead) and keeps
 * what is suspicious as spam, for a person to look at.
 *
 * A person CAN trip two of the certain rules — a very quick hand, someone
 * trying their own form with an example address — and is told so ("try once
 * more", "use an address you can be reached at"), so nothing real is lost: the
 * next press goes through. Only what no person sends is answered "thank you".
 *
 * The line is drawn on evidence from production traffic. Most turned-away
 * posts are a vulnerability scanner's flood: the blank decoy filled, a claim to
 * come from www.google.com, neither a token nor a decoy. With that set aside,
 * three kinds of bot still reach a Spam view or an inbox:
 *
 *   - sent under 3 seconds: every one a bot, 0–2 seconds after its page
 *     loaded (SEO text, casino and pharma links, "lead generation" pitches, an
 *     RFQ blast from several addresses in the same second);
 *   - "google" for the company with a made-up name: the first name the same
 *     as the last, or a name ending in two capitals after a lowercase letter
 *     or a space (FrankIncixGN / FrankIncixGN). Sent slowly enough, these pass
 *     every other rule and reach the inbox;
 *   - testing@example.com: a scanner's signature.
 *
 * None of the three matches a real lead — not an enquiry, not an owner's own
 * test leads, not a hand-written cold pitch, which is the "is this spam?" kind
 * and stays kept — so all three are certain.
 *
 * Nothing here decides what happens to a submission; it says which rule, why,
 * how sure, and what the sender should be told. A caught bot is told "thank
 * you" (a decoy, a reused token, a bot's signature), so it learns nothing;
 * anyone who might be a person is told what to do. A site words those itself in a
 * `form-messages.php` (code => message) in its active theme or in
 * `admin/site/templates/` (`Helpers\Theme::file()`).
 *
 * A signed-in member of staff is never screened: the handler skips this class
 * for them.
 *
 * ── A SITE'S OWN RULES (`siteRule()`) ───────────────────────────────────────
 * A site that knows its own junk names it in a `form-spam.php`, in its active
 * theme or in `admin/site/templates/`, returning a function given the form,
 * its answers and the post's ip, user agent and page:
 *
 *     return static function (array $form, array $answers, array $meta): ?string {
 *         return preg_match('/\bbacklinks?\b/i', $answers['message'] ?? '') === 1 ? 'pitch' : null;
 *     };
 *
 * A reason makes the post spam ("site rule: pitch"): kept, never discarded,
 * so a rule that is wrong costs a look in the Spam view and never a lead.
 *
 * ── ANSWERED LIKE A SENT MESSAGE (`spam_answer`) ────────────────────────────
 * A form whose `spam_answer` is 'thanks' answers every post the rules turn
 * away for certain, and every one turned away for its content or its speed,
 * exactly as it answers one that was sent: its message, its after-sending
 * page, no conversion and no email. Those its content or speed turned away
 * are kept as spam with their reason, even where certain spam is discarded
 * (`QUIET`). A sender who might be a person, with a page left open too long
 * or a burst from one address, is still told (`answersSent()`).
 */
final class Guard
{
    public const MIN_SECONDS  = 3;
    public const MAX_SECONDS  = 7200;
    public const RATE_MAX     = 5;
    public const RATE_MINUTES = 10;
    public const MAX_LINKS    = 4;

    /** The token's field name. */
    public const TOKEN = 't';

    /** @var array<string, string> code => what the sender is told */
    private const MESSAGES = [
        'cross_site'     => 'This form can only be sent from this website.',
        'stale_page'     => 'This page is out of date. Please reload it and send your message again.',
        'token_invalid'  => 'Your session token was invalid. Please reload the page and try again.',
        'too_fast'       => 'That came through a little too fast. Please try once more.',
        'expired'        => 'This form expired. Please reload the page and send it again.',
        'rate_limited'   => 'You have sent several messages just now. Please wait a few minutes and try again.',
        'flood'          => 'You have sent several messages just now. Please wait a few minutes and try again.',
        'too_many_links' => 'That message tripped our spam filter (too many links).',
        'bbcode'         => 'That message tripped our spam filter.',
        'name_link'      => 'Please enter a real name.',
        'test_address'   => 'Please use an email address you can be reached at.',
        'site_rule'      => 'That message tripped our spam filter.',
    ];

    /**
     * The rules a form answering like a sent message (`spam_answer` 'thanks')
     * keeps as spam whatever else it would do: its speed and its content, the
     * CMS's rules and the site's own.
     */
    public const QUIET = ['too_fast', 'too_many_links', 'bbcode', 'name_link', 'test_address', 'bot_signature', 'site_rule'];

    /** The fewest and the most seconds a form may ask for (`minSeconds()`). */
    public const MIN_SECONDS_RANGE = [1, 120];

    /**
     * What each CERTAIN reason is called where its count is shown — the Spam
     * view's line of what was discarded. The suspicious ones are stored with a
     * sentence of their own and never counted.
     *
     * @var array<string, string>
     */
    public const DISCARDED = [
        'decoy'         => 'filled a hidden field',
        'token_invalid' => 'never loaded the form',
        'too_fast'      => 'sent too fast',
        'cross_site'    => 'from another site',
        'replayed'      => 'replayed a page',
        'flood'         => 'flood from one address',
        'bbcode'        => 'forum link code',
        'name_link'     => 'a link as a name',
        'test_address'  => 'an address that gets no mail',
        'bot_signature' => "a known spam bot's signature",
    ];

    /** Names that are a person's, whole or part — read by the content check and by `Preview`. */
    public const NAME_FIELDS = ['name', 'first_name', 'last_name', 'full_name', 'your_name', 'your-name'];

    /**
     * A field that holds a company's name, by its name — or by its type,
     * should one be added (none is today). Read by the bot signature.
     */
    private const COMPANY_FIELDS = ['company', 'organization', 'organisation'];

    private static ?string $secret = null;

    /** Opaque, stable decoy names. Keys are the roles, not the names. */
    public static function decoyNames(): array
    {
        return [
            'empty' => 'f_' . substr(hash_hmac('sha256', 'form-decoy-empty', self::secret()), 0, 14),
            'keep'  => 'f_' . substr(hash_hmac('sha256', 'form-decoy-keep', self::secret()), 0, 14),
        ];
    }

    /** The value the prefilled decoy must still hold. */
    public static function decoyValue(): string
    {
        return substr(hash_hmac('sha256', 'form-decoy-value', self::secret()), 0, 10);
    }

    /**
     * A keyed hash under the forms' own secret — for another stateless token a
     * form carries (the built-in question, `Forms\Captcha`), so the forms have
     * one key between them, and one fallback when config.php has none.
     */
    public static function mac(string $data): string
    {
        return hash_hmac('sha256', $data, self::secret());
    }

    /**
     * A time-trap token for a form drawn at $at (now when null). The random
     * part makes it one page load's: two visitors who load a page in the same
     * second must not share a token, or the first one's lead would make the
     * second one's look like a replay.
     */
    public static function token(?int $at = null): string
    {
        $body = ($at ?? time()) . '.' . bin2hex(random_bytes(8));
        return $body . '.' . hash_hmac('sha256', $body, self::secret());
    }

    /**
     * The hidden inputs a form carries: the token and the two decoys, the
     * decoys kept off screen. A site that draws its own form markup calls this,
     * or draws them its own way from decoyNames()/decoyValue()/token().
     */
    public static function fields(): string
    {
        $n = self::decoyNames();
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // OUT OF SIGHT BY A STYLESHEET LINKED BESIDE THEM (`form-guard.css`).
        // A form drawn into a theme that does not load ours must not show a
        // stray box a visitor fills in — every real submission would then be a
        // "bot" — and an inline style would show them on a page whose
        // Content-Security-Policy refuses one. Linked before them, so nothing
        // after it is drawn until the browser has the rule.
        return '<input type="hidden" name="' . self::TOKEN . '" value="' . $h(self::token()) . '">'
             . '<link rel="stylesheet" href="' . $h(\Cms\Helpers\Asset::sitePath('assets/css/form-guard.css')) . '">'
             . '<div class="cms-decoy" aria-hidden="true">'
             . '<input type="text" name="' . $h($n['empty']) . '" value="" tabindex="-1" autocomplete="off">'
             . '<input type="text" name="' . $h($n['keep']) . '" value="' . $h(self::decoyValue()) . '" tabindex="-1" autocomplete="off">'
             . '</div>';
    }

    /**
     * The checks that come before the answers are read (1–4), or null when the
     * submission may go on. A turn-away is
     *   code     which rule
     *   reason   stored with the submission, for whoever reads the Spam view
     *   status   the HTTP status
     *   fake     true: tell the sender "thank you" (a bot; nothing to learn)
     *   certain  true: no real lead has looked like it (see the class comment)
     *
     * @param  array<string, mixed> $post
     * @param  array<string, mixed> $server
     * @param  array<string, mixed> $settings the form's, for its `min_seconds`
     * @return array{code: string, reason: string, status: int, fake: bool, certain: bool}|null
     */
    public static function screen(array $post, array $server, array $settings = []): ?array
    {
        // 1. Same origin. No Origin and no Referer is let through: privacy
        // tools strip both, and the token below is the real test.
        $host = self::host((string) ($server['HTTP_HOST'] ?? ''));
        $from = (string) ($server['HTTP_ORIGIN'] ?? ($server['HTTP_REFERER'] ?? ''));
        if ($from !== '' && $host !== '') {
            $fromHost = self::host((string) parse_url($from, PHP_URL_HOST));
            if ($fromHost !== '' && !self::sameSite($fromHost, $host)) {
                return self::turn('cross_site', 'cross-site post from ' . $fromHost, 403, false, true);
            }
        }

        // 2. The decoys, with the token's verdict to hand: a post missing the
        // prefilled decoy AND carrying no valid token was not sent from any
        // page this site drew, while one that has a good token is a page
        // drawn before the decoys existed.
        $n     = self::decoyNames();
        $token = (string) ($post[self::TOKEN] ?? '');
        $valid = self::validToken($token);
        if (trim((string) ($post[$n['empty']] ?? '')) !== '') {
            return self::turn('decoy', 'decoy: the blank field was filled', 200, true, true);
        }
        if (!array_key_exists($n['keep'], $post)) {
            return $valid
                ? self::turn('stale_page', 'stale page: the prefilled decoy was missing', 400, false, false)
                : self::turn('token_invalid', 'token: forged or missing', 400, false, true);
        }
        if (!hash_equals(self::decoyValue(), (string) $post[$n['keep']])) {
            return self::turn('decoy', 'decoy: the prefilled field was altered', 200, true, true);
        }

        // 3. The time-trap.
        if (!$valid) {
            return self::turn('token_invalid', 'token: forged or missing', 400, false, true);
        }
        $age = time() - (int) explode('.', $token)[0];
        // UNDER 3 SECONDS IS A SCRIPT: every one seen in production was a
        // bot, sent 0–2 seconds after its page loaded. Certain, and still told
        // to try once more, so a person who manages it loses nothing. A form
        // whose own traffic says more asks for more (`min_seconds`).
        $least = self::minSeconds($settings);
        if ($age < $least) {
            return self::turn('too_fast', 'token: sent too fast (' . max(0, $age) . ' s after the page loaded, the least is ' . $least . ' s)', 429, false, true);
        }
        if ($age > self::MAX_SECONDS) {
            return self::turn('expired', 'token: expired (' . intdiv($age, 60) . ' minutes old)', 410, false, false);
        }
        // A REPLAY ONCE IS A DOUBLE CLICK; AGAIN AND AGAIN IS A SCRIPT. A
        // scanner replays each of its accepted posts five to ten times.
        $hash = self::tokenHash($token);
        $used = FormSubmission::byToken($hash);
        if ($used !== null) {
            return FormSubmission::spamWithToken($hash) === 0
                ? self::turn('already_sent', 'token: already used by submission #' . $used, 200, true, false)
                : self::turn('replayed', 'token: used again by submission #' . $used, 200, true, true);
        }

        // 4. The rate limit, across every form. The first post over it is kept
        // for a look — a busy office shares one address — and the rest of the
        // window's are a flood, counted rather than stored one row each.
        $ip = ClientIp::get();
        if ($ip !== '') {
            $max     = max(1, (int) (Option::get('forms_rate_max', null) ?? self::RATE_MAX));
            $minutes = max(1, (int) (Option::get('forms_rate_minutes', null) ?? self::RATE_MINUTES));
            if (FormSubmission::receivedFromSince($ip, $minutes) >= $max) {
                return FormSubmission::rateKeptSince($ip, $minutes) === 0
                    ? self::turn('rate_limited', "rate limit: $max in $minutes minutes from this address", 429, false, false)
                    : self::turn('flood', "rate limit: $max in $minutes minutes, again", 429, false, true);
            }
        }

        return null;
    }

    /**
     * The content checks (5), on the answers the form stored.
     *
     * @param  array<string, mixed>              $data      name => answer
     * @param  list<array<string, mixed>>        $fields    the form's (expanded) fields
     * @param  array<string, mixed>              $settings  the form's settings
     * @param  array<string, mixed>              $read      name => the answer as read, before it was joined
     *                                                      for storing (a Name field's first and last apart)
     * @return array{code: string, reason: string, status: int, fake: bool, certain: bool}|null
     */
    public static function content(array $data, array $fields, array $settings, array $read = []): ?array
    {
        // A KNOWN BOT FIRST, before the link count: it is certain, and a post
        // that is both is the bot's. Told "thank you", as a filled decoy is.
        $bot = self::botSignature($data, $fields, $read);
        if ($bot !== null) {
            return self::turn('bot_signature', 'bot signature: ' . $bot, 200, true, true);
        }
        $blob  = strtolower(implode(' ', array_map(static fn ($v): string => is_array($v) ? implode(' ', $v) : (string) $v, $data)));
        $most  = isset($settings['max_links']) && $settings['max_links'] !== '' ? max(0, (int) $settings['max_links']) : self::MAX_LINKS;
        $links = preg_match_all('~https?://~i', $blob);
        if ($links > $most) {
            return self::turn('too_many_links', "links: $links (the most is $most)", 422, false, false);
        }
        if (preg_match('~\[(url|link)[=\]]~i', $blob) === 1) {
            return self::turn('bbcode', 'BBCode link', 422, false, true);
        }
        foreach ($fields as $field) {
            $name   = (string) ($field['name'] ?? '');
            $type   = (string) ($field['type'] ?? '');
            $value  = $data[$name] ?? '';
            $value  = is_array($value) ? implode(' ', $value) : (string) $value;
            $isName = $type === 'name' || in_array($name, self::NAME_FIELDS, true);
            if ($isName && stripos($value, 'http') !== false) {
                return self::turn('name_link', 'name: contains a link (' . $name . ')', 422, false, true);
            }
            // AN ADDRESS NO ONE CAN BE REACHED AT. RFC 2606 and 6761 reserve
            // these for examples and tests; mail to them goes nowhere, ever.
            // A scanner signs its posts `testing@example.com`. Certain, and
            // still told why: a person trying their own form with one is told
            // to use a real address, and loses nothing, since no answer could
            // have reached them there.
            if (($type === 'email' || $name === 'email') && self::reservedDomain($value)) {
                return self::turn('test_address', 'email: ' . mb_substr(trim($value), 0, 80) . ' cannot receive mail', 422, false, true);
            }
        }
        return null;
    }

    /**
     * The seconds a form's page must have been open before it sends: its
     * `min_seconds` (Settings → Spam on the form), else 3, kept within
     * `MIN_SECONDS_RANGE` so a typing slip cannot let every bot through or
     * turn every person away.
     *
     * @param array<string, mixed> $settings the form's
     */
    public static function minSeconds(array $settings): int
    {
        $set = trim((string) ($settings['min_seconds'] ?? ''));
        if (preg_match('/^\d{1,4}$/', $set) !== 1) {
            return self::MIN_SECONDS;
        }
        return max(self::MIN_SECONDS_RANGE[0], min(self::MIN_SECONDS_RANGE[1], (int) $set));
    }

    /**
     * The site's own rule (`form-spam.php`, see the class comment), or null
     * when it has none or the post passes it. A rule that is not a function,
     * or one that fails, is logged and passes the post: a lead is never lost
     * to a broken rule.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $answers name => answer, as stored
     * @param array<string, mixed> $meta    ip, user_agent, source_page
     * @return array{code: string, reason: string, status: int, fake: bool, certain: bool}|null
     */
    public static function siteRule(array $form, array $answers, array $meta): ?array
    {
        static $rule = false;
        if ($rule === false) {
            $rule = null;
            $file = Theme::file('form-spam.php');
            if ($file !== null) {
                try {
                    $made = (static fn (string $__f): mixed => require $__f)($file);
                    if (is_callable($made)) {
                        $rule = $made;
                    } else {
                        error_log('CMS forms: ' . $file . ' returns no function, so no site rule is applied');
                    }
                } catch (\Throwable $e) {
                    error_log('CMS forms: ' . $file . ' could not be read: ' . $e->getMessage());
                }
            }
        }
        if ($rule === null) {
            return null;
        }
        try {
            $why = $rule($form, $answers, $meta);
        } catch (\Throwable $e) {
            error_log('CMS forms: the site rule failed (' . $e->getMessage() . '), so the post passed it');
            return null;
        }
        if (!is_string($why) || trim($why) === '') {
            return null;
        }
        return self::turn('site_rule', 'site rule: ' . mb_substr(trim((string) preg_replace('/\s+/', ' ', $why)), 0, 200), 422, false, false);
    }

    /**
     * Whether the sender is answered as though the post was sent: a caught
     * bot always is (`fake`), and on a form answering like a sent message
     * (`spam_answer` 'thanks'), so is everything turned away for certain or
     * for its speed or content (`QUIET`).
     *
     * @param array{code: string, fake: bool, certain: bool} $turn
     * @param array<string, mixed> $settings the form's
     */
    public static function answersSent(array $turn, array $settings): bool
    {
        return !empty($turn['fake'])
            || (($settings['spam_answer'] ?? '') === 'thanks' && (!empty($turn['certain']) || in_array($turn['code'], self::QUIET, true)));
    }

    /**
     * Whether a turned-away post is kept as spam (or counted and discarded):
     * anything not certain is; certain spam is when the site keeps all of it
     * (`$keepAll`), and on a form answering like a sent message, whatever its
     * speed or content turned away (`QUIET`).
     *
     * @param array{code: string, fake: bool, certain: bool} $turn
     * @param array<string, mixed> $settings the form's
     */
    public static function keeps(array $turn, array $settings, bool $keepAll): bool
    {
        return empty($turn['certain']) || $keepAll
            || (($settings['spam_answer'] ?? '') === 'thanks' && in_array($turn['code'], self::QUIET, true));
    }

    /**
     * What gives a known spam bot away, or null: "google" for the company (the
     * whole answer, in any case) with a made-up name — the first name the same
     * as the last, or a name ending in two capitals after a lowercase letter or
     * a space (FrankIncixGN / FrankIncixGN, WalterBoushGM / MasonBoushGM,
     * "… DW AB"). The company is a field named one of
     * `COMPANY_FIELDS`; the names are `NAME_FIELDS` and a Name field's first
     * and last. A form that asks for neither is never matched, and an answer
     * the form's logic hid is not read.
     *
     * @param array<string, mixed>       $data
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed>       $read
     */
    private static function botSignature(array $data, array $fields, array $read): ?string
    {
        $company = null;
        $first   = '';
        $last    = '';
        $names   = [];
        foreach ($fields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if ($name === '' || !array_key_exists($name, $data)) {
                continue;
            }
            $text = trim(is_array($data[$name]) ? implode(' ', $data[$name]) : (string) $data[$name]);
            if (in_array($name, self::COMPANY_FIELDS, true) || in_array($type, self::COMPANY_FIELDS, true)) {
                if (strtolower($text) === 'google') {
                    $company = $text;
                }
            } elseif ($type === 'name') {
                $parts   = is_array($read[$name] ?? null) ? $read[$name] : [];
                $first   = trim((string) ($parts['first'] ?? ''));
                $last    = trim((string) ($parts['last'] ?? ''));
                $names[] = $first;
                $names[] = $last;
                $names[] = $text;
            } elseif ($name === 'first_name') {
                $first   = $text;
                $names[] = $text;
            } elseif ($name === 'last_name') {
                $last    = $text;
                $names[] = $text;
            } elseif (in_array($name, self::NAME_FIELDS, true)) {
                $names[] = $text;
            }
        }
        if ($company === null) {
            return null;
        }
        if ($first !== '' && $first === $last) {
            return 'company "' . $company . '", the first name the same as the last (' . mb_substr($first, 0, 60) . ')';
        }
        foreach ($names as $one) {
            if (preg_match('/(?<=[a-z\s])[A-Z]{2}$/', $one) === 1) {
                return 'company "' . $company . '", a name ending in two capitals (' . mb_substr($one, 0, 60) . ')';
            }
        }
        return null;
    }

    /**
     * A NEW TOKEN FOR A PAGE LEFT OPEN, in place of the one it has: given a
     * token this site signed that is at least the form's fewest seconds old
     * (`minSeconds()`), one good for as long again, dated those seconds back
     * so it can be sent at once. The time-trap is not shortened: the page has
     * been open that long already. Null for anything else.
     *
     * @param array<string, mixed> $settings the form's
     */
    public static function renew(string $token, array $settings): ?string
    {
        if (!self::validToken($token)) {
            return null;
        }
        $least = self::minSeconds($settings);
        if (time() - (int) explode('.', $token)[0] < $least) {
            return null;
        }
        return self::token(time() - $least);
    }

    /** The stored hash of a token (the token itself is never kept). */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The hash of the post's token when it is one this site signed, else null. */
    public static function postedTokenHash(array $post): ?string
    {
        $token = (string) ($post[self::TOKEN] ?? '');
        return self::validToken($token) ? self::tokenHash($token) : null;
    }

    /**
     * What the sender is told for a rule: the site's wording — the active
     * theme's `form-messages.php`, else `admin/site/templates/`'s
     * (`Helpers\Theme::file()`) — or ours.
     */
    public static function message(string $code): string
    {
        static $site = null;
        if ($site === null) {
            $file = Theme::file('form-messages.php');
            $site = $file !== null ? (array) (static fn (string $__f): mixed => require $__f)($file) : [];
        }
        return (string) ($site[$code] ?? self::MESSAGES[$code] ?? 'Your message could not be sent.');
    }

    private static function validToken(string $token): bool
    {
        $parts = explode('.', $token);
        return count($parts) === 3
            && ctype_digit($parts[0])
            && preg_match('/^[0-9a-f]{16}$/', $parts[1]) === 1
            && hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], self::secret()), $parts[2]);
    }

    /** A host name, lowercased, without a port or a trailing dot. */
    private static function host(string $host): string
    {
        return rtrim(strtolower((string) preg_replace('/:\d+$/', '', trim($host))), '.');
    }

    /**
     * The same site: the same host, the same host with or without `www.`, or
     * the host `base_url` names. The `[form]` renderer posts to `base_url`, so
     * a visitor reading the page on the `www.` address sends an Origin that is
     * not the request's host — and that is a real lead, not another site.
     */
    private static function sameSite(string $from, string $host): bool
    {
        $bare = static fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        if ($bare($from) === $bare($host)) {
            return true;
        }
        $base = defined('BASE_URL') ? self::host((string) parse_url((string) BASE_URL, PHP_URL_HOST)) : '';
        return $base !== '' && $bare($from) === $bare($base);
    }

    /** An address on a domain kept for examples and tests (RFC 2606, RFC 6761). */
    private static function reservedDomain(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }
        $domain = rtrim(strtolower(trim(substr($address, $at + 1))), '.');
        if (in_array($domain, ['example.com', 'example.net', 'example.org', 'localhost'], true)) {
            return true;
        }
        foreach (['.example', '.test', '.invalid', '.localhost', '.example.com', '.example.net', '.example.org'] as $tail) {
            if (str_ends_with($domain, $tail)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{code: string, reason: string, status: int, fake: bool, certain: bool} */
    private static function turn(string $code, string $reason, int $status, bool $fake, bool $certain): array
    {
        return ['code' => $code, 'reason' => $reason, 'status' => $status, 'fake' => $fake, 'certain' => $certain];
    }

    /**
     * The key every token and decoy name is signed with: `app_secret`, read the
     * way `Security\Sealed` reads it. An install still on the example's
     * placeholder, or with none, signs with a key derived from its own database
     * password instead — a form that cannot be sent is the worst of the
     * outcomes, and that password is not something a visitor can know.
     */
    /**
     * A FIXED VALUE, NOT A NAME — NEVER RENAMED. The fallback key is a hash of
     * this text with the database's name and password, so changing it changes
     * the key, and with it every form's decoy field names and every page's
     * token already sent. It keeps the working title it was first written with.
     */
    private const FALLBACK_SALT = 'imprint-forms|';

    private static function secret(): string
    {
        // A PAGE DRAWN FOR A STATIC COPY (`Export\StaticCopy`) carries decoys
        // and a token under the copy's own secret, which the copy's form
        // handler holds — never the site's `app_secret`, which stays here.
        if (defined('CMS_EXPORT') && is_array(CMS_EXPORT) && (string) (CMS_EXPORT['secret'] ?? '') !== '') {
            return (string) CMS_EXPORT['secret'];
        }
        if (self::$secret === null) {
            $config = defined('ROOT_PATH') ? \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php') : null;
            $config = is_array($config) ? $config : [];
            $secret = trim((string) ($config['app_secret'] ?? ''));
            if (strlen($secret) < 16 || $secret === 'change-me-to-a-random-secret-string') {
                $secret = hash('sha256', self::FALLBACK_SALT . (string) ($config['db_name'] ?? '') . '|' . (string) ($config['db_pass'] ?? '') . '|' . (defined('ROOT_PATH') ? ROOT_PATH : ''));
            }
            self::$secret = $secret;
        }
        return self::$secret;
    }
}
