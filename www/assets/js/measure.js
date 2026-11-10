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
