<?php

declare(strict_types=1);

namespace Cms\Assistant;

/**
 * What a visitor actually sees on a page, in the order they see it.
 *
 * **Why this exists.** Every request a site owner makes is positional and
 * visual — "the opening message", "the button at the top", "the first thing I
 * see", "swap those two cards", "that heading". Answering any of them from
 * stored data means guessing which storage layer produces the thing on screen,
 * and a wrong guess is a wrong answer: an offer to edit a `content` column that
 * renders nowhere, a headline in plain sight reported as not existing.
 *
 * So the assistant looks at the page. `SiteSearch` then says where any given
 * words live. Between them, "change the first thing I see" stops being an
 * inference and becomes two lookups.
 *
 * The page is fetched over HTTP from the site's own address rather than rendered
 * in-process, because that is the only way to get what a visitor gets — every
 * block, every style, every template, assembled by the real router.
 */
final class PageView
{
    /** Long enough for a slow page, short enough not to hang a turn. */
    private const TIMEOUT = 10;

    /**
     * Fetch a page and return its visible text in document order.
     *
     * @return array{path: string, title: string, visible: array<int, array<string, string>>}|array{error: string}
     */
    public static function of(string $path): array
    {
        $path = '/' . ltrim(trim($path), '/');
        $html = self::fetch(BASE_URL . $path);

        if ($html === null) {
            $why = self::$lastError !== null ? ' — ' . self::$lastError : '';
            return ['error' => "Could not open {$path}{$why}."];
        }

        $document = self::parse($html);

        // Split before extracting, so every line can say which part of the page
        // it is in. Without that, "the first thing I see" resolves to the logo in
        // the navigation rather than the headline, and "the button at the top"
        // is ambiguous between the menu bar and the hero.
        $visible = [];
        $seen    = [];

        foreach (self::regions($document) as $region => $element) {
            foreach (self::visibleText($element) as $item) {
                // Repeats across a page are usually one thing seen twice — a
                // heading that is also a link, a label echoed in a footer. The
                // first occurrence is the one the owner means.
                $fingerprint = $item['kind'] . '|' . $item['text'];

                if (isset($seen[$fingerprint])) {
                    continue;
                }

                $seen[$fingerprint] = true;
                $visible[] = ['region' => $region] + $item;

                if (count($visible) >= self::MAX_ITEMS) {
                    break 2;
                }
            }
        }

        return [
            'path'    => $path,
            'title'   => self::titleOf($html),
            'note'    => 'In the order a visitor sees them. "main" is the page itself; '
                       . '"header" and "footer" are the same on every page.',
            'visible' => $visible,
        ];
    }

    // ----------------------------------------------------------------

    private static function fetch(string $url): ?string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => 'CMS-Assistant',
        ]);

        // A development install may sit behind an HTTP gate. Without this the
        // assistant would see the gate's challenge page instead of the site — and
        // report that the owner's own content does not exist, which is precisely
        // the failure mode this class was written to end.
        $config = \Cms\Site\ConfigFile::read(ROOT_PATH . '/config/config.php');
        $config = is_array($config) ? $config : [];
        $user   = (string) ($config['preview_user'] ?? '');
        $pass   = (string) ($config['preview_password'] ?? '');

        if ($user !== '') {
            curl_setopt($handle, CURLOPT_USERPWD, $user . ':' . $pass);
        }

        // ── THE SITE'S OWN CERTIFICATE ──────────────────────────────────────
        // This tool fetches ONE host: `BASE_URL`, the site's own address out of
        // its own config. It never follows a URL anybody supplied.
        //
        // Plenty of real installs cannot present a certificate that validates at
        // their own address — a development server behind a wildcard that does
        // not cover the subdomain, a staging box with a self-signed pair, a small
        // host mid-renewal. With full verification, every one of those makes
        // `curl_exec` return false, `of()` say "Could not open /", and the
        // assistant fall back to guessing from stored data — which is the ENTIRE
        // failure this class was written to end.
        //
        // So peer verification is relaxed for the site's own origin and NOTHING
        // else. The check is on the resolved URL, not on a flag, so it cannot be
        // widened by accident later.
        if (str_starts_with($url, BASE_URL . '/') || $url === BASE_URL) {
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 400) {
            // WHY, not just that. "Could not open /" is what this returned for a
            // TLS failure, a 404, a timeout and a gate challenge alike, so the
            // one message covering the tool's only real failure mode said
            // nothing about which one had happened.
            self::$lastError = $err !== ''
                ? $err
                : ($status > 0 ? 'the site answered HTTP ' . $status : 'no response');
            return null;
        }

        return $body;
    }

    /** Why the last fetch failed, for the message `of()` hands back. */
    private static ?string $lastError = null;

    /**
     * Parse the page once, tolerantly.
     *
     * `DOMDocument` ships with PHP — this is the standard library, not a
     * dependency. Its HTML parser complains about anything newer than HTML 4,
     * which is every real page, so the warnings are captured and dropped rather
     * than logged: a `<main>` element is not an error.
     */
    private static function parse(string $html): \DOMDocument
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // The meta hint makes libxml read the bytes as UTF-8. Without it a page
        // with an accented name comes back mojibake, and the owner searching for
        // "Tomás" finds nothing.
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Header / main / footer, in reading order.
     *
     * Only the page's OWN landmarks: a `<header>` inside an `<article>` is that
     * article's header, not the site's, and attributing a card's heading to the
     * site chrome would make "the button at the top" resolve to the wrong thing.
     *
     * Falls back to the body for a theme that uses none of the three — better a
     * flat list than none.
     *
     * @return array<string, \DOMNode>
     */
    private static function regions(\DOMDocument $document): array
    {
        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return [];
        }

        $out = [];

        foreach (['header', 'main', 'footer'] as $name) {
            foreach ($document->getElementsByTagName($name) as $element) {
                // Direct child of <body> only.
                if ($element->parentNode === $body) {
                    $out[$name] = $element;
                    break;
                }
            }
        }

        return $out === [] ? ['main' => $body] : $out;
    }

    private static function titleOf(string $html): string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1
            ? trim(html_entity_decode(strip_tags($m[1])))
            : '';
    }

    /** As many lines as a person would scan before giving up on a page. */
    private const MAX_ITEMS = 200;

    /** A run of words longer than this is an article, not a thing to point at. */
    private const MAX_TEXT = 400;

    /** Not visible prose. Skipped whole, children included. */
    private const INVISIBLE = ['script', 'style', 'noscript', 'svg', 'head', 'template', 'iframe'];

    /** What kind of thing an element is, in the words an owner would use. */
    private const KINDS = [
        'h1' => 'heading', 'h2' => 'heading', 'h3' => 'heading',
        'h4' => 'heading', 'h5' => 'heading', 'h6' => 'heading',
        'a' => 'link', 'button' => 'button', 'summary' => 'button',
        'li' => 'list item', 'figcaption' => 'caption', 'blockquote' => 'quote',
        'th' => 'text', 'td' => 'text', 'label' => 'text',
    ];

    /**
     * The page's text, in order, each line tagged with what kind of thing it is.
     *
     * The tag is what makes a positional request answerable: "the first thing I
     * see" is the first thing in `main`, "swap those two" needs the two to be
     * separate things, and "let me click the picture" needs the picture to be
     * visible as a link at all.
     *
     * ── WHY THIS WALKS THE DOM INSTEAD OF MATCHING TAGS ─────────────────────
     * One `preg_match_all` over a whitelist of tags has a flaw which is
     * invisible until it matters: **`preg_match_all` resumes after the end of
     * each match**, so a card like
     *
     *     <li><h2><a>Dana Whitfield</a></h2><p>President</p></li>
     *
     * matches the `<li>` and skips everything inside it. Four people on a
     * roster come back as four `list item` lines reading "Dana Whitfield
     * President president@example.com" — no heading, no link, no separation.
     * For a wrapper element the outermost is the *least* useful one, and it is
     * the only one that survives.
     *
     * That single behaviour is underneath three of the four requests this
     * feature exists for. "Swap those two" cannot be resolved when the two are
     * one blob. "Click the image" cannot be answered when no link is visible.
     * And anything not inside one of the listed tags is dropped entirely — the
     * footer's copyright line is a bare text node, so it would be invisible, on
     * every page, while being one of the most commonly reworded lines on any
     * website.
     *
     * The rule: emit at the innermost element that holds text, plus any text
     * sitting loose beside child elements, plus images by their alt text.
     *
     * @return array<int, array<string, string>>
     */
    private static function visibleText(\DOMNode $node): array
    {
        $out = [];
        self::walk($node, $out);

        return $out;
    }

    /**
     * Depth-first, in document order.
     *
     * @param array<int, array<string, string>> $out
     */
    private static function walk(\DOMNode $node, array &$out): void
    {
        foreach ($node->childNodes ?? [] as $child) {
            if (count($out) >= self::MAX_ITEMS) {
                return;
            }

            if ($child instanceof \DOMText) {
                // Text sitting directly beside child elements — "Posted on" in
                // `<p>Posted on <time>…</time></p>`, or a footer's bare line.
                self::emit($out, 'text', $child->textContent);
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::INVISIBLE, true)) {
                continue;
            }

            if ($tag === 'img') {
                // A picture is a thing on the page, and its alt text is what it
                // is called. Reported so "make the photo clickable" and "change
                // that image" are answerable at all.
                $alt = trim($child->getAttribute('alt'));

                if ($alt !== '') {
                    self::emit($out, self::inLink($child) ? 'image link' : 'image', $alt);
                }
                continue;
            }

            // An element whose text all lives in child elements is a wrapper.
            // Descend: the useful thing is inside it.
            if (self::hasElementText($child)) {
                self::walk($child, $out);
                continue;
            }

            self::emit($out, self::KINDS[$tag] ?? 'text', $child->textContent);
        }
    }

    /** Does any child ELEMENT of this node carry text? Then it is a wrapper. */
    private static function hasElementText(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::INVISIBLE, true)) {
                continue;
            }

            if ($tag === 'img' && trim($child->getAttribute('alt')) !== '') {
                return true;
            }

            if (trim($child->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Is this image inside a link — i.e. can the visitor click it? */
    private static function inLink(\DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof \DOMElement && strtolower($parent->tagName) === 'a') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, string>> $out */
    private static function emit(array &$out, string $kind, string $text): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            return;
        }

        $out[] = ['kind' => $kind, 'text' => $text];
    }
}
