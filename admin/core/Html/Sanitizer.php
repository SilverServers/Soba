<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * AN ALLOWLIST HTML SANITISER. Nothing survives that is not named here.
 *
 * ── THE FOUNDATION, NOT THE EDITOR ──────────────────────────────────────────
 * `templates/post.php` and `templates/page.php` both do
 * `<?= Shortcode::process($post['content']) ?>` — unescaped, by design, because
 * an entry's body is meant to contain markup. With no sanitiser, whatever an
 * author typed would reach every visitor's browser verbatim. That is
 * survivable while the only way to type into that box is a plain textarea used
 * by the site's own staff.
 *
 * A rich-text editor makes it worse in two ways at once: it PRODUCES markup, so
 * far more of it flows through, and it invites paste-from-Word — which arrives
 * carrying `<script>`, `<style>`, `<o:p>`, event handlers and inline styles from
 * a document nobody in this building wrote.
 *
 * So the editor is not the foundation; this is. The editor is a convenience over
 * a guarantee that holds whether or not the editor was used, whether or not
 * JavaScript ran, and whether the content arrived from the admin, an import, the
 * assistant or a hand-made POST.
 *
 * ── AN ALLOWLIST, NEVER A BLOCKLIST ─────────────────────────────────────────
 * "Strip `<script>`" is a blocklist, and blocklists lose: `<img onerror>`,
 * `<svg><script>`, `<a href="javascript:">`, `<iframe srcdoc>`, `<math>`,
 * `<style>@import`, and a new one every few years. Everything not in `TAGS` is
 * removed, so a tag invented after this file was written is removed by default.
 *
 * ── AND THE ATTRIBUTES ARE PER TAG ──────────────────────────────────────────
 * A global attribute allowlist would let `href` onto a `<p>` — harmless — and
 * `src` onto anything, which is not. Each tag names what it may carry.
 *
 * ── PARSED, NOT REGEXED ─────────────────────────────────────────────────────
 * `preg_replace` over HTML is the classic wrong answer: `<scr<script>ipt>`
 * defeats one pass, attribute values containing `>` defeat another, and a
 * comment can hide anything. `DOMDocument` is in PHP's core `dom` extension —
 * no dependency — and it parses the way a browser does, so
 * what this walks is what a browser would build.
 *
 * ── WHAT IT DELIBERATELY LEAVES ALONE ───────────────────────────────────────
 * Shortcodes. `[gallery id="1"]` is not HTML, so it is text to the parser and
 * comes out the other side untouched — `Shortcode::process()` still finds it.
 */
final class Sanitizer
{
    /**
     * tag => attributes it may keep.
     *
     * ── WHAT IS NOT HERE, AND WHY ──────────────────────────────────────────
     * `script`, `style`, `iframe`, `object`, `embed`, `form`, `input`,
     * `button`, `svg`, `math`, `link`, `meta`, `base` — every one of these is
     * either executable, a way to load something executable, or a way to change
     * where a form posts. None of them belongs in an entry's body, except as
     * part of an embed from one of five providers, kept only as the provider's
     * code writes it (`Html\Embed`).
     *
     * `class` is allowed on almost nothing, and where it IS allowed only the
     * VALUES in `CLASSES` and `TEXT_CLASSES` survive. An author cannot reach
     * into the theme's stylesheet, and a pasted `class="MsoNormal"` is Word's,
     * not ours.
     * `style` is allowed on nothing either: it is how a paste from a word
     * processor carries a wall of `font-family` declarations, and how
     * `position:fixed;inset:0` covers the page.
     *
     * `id` is allowed on HEADINGS ONLY, as a slug, once per body — a heading's
     * anchor, which is what an in-page link (`href="#pricing"`) lands on.
     * Anywhere else a pasted id is how the page's own ids get duplicated — a
     * `<div id="main">` from another site breaks every anchor and label on the
     * page around it — so it goes. See `anchor()`.
     *
     * `srcset` and `sizes` are not here either: they name image files at sizes
     * this library does not make yet, so a body keeping them would
     * point browsers at pictures that are not there.
     */
    public const TAGS = [
        // Structure
        // `class` HERE CARRIES TEXT ALIGNMENT AND NOTHING ELSE — see
        // `TEXT_CLASSES`, which is a different vocabulary from `CLASSES` for a
        // reason spelled out there.
        'p'          => ['class'],
        'br'         => [],
        'hr'         => [],
        'h2'         => ['class', 'id'], 'h3' => ['class', 'id'], 'h4' => ['class', 'id'],
        'h5'         => ['class', 'id'], 'h6' => ['class', 'id'],
        // NO `h1`. The page's own title is the h1 — `post.php` renders it — and
        // a second one in the body is a document with two titles, which is a
        // real accessibility and SEO fault rather than a matter of taste. One
        // that arrives is RENAMED to `h2` (`ALIASES`), not unwrapped: the words
        // were a heading, and flattening them into the paragraph after is the
        // software deciding the author's structure did not matter.
        'blockquote' => ['class'],
        'pre'        => [],
        'div'        => [],

        // Inline
        'strong'     => [], 'b'   => [],
        'em'         => [], 'i'   => [],
        'u'          => [], 's'   => [], 'del' => [], 'ins' => [],
        'code'       => [],
        'sub'        => [], 'sup' => [],
        'span'       => [],

        // Lists
        // `start` (digits only): a numbered list that carries on after a
        // paragraph begins at 3, not again at 1. Markdown writes it so, and
        // without it an imported "3. Three" became a second "1.".
        'ul' => [], 'ol' => ['start'], 'li' => ['class'],
        'dl' => [], 'dt' => [], 'dd' => [],

        // Links and media
        'a'   => ['href', 'title', 'target', 'rel'],
        // `class` ON THESE TWO TAKES `CLASSES` — the float/wrap vocabulary —
        // and nothing else. Alignment is the one thing an author decides about
        // an image that cannot be expressed any other way: text wrapping left
        // or right is the difference between a page that reads and a stack of
        // pictures. The blocks above take `TEXT_CLASSES` instead, and the two
        // lists do not mix; see `TEXT_CLASSES` for why they must not.
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'class'],
        'figure'     => ['class'],
        'figcaption' => [],

        // Tables
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['scope', 'colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
        'caption' => [],
    ];

    /**
     * The ONLY class values that survive, and only on `img` and `figure`.
     *
     * ── THIS IS NOT "class IS ALLOWED" ─────────────────────────────────────
     * `TAGS`' own note says `class` is allowed on nothing, because an author
     * must not be able to reach into the theme's stylesheet and a pasted
     * `class="MsoNormal"` is Word's rather than ours. Both hold: the attribute
     * is checked against this list by VALUE, so `class="site-header"` is
     * removed, and a paste from a word processor brings nothing through.
     *
     * The exception is alignment, which has no other expression. It is not
     * styling in the sense the rule forbids — it is the author saying "this
     * picture belongs on the right and the text wraps round it", which is a
     * structural decision about the document and the single most-used control
     * in any editor's image menu.
     *
     * The alternative would be `style="float:right"`, which is the attribute
     * this file exists to strip, or a `data-` attribute the public theme would
     * then have to style — the same thing with an extra step and a worse name.
     *
     * The theme owns what these MEAN. An install can restyle `.align-right`;
     * it cannot invent a sixth value here.
     */
    public const CLASSES = ['align-left', 'align-center', 'align-right', 'align-none'];

    /**
     * Block-level TEXT ALIGNMENT — a different vocabulary, on purpose.
     *
     * ── WHY NOT REUSE `align-right` ────────────────────────────────────────
     * On an `img`, `align-right` means *float it right and wrap the text round
     * it*. On a paragraph it would have to mean *set text-align to right*, which
     * is not the same instruction and does not produce the same layout. One word
     * for two behaviours means the public theme cannot style either correctly —
     * a `<p class="align-right">` would either float the paragraph or the
     * pictures would stop wrapping, depending on which rule was written last.
     *
     * So blocks get `text-left` / `text-center` / `text-right`, the filter picks
     * the list by TAG, and neither vocabulary can leak onto the other's
     * elements: `<img class="text-center">` loses the class exactly as
     * `<p class="align-right">` does.
     *
     * There is no `text-justify`. Justified text on the web has no hyphenation
     * to work with and produces rivers of white space at narrow widths, which is
     * a legibility fault on exactly the devices most visitors are using.
     *
     * As with `CLASSES`, the theme owns what these MEAN; an install can restyle
     * them and cannot invent a fourth.
     */
    public const TEXT_CLASSES = ['text-left', 'text-center', 'text-right'];

    /**
     * Deprecated spellings that MEAN one of the allowed tags.
     *
     * Renamed rather than unwrapped, because unwrapping loses the formatting
     * while the author's words survive — which reads as the software quietly
     * deciding their emphasis did not matter.
     *
     * `<strike>` is what `execCommand('strikeThrough')` still emits in
     * Chromium. The editor renames its own output before it posts; this catches
     * the same tag arriving from the HTML view, an import, or a paste out of a
     * document written twenty years ago, which is the only reason the sanitiser
     * and not the editor is the guarantee.
     *
     * `<h1>` → `<h2>`: a second title in a body is still a heading, one level
     * down.
     *
     * `<font>` is deliberately not here. Its whole purpose is `color`, `face`
     * and `size`, none of which survives here — renaming it to `<span>` would
     * keep an element that means nothing instead of removing one that means
     * nothing. It is unwrapped, and that is right.
     */
    private const ALIASES = ['strike' => 's', 'h1' => 'h2'];

    /**
     * WORDPRESS'S WORDS FOR THE SAME TWO THINGS. Content written
     * there says `alignright` where this file says `align-right`, and the
     * block editor's `has-text-align-center` where this says `text-center`.
     * TRANSLATED, not accepted: what comes out is still only `CLASSES` and
     * `TEXT_CLASSES`, filtered by tag as ever — `<p class="alignright">`
     * becomes `align-right`, which a paragraph may not wear, and goes.
     */
    private const CLASS_ALIASES = [
        'alignleft'             => 'align-left',
        'aligncenter'           => 'align-center',
        'alignright'            => 'align-right',
        'alignnone'             => 'align-none',
        'has-text-align-left'   => 'text-left',
        'has-text-align-center' => 'text-center',
        'has-text-align-right'  => 'text-right',
    ];

    /** The anchors this call has kept — one each (`anchor()`). */
    private static array $anchors = [];

    /** The providers whose embed this body has, by class (`Html\Embed`). */
    private static array $embedded = [];

    /** The providers' scripts this body has, each with its provider, until `clean()` knows whether it has an embed to draw. */
    private static array $scripts = [];

    /**
     * Tags whose CONTENT goes with them, rather than being unwrapped.
     *
     * `iframe` and `script` STAY HERE, with one exception: an embed from one of
     * five providers is kept as pasted — the provider's frame with only the
     * attributes its code writes, and its script with nothing inside it
     * (`Html\Embed`). Every other iframe and script still goes with its
     * contents.
     */
    private const DROP_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript'];

    /**
     * Tags that may contain INLINE CONTENT ONLY.
     *
     * ── WHY A STRUCTURAL RULE AT ALL ───────────────────────────────────────
     * The allowlist above answers "may this tag exist?" and "may it carry this
     * attribute?" — it says nothing about whether one tag may be inside
     * another. That gap is real: a list inside a heading,
     * `<h2><ul><li>…</li></ul></h2>`, is made of allowed tags only, so the
     * allowlist alone lets it straight through.
     *
     * A heading may contain inline content only. Browsers do not reject the
     * invalid version, they re-parse it — differently from each other — so what
     * the author sees in the editor and what a visitor sees can genuinely
     * differ. The editor does not create it; this catches it arriving from
     * anywhere else, which is the whole reason the sanitiser and not the editor
     * is the guarantee.
     *
     * There are TWO structural rules here and no more. A general "is this
     * nesting legal" check is the HTML content model, which is large, and
     * inventing half of it would be worse than naming the cases that actually
     * occur. See `reparentStrayList()` for the second.
     */
    private const INLINE_ONLY = ['h2', 'h3', 'h4', 'h5', 'h6', 'p'];

    /** Tags that are block-level for the purposes of the rule above. */
    private const BLOCKS = [
        'p', 'div', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'hr',
    ];

    /** URL schemes an `href` or `src` may use. */
    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Clean a fragment of HTML.
     *
     * Empty in, empty out. Anything unparseable comes back as escaped text
     * rather than as nothing — losing an author's work to a parse error is a
     * worse failure than showing them their own angle brackets.
     */
    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument();

        // libxml does not know HTML5's tags and complains about every one. The
        // complaints are noise — the parse itself is fine — and left on they
        // would fill `php_error.log` and bury the errors that matter.
        $previous = libxml_use_internal_errors(true);

        // THE ENCODING DECLARATION IS NOT OPTIONAL. `loadHTML()` assumes
        // ISO-8859-1 for a string with no declared charset, so "Café" comes back
        // as "CafÃ©" — silently, on the way through a function whose job is to
        // leave content alone. The meta tag is the documented fix and is
        // stripped again below with the rest of the wrapper.
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="cms-sanitizer-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }

        $root = $doc->getElementById('cms-sanitizer-root');
        if (!$root instanceof DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }

        // One set of anchors per body; the wrapper's own id is never one.
        self::$anchors = ['cms-sanitizer-root' => true];
        self::$embedded = [];
        self::$scripts = [];
        self::walk($root);

        // A PROVIDER'S SCRIPT WITH NOTHING TO DRAW GOES. Decided after the
        // whole body is walked, because the script may come before its embed
        // as well as after it.
        foreach (self::$scripts as [$script, $provider]) {
            if (!isset(self::$embedded[$provider])) {
                $script->parentNode?->removeChild($script);
            }
        }
        self::$scripts = [];

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Walk depth-first, removing or unwrapping as we go.
     *
     * ── THE CHILD LIST IS COPIED BEFORE ITERATING ──────────────────────────
     * `$node->childNodes` is LIVE: removing a child while iterating it skips
     * the next one, so a document with two adjacent `<script>` tags keeps the
     * second. `iterator_to_array()` takes a snapshot first. This is the single
     * most common way a hand-written DOM sanitiser is wrong, and it fails
     * quietly — it removes SOME of what it should.
     */
    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::handleElement($child);
                continue;
            }

            // COMMENTS GO. `<!--[if IE]><script>` is a conditional comment that
            // some parsers execute, and a comment is never content an author
            // meant to publish.
            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode?->removeChild($child);
                continue;
            }

            // A CDATA section or a processing instruction is not content either.
            if (in_array($child->nodeType, [XML_CDATA_SECTION_NODE, XML_PI_NODE], true)) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /**
     * A provider's frame, as its embed code writes it: only its own attributes
     * (`srcdoc`, `sandbox`, `on*` and the rest go), a size only if it is a
     * number, a style only if it is the provider's own, and nothing inside the
     * frame.
     *
     * @param class-string<EmbedFrame> $provider
     */
    private static function keepFrame(DOMElement $el, string $provider): void
    {
        $wrapped = $el->parentNode instanceof DOMElement && Embed::box($el->parentNode) === $provider;
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim((string) $attr->nodeValue);
            if (!in_array($name, $provider::attributes(), true)
                || (in_array($name, ['width', 'height', 'frameborder'], true) && preg_match('/^\d{1,4}%?$/', $value) !== 1)
                || ($name === 'scrolling' && !in_array(strtolower($value), ['no', 'yes', 'auto'], true))
                || ($name === 'style' && !$provider::frameStyle($value, $wrapped))) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            $el->removeChild($child);
        }
        self::bare($el);
        self::$embedded[$provider] = true;
    }

    /**
     * A provider's script: one of the addresses its code writes, the way it
     * loads (`async`, `defer`, `charset`) and nothing inside it. Kept only if
     * the body has the provider's embed — `clean()` decides once it has seen
     * the whole body.
     */
    private static function keepScript(DOMElement $el, string $provider): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim((string) $attr->nodeValue);
            if (!in_array($name, ['src', 'async', 'defer', 'charset'], true)
                || (in_array($name, ['async', 'defer'], true) && !in_array(strtolower($value), ['', $name], true))
                || ($name === 'charset' && strtolower($value) !== 'utf-8')) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        foreach (iterator_to_array($el->childNodes) as $child) {
            $el->removeChild($child);
        }
        self::bare($el);
        self::$scripts[] = [$el, $provider];
    }

    /**
     * A provider's quote, as its embed code writes it: each tag of the
     * provider's keeps only what the provider writes on it (`keeps()`), every
     * other tag inside it is cleaned as it would be anywhere in a body, and a
     * link that loses its address stops being a link. No provider's code puts a
     * script or a frame inside its quote, so even a provider's own goes from
     * there.
     *
     * No `rel` is added to the provider's `target="_blank"` links: the page
     * they open is the provider's, whose script already runs in this one.
     *
     * @param class-string<EmbedQuote> $provider
     */
    private static function keepQuote(DOMElement $el, string $provider): void
    {
        $tag = strtolower($el->nodeName);
        if (in_array($tag, ['script', 'iframe'], true)) {
            $el->parentNode?->removeChild($el);
            return;
        }
        if (!in_array($tag, $provider::tags(), true)) {
            self::handleElement($el);
            return;
        }
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            if (!$provider::keeps($tag, strtolower($attr->nodeName), trim((string) $attr->nodeValue))) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        self::bare($el);
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::keepQuote($child, $provider);
            } elseif (in_array($child->nodeType, [XML_COMMENT_NODE, XML_CDATA_SECTION_NODE, XML_PI_NODE], true)) {
                $el->removeChild($child);
            }
        }
        if ($tag === 'a' && !$el->hasAttribute('href')) {
            self::unwrap($el);
        }
        self::$embedded[$provider] = true;
    }

    /**
     * AN EMPTY ATTRIBUTE IS WRITTEN BARE, IN ITS PLACE. A provider's code
     * writes `allowfullscreen`, `async` and the like bare, and a browser hands
     * a bare one back from the editor as `=""`. One written out in full
     * (`allowfullscreen="allowfullscreen"`, as an earlier release stored embed
     * codes, or Facebook's `allowfullscreen="true"`) is kept as it is. What is
     * stored is the code as pasted.
     */
    private static function bare(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            if ($attr->nodeValue === '') {
                while ($attr->firstChild !== null) {
                    $attr->removeChild($attr->firstChild);
                }
            }
        }
    }

    private static function handleElement(DOMElement $el): void
    {
        $tag = strtolower($el->nodeName);

        // ── A DEPRECATED SPELLING IS RENAMED, NOT DISCARDED ────────────────
        // `<strike>` means `<s>`. Falling through to the unknown-tag branch
        // below would keep the words and silently drop the strikethrough, which
        // is the software deciding the author's emphasis did not matter.
        if (isset(self::ALIASES[$tag])) {
            $new = $el->ownerDocument->createElement(self::ALIASES[$tag]);
            foreach (iterator_to_array($el->childNodes) as $child) {
                $new->appendChild($child);
            }
            $el->parentNode?->replaceChild($new, $el);
            self::handleElement($new);
            return;
        }

        // ── EXECUTABLE TAGS TAKE THEIR CONTENTS WITH THEM ──────────────────
        // Unwrapping `<script>alert(1)</script>` would leave `alert(1)` as
        // visible text on the page — not dangerous, but nonsense the author
        // never typed. `<style>` unwrapped is worse: a wall of CSS as prose.
        if (in_array($tag, self::DROP_CONTENT, true)) {
            // A PROVIDER'S FRAME OR SCRIPT IS KEPT — see `DROP_CONTENT`.
            $provider = match ($tag) {
                'iframe' => Embed::frame($el->getAttribute('src')),
                'script' => Embed::script($el->getAttribute('src')),
                default  => null,
            };
            if ($provider !== null) {
                $tag === 'iframe' ? self::keepFrame($el, $provider) : self::keepScript($el, $provider);
                return;
            }
            $el->parentNode?->removeChild($el);
            return;
        }

        // A PROVIDER'S QUOTE, AND A PROVIDER'S BOX ROUND ITS FRAME, keep what
        // the provider's code writes on them, which `TAGS` would not.
        if ($tag === 'blockquote' && ($provider = Embed::quote($el)) !== null) {
            self::keepQuote($el, $provider);
            return;
        }
        if ($tag === 'div' && Embed::box($el) !== null) {
            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                if (strtolower($attr->nodeName) !== 'style') {
                    $el->removeAttribute($attr->nodeName);
                }
            }
            self::walk($el);
            return;
        }

        if (!isset(self::TAGS[$tag])) {
            // ── AN UNKNOWN TAG IS UNWRAPPED, NOT DELETED ───────────────────
            // `<font>`, `<center>`, `<o:p>` and every other thing a word
            // processor emits wrap text the author DID write. Deleting them
            // deletes the paragraph; unwrapping keeps the words and loses the
            // tag, which is what "paste as plain formatting" should mean.
            self::walk($el);
            self::unwrap($el);
            return;
        }

        // Attributes first, then descend — so an element that is about to lose
        // its `href` is still walked for its children.
        $allowed = self::TAGS[$tag];
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);

            if (!in_array($name, $allowed, true)) {
                // This is where `onclick`, `onerror`, `style`, `class`, `id`,
                // `srcdoc`, `formaction` and everything else go — not by being
                // listed, but by not being listed.
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (($name === 'href' || $name === 'src') && !self::safeUrl($attr->nodeValue ?? '')) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if ($name === 'id' && !self::anchor((string) $attr->nodeValue)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if ($name === 'start' && preg_match('/^\d{1,9}$/', trim((string) $attr->nodeValue)) !== 1) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            // ── A CLASS IS KEPT ONLY IF THIS FILE NAMES THE VALUE ──────────
            // Filtered rather than accepted or rejected whole: an image that
            // arrives with `class="align-right MsoNormal"` keeps the alignment
            // and loses the rest, which is what a paste from a word processor
            // over an aligned image actually looks like.
            if ($name === 'class') {
                // BY TAG. `img` and `figure` take the float/wrap vocabulary;
                // every other element that may carry a class takes the
                // text-alignment one. Sharing a single list would let
                // `<p class="align-right">` through, which the theme floats.
                $vocabulary = in_array($tag, ['img', 'figure'], true)
                    ? self::CLASSES
                    : self::TEXT_CLASSES;
                $keep = array_values(array_unique(array_intersect(
                    array_map(
                        static fn (string $c): string => self::CLASS_ALIASES[$c] ?? $c,
                        preg_split('/\s+/', trim((string) $attr->nodeValue)) ?: []
                    ),
                    $vocabulary
                )));
                if ($keep === []) {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('class', implode(' ', $keep));
                }
            }
        }

        // ── A LINK THAT OPENS A NEW TAB GETS rel="noopener" ────────────────
        // `target="_blank"` without it hands the opened page a `window.opener`
        // reference back to ours, which it can navigate. Added rather than
        // required, because an author choosing "open in a new tab" should not
        // have to know this.
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $rel = trim($el->getAttribute('rel') . ' noopener noreferrer');
            $el->setAttribute('rel', implode(' ', array_unique(explode(' ', $rel))));
        }

        // A LINK WITH NO href IS NOT A LINK. Unwrapped rather than kept, so a
        // `javascript:` href that was just stripped does not leave behind an
        // underlined phrase that does nothing when clicked.
        if ($tag === 'a' && $el->getAttribute('href') === '') {
            self::walk($el);
            self::unwrap($el);
            return;
        }

        self::walk($el);

        // ── AND THE ONE STRUCTURAL RULE ────────────────────────────────────
        // Checked AFTER descending, so the children have already been cleaned
        // and what is measured is what will actually be stored.
        //
        // The HEADING is unwrapped, not the list. Deleting the block would
        // delete the author's words; unwrapping the heading keeps every word
        // and loses only the thing that could not legally be there. A heading
        // that is really a list was a heading by accident.
        if (in_array($tag, self::INLINE_ONLY, true) && self::hasBlockChild($el)) {
            self::unwrap($el);
        }

        // ── AND THE SECOND: LISTS THAT ARE NESTED WRONG ────────────────────
        if ($tag === 'ul' || $tag === 'ol') {
            self::reparentStrayList($el);
        }
        if ($tag === 'li') {
            self::unnestListItem($el);
        }
    }

    /**
     * MAY A HEADING KEEP THIS ANCHOR? A slug — a letter first, then letters,
     * digits, `-` or `_`, up to 80 — and the first of its name in this body.
     * A second `id="pricing"` would make `#pricing` land on whichever the
     * browser picks, so it goes and the first keeps it.
     */
    private static function anchor(string $id): bool
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/', $id) !== 1 || isset(self::$anchors[$id])) {
            return false;
        }
        self::$anchors[$id] = true;

        return true;
    }

    /**
     * An `li` inside an `li` is two items, not one containing another.
     *
     * The other half of the indent/outdent pair. `execCommand('outdent')`
     * unwraps the sub-list and leaves the item where it was, giving
     * `<li>One<li>Two</li></li>` — which browsers RENDER as two items, so
     * nothing looks wrong, and which a screen reader reads as one item whose
     * text runs together: "OneTwo" is one word.
     *
     * The inner item becomes the next sibling of its host, which is what
     * outdent was asked for. A sub-LIST inside an `li` is correct and is left
     * alone; only a bare `li` is moved.
     */
    private static function unnestListItem(DOMElement $item): void
    {
        foreach (iterator_to_array($item->childNodes) as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $item->parentNode?->insertBefore($child, $item->nextSibling);
            }
        }
    }

    /**
     * A sub-list must be inside the `li` it belongs to, not beside it.
     *
     * ── WHAT THE INDENT BUTTON MAKES ───────────────────────────────────────
     * `document.execCommand('indent')` on a list item produces
     * `<ul><li>one</li><ul><li>two</li></ul></ul>` in Chromium — the sub-list is
     * a SIBLING of the item it is nested under, and a `ul` may contain nothing
     * but `li`. Every tag in it is on the allowlist, so it would go through
     * exactly as `<h2><ul>…</ul></h2>` would, and for the same reason: the
     * allowlist answers "may this tag exist", never "may it be there".
     *
     * Browsers render it as though it were nested, so nothing looks wrong on
     * the screen. They also re-parse it differently from each other, and a
     * screen reader announces a list with a phantom empty item. The editor
     * repairs its own output; this is the guarantee, because the HTML view, an
     * import and a paste can all produce the same shape.
     *
     * The stray list moves into the `li` BEFORE it — that is the item it was
     * indented under and the only reading that keeps the author's meaning. With
     * no previous `li` there is nothing to nest into, so one is made rather than
     * the content being dropped.
     */
    private static function reparentStrayList(DOMElement $list): void
    {
        foreach (iterator_to_array($list->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $name = strtolower($child->nodeName);
            if ($name !== 'ul' && $name !== 'ol') {
                continue;
            }

            $prev = $child->previousSibling;
            while ($prev !== null
                   && !($prev instanceof DOMElement && strtolower($prev->nodeName) === 'li')) {
                $prev = $prev->previousSibling;
            }

            if ($prev === null) {
                $prev = $list->ownerDocument->createElement('li');
                $list->insertBefore($prev, $child);
            }

            $prev->appendChild($child);
        }
    }

    private static function hasBlockChild(DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement
                && in_array(strtolower($child->nodeName), self::BLOCKS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Move an element's children up into its place, then remove it. */
    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            $parent->insertBefore($child, $el);
        }

        $parent->removeChild($el);
    }

    /**
     * May this URL be linked or loaded?
     *
     * ── SCHEME-RELATIVE AND ROOT-RELATIVE ARE FINE; `javascript:` IS NOT ────
     * `parse_url()` alone is not enough: `java\nscript:alert(1)` has no scheme
     * as far as it is concerned, and browsers strip the newline and run it. So
     * control characters and whitespace come out FIRST, and only then is the
     * scheme read.
     *
     * A URL with no scheme at all — `/about`, `about.html`, `#top` — is
     * allowed, because that is what an internal link looks like.
     */
    private static function safeUrl(string $url): bool
    {
        // Strip every control character and all whitespace before deciding.
        // `javascript&#09;:` and `java\0script:` are both real bypasses.
        $clean = preg_replace('/[\x00-\x20\x7F]+/u', '', $url) ?? '';
        $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
        $clean = preg_replace('/[\x00-\x20\x7F]+/u', '', $clean) ?? '';

        if ($clean === '') {
            return false;
        }

        // No colon before the first `/`, `?` or `#` means no scheme, which
        // means it is relative. `//example.com` is scheme-relative and fine.
        if (!preg_match('#^([a-z][a-z0-9+.\-]*):#i', $clean, $m)) {
            return true;
        }

        return in_array(strtolower($m[1]), self::SCHEMES, true);
    }
}
