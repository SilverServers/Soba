<?php

declare(strict_types=1);

namespace Cms\Security;

/**
 * THE ADMIN'S DOOR — the headers every admin response carries, and who may
 * reach it at all. One place, because two front doors use it: the front
 * controller (`www/index.php`) for every admin screen and the sign-in page,
 * and `Site\Tools` for a site's own tools, which are handed over before the
 * front controller gets that far (a tool brings its own `icon()`). Written
 * twice, the two lists drift, and the door a tool walks through is the one
 * that stops being checked — which is how site tools first shipped: framable,
 * and open to an address Security → Blocking had turned away.
 *
 * Nothing here loads a template or `Ui/globals.php` until it turns somebody
 * away (`AddressRules::turnAway()`), and then the request ends: a tool's own
 * `icon()` never meets the CMS's.
 */
final class Door
{
    /**
     * On every response. `nosniff`: nothing here ever wants a browser to guess
     * that a download is a script. Strict-Transport-Security only when the
     * owner has switched it on (Security → HTTPS) — it is
     * irreversible for its whole max-age, so it is theirs to decide.
     */
    public static function everywhere(): void
    {
        header('X-Content-Type-Options: nosniff');
        Hsts::send();
    }

    /**
     * On the admin and the sign-in page, and nowhere else:
     *
     *   frame-ancestors / X-Frame-Options   no other site may put the admin in a
     *                                       frame and trick a click through it
     *   Referrer-Policy: same-origin        an admin address — the sign-in page's
     *                                       own, above all — is never handed to
     *                                       another site in a Referer header
     *   Cache-Control: no-store             a signed-out Back button shows nothing
     *
     * A site's tool that wants its assets cached says so itself: its own
     * `Cache-Control` comes after these and replaces the last.
     */
    public static function adminHeaders(): void
    {
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
    }

    /**
     * WHO MAY REACH THE DOOR AT ALL (Security → Blocking → Addresses): before
     * the form token, the router or any sign-in is looked at — a turned-away
     * address learns nothing about what is behind it. Ends the request for an
     * address the rules refuse.
     */
    public static function checkAddress(): void
    {
        if (AddressRules::refuses(ClientIp::get())) {
            AddressRules::turnAway();
        }
    }
}
