/**
 * A HAND-ORDERED TYPE'S LIST, ORDERED BY DRAG (`#entry-list[data-arrange]`):
 * shown in its own order, every entry on one page, with no search, filter or
 * other sort (a child type's within one parent).
 *
 *   A ROW IS DRAGGED BY ITS GRIP (reorder.js), as a gallery's tiles are, or
 *   moved by Up one and Down one among its actions, the keyboard's way, the
 *   button keeping the focus. The new order is kept as it is dropped
 *   (`ContentTypeController::handleOrder()`), the whole list's at once, and
 *   the public page that lists them follows it.
 *
 *   A LIST GROUPED BY A CHOICE OR A TAXONOMY (`StaffView::groupBy()`) is
 *   ordered within its groups: a row dropped in another group goes back, and
 *   says why, and Up one and Down one stop at its group's edge.
 *
 * ES5, no build step, no dependency.
 */
(function () {
    'use strict';

    var list = document.querySelector('#entry-list[data-arrange]');
    if (!list) { return; }

    var url    = list.getAttribute('data-arrange');
    var parent = list.getAttribute('data-arrange-parent') || '';
    var busy   = false;
    var again  = false;

    function rows() {
        return Array.prototype.filter.call(list.children, function (el) { return el.hasAttribute('data-entry-id'); });
    }

    /** The group a row is under on the screen: the nearest heading above it. */
    function under(row) {
        for (var el = row.previousElementSibling; el; el = el.previousElementSibling) {
            if (el.classList.contains('list__group-header')) { return el.getAttribute('data-group'); }
        }
        return null;
    }

    function inPlace(row) {
        var head = list.querySelector('.entry-list__head');
        if (head && head.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_PRECEDING) { return false; }
        return !row.hasAttribute('data-group') || under(row) === row.getAttribute('data-group');
    }

    /** The order on the screen, kept, one request at a time. */
    function keep() {
        if (busy) { again = true; return; }
        var fd = new FormData();
        if (parent) { fd.append('parent_id', parent); }
        rows().forEach(function (r) { fd.append('ids[]', r.getAttribute('data-entry-id')); });
        busy = true;
        CMS.api(url, { method: 'POST', body: fd })
            .catch(function (err) { CMS.flash(err.message, 'error'); })
            .then(function () {
                busy = false;
                if (again) { again = false; keep(); }
            });
    }

    function move(row, by, button) {
        var all = rows();
        var at  = all.indexOf(row) + by;
        if (at < 0 || at >= all.length) { return; }
        var other = all[at];
        // WITHIN ITS GROUP: the row beside it in another group is past its edge.
        if (row.getAttribute('data-group') !== other.getAttribute('data-group')) { return; }
        list.insertBefore(row, by < 0 ? other : other.nextSibling);
        if (button) { button.focus(); }
        keep();
    }

    list.addEventListener('click', function (e) {
        var row = e.target.closest('[data-entry-id]');
        if (!row) { return; }
        var up = e.target.closest('[data-arrange-up]');
        if (up) { e.preventDefault(); move(row, -1, up); return; }
        var down = e.target.closest('[data-arrange-down]');
        if (down) { e.preventDefault(); move(row, 1, down); }
    });

    if (window.Reorder && typeof window.Reorder.create === 'function') {
        window.Reorder.create(list, {
            handle:      '[data-arrange-grip]',
            ghostClass:  'sortable-ghost',
            chosenClass: 'sortable-chosen',
            animation:   150,
            onEnd: function (evt) {
                var row = evt && evt.item;
                if (!row || !row.hasAttribute('data-entry-id')) { return; }
                if (!inPlace(row)) {
                    // BACK WHERE IT WAS, among the others as they stand.
                    var others = Array.prototype.filter.call(list.children, function (el) { return el !== row; });
                    list.insertBefore(row, others[evt.oldIndex] || null);
                    CMS.flash('A row moves within its own group. Change its group on its own screen.', 'warning');
                    return;
                }
                if (evt.oldIndex !== evt.newIndex) { keep(); }
            }
        });
    } else {
        console.error('list-arrange: reorder.js did not load, so the rows cannot be dragged.');
    }
}());
