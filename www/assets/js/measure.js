/**
 * GOOGLE'S MEASUREMENT TAGS WITHOUT AN INLINE SCRIPT (`Site\Google`).
 *
 * What Google's own snippet does inline, done from this site's file: the
 * data layer, `gtag()` and `gtag('js')`, in the data layer before Google's
 * loader runs. The IDs are on this script's own tag; Google's loader for each
 * is printed after it. Its settings, the page view among them, are the
 * Google tag's and the container's own, in Google's screens.
 *
 * Then the site's own events, each sent once: a form's conversion, on the
 * page a real submission lands on (`data-conversion`) or from the form's own
 * script (`cmsMeasure.convert()`), and, when they are switched on, clicks on
 * a phone number or an email address. Each goes to the data layer as an event
 * when Tag Manager is on, and to GA4 as a gtag event when GA4 is.
 *
 * ASKED FIRST, when the page says so (`data-consent`): Google's consent is
 * denied before anything else, nothing of Google's is loaded, and the banner
 * is shown until the visitor answers. Accept grants it and loads Google's
 * loaders (`data-load-ga4`, `data-load-gtm`), with this tag's nonce; Decline
 * loads nothing. The answer is kept a year in `cms_consent`, and a Cookie
 * settings button (`data-cms-consent-open`) shows the banner again.
 */
(function () {
    'use strict';

    var me = document.currentScript;
    // ONCE A PAGE, however many times the file is reached.
    if (!me || window.cmsMeasure) { return; }

    var ga4 = me.getAttribute('data-ga4') || '';
    var gtm = me.getAttribute('data-gtm') || '';
    var dataLayer = window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
        window.gtag = function () { dataLayer.push(arguments); };
    }

    // ── ASKED FIRST ─────────────────────────────────────────────────────────
    var asks = me.hasAttribute('data-consent');
    var path = me.getAttribute('data-path') || '/';
    var KINDS = ['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'];
    function consent(value) {
        var state = {};
        KINDS.forEach(function (kind) { state[kind] = value; });
        return state;
    }
    function answer() {
        var m = document.cookie.match(/(?:^|;\s*)cms_consent=(granted|denied)/);
        return m ? m[1] : '';
    }
    var loaded = false;
    function load() {
        if (loaded) { return; }
        loaded = true;
        ['data-load-ga4', 'data-load-gtm'].forEach(function (name) {
            var src = me.getAttribute(name);
            if (!src) { return; }
            var tag = document.createElement('script');
            tag.async = true;
            tag.src = src;
            if (me.nonce) { tag.nonce = me.nonce; }
            document.head.appendChild(tag);
        });
    }
    if (asks) {
        window.gtag('consent', 'default', consent('denied'));
        if (answer() === 'granted') {
            window.gtag('consent', 'update', consent('granted'));
            load();
        }
    }
    window.gtag('js', new Date());

    function send(name, params) {
        if (gtm) {
            var push = { event: name };
            for (var key in params) {
                if (Object.prototype.hasOwnProperty.call(params, key)) { push[key] = params[key]; }
            }
            dataLayer.push(push);
        }
        if (ga4) {
            window.gtag('event', name, params);
        }
    }

    var sent = {};
    window.cmsMeasure = {
        /** A form's conversion: `{event, form, id}`, sent once for each `id`. */
        convert: function (conversion) {
            if (!conversion || !conversion.event || sent[conversion.id]) { return; }
            sent[conversion.id] = true;
            send(String(conversion.event), { form_id: String(conversion.form) });
        }
    };

    // THE CONVERSION THIS PAGE WAS SENT WITH, and its cookie cleared, whether
    // or not the server could clear it before the page's first bytes.
    var waiting = me.getAttribute('data-conversion');
    if (waiting) {
        window.cmsMeasure.convert({ event: waiting, form: me.getAttribute('data-form') || '', id: 'page' });
    }
    if (document.cookie.indexOf('cms_conversion=') !== -1) {
        document.cookie = 'cms_conversion=; Max-Age=0; Path=' + (me.getAttribute('data-path') || '/') + '; SameSite=Lax';
    }

    // THE BANNER: shown when there is no answer yet, or when asked again; the
    // answer kept for a year either way.
    if (asks) {
        var ready = function () {
            var banner = document.querySelector('[data-cms-consent]');
            if (!banner) { return; }
            if (!answer()) { banner.hidden = false; }
            banner.addEventListener('click', function (e) {
                var button = e.target.closest ? e.target.closest('[data-cms-consent-answer]') : null;
                if (!button) { return; }
                var value = button.getAttribute('data-cms-consent-answer') === 'granted' ? 'granted' : 'denied';
                document.cookie = 'cms_consent=' + value + '; Max-Age=31536000; Path=' + path + '; SameSite=Lax';
                window.gtag('consent', 'update', consent(value));
                if (value === 'granted') { load(); }
                banner.hidden = true;
            });
            document.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('[data-cms-consent-open]')) {
                    banner.hidden = false;
                }
            });
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', ready);
        } else {
            ready();
        }
    }

    // A CLICK ON A PHONE NUMBER OR AN EMAIL ADDRESS. Captured, so a link
    // whose own handler stops the click is still counted.
    if (me.hasAttribute('data-clicks')) {
        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
            if (!link) { return; }
            var href = String(link.getAttribute('href') || '').trim();
            var scheme = href.slice(0, href.indexOf(':') + 1).toLowerCase();
            if (scheme === 'tel:') {
                send('phone_call_click', { method: 'phone', link_url: href });
            } else if (scheme === 'mailto:') {
                send('email_click', { method: 'email', link_url: href });
            }
        }, true);
    }
}());
