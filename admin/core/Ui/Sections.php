<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * SECTION GLYPHS — the mark on a form card's section heading.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 * Every panel in a form's ASIDE column carried its own glyph through
 * `panel_title()`, and every section heading in the MAIN column was a bare word.
 * Forty-three of them across nineteen templates: Names, Addresses, Shape,
 * Identity, Sender, Fields, Password, Export. One column marked, the column
 * beside it not — a half-designed look.
 *
 * ── WHY A REGISTRY AND NOT A SLUG AT EACH CALL SITE ─────────────────────────
 * `Sidebar::iconFor()` accepts a raw slug, so every heading COULD have named its
 * own icon inline. Then there is no list to check, and `Icons::render()` returns
 * an empty string for a slug it cannot resolve — so a typo is a heading that
 * quietly loses its mark and looks exactly like a heading that never had one.
 * Only a MAP can be asserted total (`Icons::assertTotal()`). This is that map.
 *
 * ── THE MARKS ARE CHECKED PER SCREEN, NOT JUST FOR UNIQUENESS ───────────────
 * Two sections on DIFFERENT screens may share a mark — `user` heads both the
 * email Sender and the user Account, and neither screen shows the other. Two
 * sections on the SAME screen may not, and neither may a section and a control
 * sitting under it: the taxonomy form's Shape section is `object-group` and not
 * `folder-tree` precisely because `folder-tree` is the mark on its own Nested
 * radio four lines below.
 *
 * Add a section here in the same commit as the heading that uses it.
 */
final class Sections
{
    /**
     * Section key => icon slug.
     *
     * @return array<string, string>
     */
    public static function glyphs(): array
    {
        return [
            // ── Content types, taxonomies, terms ────────────────────────────
            // You TYPE a name; the mark is the writing instrument, not a label.
            'names'           => 'pen',
            'addresses'       => 'link',
            // A type's archive and how it pages (migration 090): a list.
            'archive'         => 'table-list',
            // What its entry list shows beside each title, and its order (migration 130).
            'list-view'       => 'table-columns',
            'address'         => 'link',
            // The archetype question. Same mark as the Content types nav row,
            // because that is exactly what the section is choosing the kind of.
            'archetypes'      => 'layer-group',
            // NOT `folder-tree`, which is the Nested radio's own mark inside
            // this very section — a heading wearing its own option's glyph
            // reads as though the option is already chosen.
            'shape'           => 'object-group',
            'placement'       => 'folder-tree',
            'order'           => 'sort',
            // `folder-tree`, the same glyph `placement` uses, because it is the
            // same idea: where this thing sits relative to another. Migration
            // 047's panel.
            'parent'          => 'folder-tree',
            // The types that belong to it, as rows on its editor (migration
            // 145): an ordered list.
            'children'        => 'list-ol',
            // What Copy makes of its entries (migration 147): the copy mark.
            'copies'          => 'copy',
            'used-by'         => 'file',
            // How many terms an entry takes (migration 056) — a count, so the
            // number sign.
            'how-many'        => 'hashtag',

            // ── Forms ───────────────────────────────────────────────────────
            'notifications'   => 'envelope',
            // A form's confirmation to whoever sent it (`Forms\Notify::confirm()`).
            'confirmation'    => 'paper-plane',
            'after-submit'    => 'paper-plane',
            'answers'         => 'list-check',
            'received'        => 'clock',
            // What became of each email a submission sent (migration 121).
            'emails'          => 'envelope',
            // Certain spam, counted and thrown away (`Forms\Guard`).
            'discarded'       => 'trash',
            // A form's own spam rules: its link limit, how long its page must
            // be open, and how a turned-away post is answered.
            'spam'            => 'shield-halved',
            // How a form is drawn on a page: its ids, classes and messages.
            'on-the-page'     => 'window-maximize',

            // ── Field groups ────────────────────────────────────────────────
            'fields'          => 'list',
            'settings'        => 'sliders',

            // ── Menus ───────────────────────────────────────────────────────
            'items'           => 'list-ul',
            'location'        => 'location-dot',
            'add-items'       => 'plus',

            // ── Galleries ───────────────────────────────────────────────────
            'images'          => 'images',
            'display'         => 'table-cells-large',
            // An embed is a snippet you COPY out of the screen.
            'embedding'       => 'copy',

            // ── Users ───────────────────────────────────────────────────────
            'account'         => 'user',
            // The account's admin theme (`Ui\Theme`).
            'admin-theme'     => 'droplet',
            'password'        => 'lock',

            // ── Settings ────────────────────────────────────────────────────
            'identity'        => 'bookmark',
            'dates-and-times' => 'clock',
            // HOW MANY A PUBLIC LIST SHOWS. Rows of a list; no other
            // section wears it.
            'reading'         => 'table-list',
            'assistant'       => 'comment',
            'sender'          => 'user',
            'sending-server'  => 'tower-broadcast',
            // A test sent through a form, to see it arrive.
            'test-submissions' => 'paper-plane',
            'test-email'       => 'paper-plane',
            'homepage'        => 'window-maximize',
            'sharing-image'   => 'image',
            // A pattern is a template for a line of text.
            'title-patterns'  => 'quote-left',
            'organisation'    => 'user-group',
            'robots'          => 'shield-halved',
            // Settings → Business details.
            'contact'         => 'phone',
            'opening-hours'   => 'clock',
            'place'           => 'location-dot',
            'service-areas'   => 'location-pin',
            'social-profiles' => 'share-from-square',
            // Settings → SEO → Google: what is measured, the site's proof of
            // owning itself, and the events it sends.
            'google-measurement'  => 'signal',
            'google-verification' => 'tag',
            'google-events'       => 'bolt',
            'google-consent'      => 'hand-spock',
            'add-redirect'    => 'plus',
            // The SAME panel in its other state, so the mark says which one you
            // are in — one form does both on Tools → Redirects.
            'edit-redirect'   => 'pen-to-square',
            'redirects'       => 'share',
            // How often a backup is made (`Site\Backups`).
            'backup-timetable' => 'clock',
            // Addresses visitors asked for and did not find (`SEO\NotFound`):
            // what somebody went looking for.
            'not-found'       => 'binoculars',
            // A page or an entry not on the site yet, shared by a link
            // (`Content\Share`).
            'share-draft'     => 'share-from-square',
            'pages'           => 'copy',
            'content-types'   => 'layer-group',
            'moving-an-address' => 'arrow-right-arrow-left',

            // ── Security ────────────────────────────────────────────────────
            // The door itself — the same mark the sign-in row and a sign-in in
            // the log wear.
            'sign-in-address' => 'arrow-right-to-bracket',
            // Where a turned-away visitor is pointed.
            'old-addresses'   => 'location-arrow',
            'attempt-limit'   => 'stopwatch',
            'longer-lockout'  => 'hourglass',
            // What the page SAYS — the closing mark, beside title patterns'
            // opening one.
            'lockout-message' => 'quote-right',
            // Which protections are switched on.
            'protection'      => 'toggle-on',
            'recent-activity' => 'signal',
            // The names nobody may have — a person, crossed out.
            'common-usernames' => 'user-slash',
            // Not `shield-halved`, which Robots wears in this same registry.
            'password-strength' => 'bolt',
            // Everyone signed out and sent to choose again.
            'reset-all'       => 'rotate',
            // Where an account is signed in: the machines it is on.
            'sessions'        => 'laptop-code',
            // Two-step sign-in. The phone the code comes from, on the
            // step itself and on the app it is set up with.
            'two-step'            => 'mobile',
            'authenticator-app'   => 'mobile',
            'email-code'          => 'envelope',
            // Ten codes, numbered, for the day the phone is lost.
            'backup-codes'        => 'list-ol',
            'two-step-methods'    => 'list-check',
            // Who must use it: the same mark as Users in the sidebar.
            'two-step-policy'     => 'user-group',
            'remembered-browsers' => 'clock',
            // Lowering an account's protection — the lock, open.
            'two-step-off'        => 'unlock',
            'confirm'             => 'lock',
            // Blocking. The built-in question is a sum.
            'captcha'             => 'equals',
            'blocked-addresses'   => 'location-dot',
            // What a turned-away address reads — the lockout message's mark.
            'block-message'       => 'quote-right',
            // The limit on one NAME, from every address: the account it
            // guards — Users' own mark for an account.
            'name-limit'          => 'user',
            // The page the mailed link opens — the lock, open.
            'unlock-name'         => 'unlock',
            // Someone looking for usernames: the same microscope a probe wears
            // in the Activity log. Not `binoculars` (Security → Overview's nav
            // mark) nor `magnifying-glass` (SEO's).
            'enumeration'         => 'microscope',
            // Notifications: each channel its own mark, and the
            // account's own mail a bell.
            'alerts-email'        => 'envelope',
            'alerts-slack'        => 'slack-brands',
            'alerts-webhook'      => 'radio',
            'new-device'          => 'bell',
            // Suspended accounts: the inactivity rule is a clock
            // running out; a suspended account is paused, not deleted.
            'inactive'            => 'hourglass',
            'suspended'           => 'pause',
            // Signing in without a password: six digits, and a link.
            'signin-code'         => 'hashtag',
            'signin-link'         => 'link',
            // Temporary access: the link's lifespan, and the
            // accounts it made — each a person let in for a while.
            'temporary-access'    => 'stopwatch',
            'temporary-accounts'  => 'user-group',
            // Login redirection: sent on elsewhere; the rules, in order.
            'login-redirection'   => 'share',
            'redirect-rules'      => 'list-ol',
            // Settings → Branding (`Ui\Branding`): the logo, and the sign-in page.
            'branding-logo'       => 'image',
            'branding-signin'     => 'arrow-right-to-bracket',
            // Settings → Words (`Site\Words`): one mark for each group of words.
            'words-lists'         => 'list-ul',
            'words-search'        => 'magnifying-glass',
            'words-not-found'     => 'lock',
            'words-menus'         => 'bars',
            'words-announcements' => 'bell',
            'words-hours'         => 'clock',
            'words-dates'         => 'calendar',
            'words-names'         => 'tags',
            'hsts'                => 'lock',

            // ── Tools ───────────────────────────────────────────────────────
            // TWO DIFFERENT SILHOUETTES, not a mirrored pair. These were
            // `cloud-arrow-down` and `cloud-arrow-up`, which are the obvious
            // choice and are indistinguishable at the 12px a section heading
            // draws them at — the arrow inside the cloud disappears and the two
            // sections of the Import/Export screen wear what reads as the same
            // mark. A glyph small enough to render as garbled is worse than
            // none.
            'export'          => 'cloud-arrow-down',
            'import'          => 'upload',
            // Tools → System health: a site's scheduled tasks, and their runs.
            'tasks'           => 'repeat',
            'task-runs'       => 'list-check',
            // Tools → Static copy: the copy, a file to take away; and where
            // the next one will be published, the form's first question.
            'static-copy-file' => 'file-arrow-down',
            'static-copy-make' => 'location-dot',
            'environment'     => 'hard-drive',
            'content'         => 'database',
            // System health's sample entries: the Sample chip's own note.
            'samples'         => 'note-sticky',
            // And what going to production changes: going on air.
            'going-live'      => 'tower-broadcast',
            // THE ENTRY'S BODY, and NOT `content`'s database mark — that key is
            // System health's row-count panel, where a database is exactly
            // right and here it would be nonsense. `align-left` is lines of
            // prose, the same reasoning ArchetypeDefaults gives for the `post`
            // archetype wearing it: the two are the same idea seen from the
            // type form and from the entry form.
            'body'            => 'align-left',
            // THE ENTRY'S EXCERPT (migration 053): a short note that stands
            // for the whole. NOT `quote-left` or `scroll` — the rich-text
            // editor's own toolbar wears both, directly above it, on the same
            // screen, and a heading that repeats a control's mark reads as
            // though it belongs to that control.
            'excerpt'         => 'note-sticky',
            // WHO WROTE IT — the entry form's Author control (migration 055).
            // `user`, which no other section on that screen wears.
            'author'          => 'user',
            // THE ENTRY LIST'S BULK EDIT: many entries edited at
            // once. `pen-to-square`, the edit mark — the list screen wears no
            // other, and `edit-redirect` above is on a different screen.
            'bulk-edit'       => 'pen-to-square',

            // ── Commerce ────────────────────────────────────────────────────
            // `order-items` and not `items`: `items` is the gallery's list-ul,
            // and a gallery's items are pictures being arranged while an
            // order's are things that were sold. Same word, different idea, and
            // sharing the key would give the two panels the same mark for no
            // reason a reader could name.
            //
            // `box-open` is the same glyph the sidebar's Orders row and the
            // `processing` status chip wear, so an order is marked identically
            // wherever it appears — which is what stops a reader learning three
            // marks for one thing.
            'order-items'     => 'box-open',
            'customer'        => 'user',
            // NOT `clock`, which is `received` — the timestamp on a form
            // submission. A history is a sequence of things that happened, and
            // `list-ol` is the ordered list that says so.
            'history'         => 'list-ol',
            // A page's or an entry's other language (`Content\Translation`).
            'translation'     => 'language',
            'order-status'    => 'sort',
            'payment'         => 'sliders',
            // TAX IS A DOCUMENT, not a setting — `scroll` is the rolled-up
            // paper a rate arrives on. NOT `percent`, which this set has not
            // got, and not `sliders`, which is `payment` above: two panels on
            // the same screen wearing one mark is how a reader learns that the
            // marks mean nothing.
            'tax'             => 'scroll',
            // NOT `box-open`, which is the Orders row, the `processing` chip
            // and the order-items panel — three places already wearing it for
            // "an order". Delivery is the thing GOING somewhere, and
            // `paper-plane` is the only send in this set.
            'delivery'        => 'paper-plane',
            // The same mark the sidebar's Discount codes row wears, so a code
            // is marked identically wherever it appears — which is what stops a
            // reader learning two marks for one thing.
            'coupons'         => 'tag',
            // WHAT A RECEIPT CARRIES and what the checkout asks the customer
            // to agree to. `print`, because a receipt is the thing printed;
            // no other section wears it.
            'receipts'        => 'print',
            // WHERE THE PARCEL IS: the carrier and number an order went with.
            // `location-crosshairs`, a position being followed — NOT
            // `location-dot`, which is an event's place and a blocked address.
            'tracking'        => 'location-crosshairs',
            // AN ORDER'S EMAILS TO ITS CUSTOMER, sent again from the order and
            // turned off or on in Settings → Shop. `envelope`, the mark every
            // other section about an email wears.
            'customer-mail'   => 'envelope',
            // WHAT IS ON THE SHELF, counted: the number sign, as How many
            // wears it, and the same mark the sidebar's Stock row wears.
            'stock'           => 'hashtag',
            // THE PRODUCTS SENT ON, to Google Shopping and Instagram: shared
            // out of this site. NOT `tower-broadcast`, the sending server's.
            'feed'            => 'share-from-square',
            // A WEBHOOK PASSES AN ORDER ON to another program as it moves.
            'webhooks'        => 'retweet',
            // A product sold in several forms. `clone` is one thing drawn
            // twice, which is exactly what a size run is — NOT `tags`, which is
            // one mark away from `tag` on Discount codes and would read as the
            // same idea.
            'variants'        => 'clone',
        ];
    }

    /**
     * The glyph slug for a section key.
     *
     * Unlike `Sidebar::iconFor()` there is NO fallback and no raw-slug escape
     * hatch: this map is asserted total, so a key that is not in it is a
     * programming error and returning a chevron would hide it behind a mark that
     * looks deliberate. An unknown key logs by name, the same way an unresolved
     * nav key does.
     */
    public static function glyphFor(string $key): string
    {
        $slug = self::glyphs()[$key] ?? null;
        if ($slug === null) {
            error_log('section: no glyph for section key "' . $key . '" — register it in Ui\Sections');
            return '';
        }

        return $slug;
    }
}
