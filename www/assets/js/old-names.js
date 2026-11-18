/**
 * THE CMS'S OLD CLASS NAMES BESIDE THE NEW, ON WHAT A FORM'S SCRIPTS DRAW
 * (`Cms\Compat\OldNames`, through 0.7.x).
 *
 * Loaded before the form's other scripts, and only while a site keeps the old
 * names (config.php without 'old_class_names' => false); its form then
 * carries `data-imprint-form`, and the server writes each `cms-form…`,
 * `cms-gallery…` and `cms-alert…` class's `imprint-…` twin in its markup.
 * What the scripts add or change afterwards — a form's steps and their
 * buttons, a tag, a summary's lines, the status message's kind — gets the same
 * here, so the scripts themselves name only the new: an element given a new
 * name gets its twin, and one that loses a new name loses its twin.
 */
(function (document) {
    'use strict';

    var NEW = /^cms-(form|gallery|alert)(?![a-z])/;

    function twin(name) { return 'imprint-' + name.slice(4); }

    /** Give `el` the twin of each new name it has; take away the twin of each one in `before` it no longer has. */
    function sync(el, before) {
        if (!el.classList) { return; }
        Array.prototype.slice.call(el.classList).forEach(function (name) {
            if (NEW.test(name) && !el.classList.contains(twin(name))) { el.classList.add(twin(name)); }
        });
        (before || '').split(/\s+/).forEach(function (name) {
            if (NEW.test(name) && !el.classList.contains(name) && el.classList.contains(twin(name))) {
                el.classList.remove(twin(name));
            }
        });
    }

    function syncAll(root) {
        sync(root, '');
        Array.prototype.forEach.call(root.querySelectorAll('[class]'), function (el) { sync(el, ''); });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-imprint-form]'), function (form) {
        var wrap = form.closest('.cms-form') || form;
        syncAll(wrap);
        new MutationObserver(function (records) {
            records.forEach(function (r) {
                if (r.type === 'attributes') {
                    sync(r.target, r.oldValue);
                }
                Array.prototype.forEach.call(r.addedNodes, function (node) {
                    if (node.nodeType === 1) { syncAll(node); }
                });
            });
        }).observe(wrap, {subtree: true, childList: true, attributes: true, attributeFilter: ['class'], attributeOldValue: true});
    });
})(document);
