<?php

declare(strict_types=1);

namespace Cms\Content;

/**
 * A MARKDOWN DOCUMENT INTO AN ENTRY'S BODY. The editor's Import Markdown posts a
 * document to `Controllers\Admin\MarkdownController`, which answers with what
 * this makes, cleaned by `Html\Sanitizer`. Nothing is saved until the entry is.
 *
 * ── WHY NOT `Helpers\Markdown` ──────────────────────────────────────────────
 * That one renders the assistant's chat replies, and its choices are right for
 * them and wrong for a document: it escapes all HTML first (a reply is model
 * output), puts `#` at h3 (a reply sits under the screen's own headings), keeps
 * lists flat, opens every link in a new tab, and knows no quotes, pictures,
 * rules or reference links. A writer's document needs the opposite on each
 * count, and changing that file would change the assistant.
 *
 * ── CLEAN MEANS NOTHING THE WRITER DID NOT WRITE ────────────────────────────
 * A document that has been through rich text and back carries leftovers, such
 * as empty bullet lists in the middle of an article, hidden with CSS. So nothing
 * here makes an element with nothing in it — no empty paragraph, item, list,
 * quote, heading or code block — and no class, id or style. What comes out is
 * the editor's own vocabulary (`Html\Sanitizer::TAGS`), so cleaning it changes
 * nothing. Nothing the writer wrote is dropped: what cannot become HTML stays as
 * text.
 *
 * ── COMMONMARK, AS THE WRITER SAW IT ────────────────────────────────────────
 * Blocks and inlines follow CommonMark, with the usual extensions for tables,
 * strikethrough and bare links: what the writer's own Markdown editor showed
 * them as they wrote, which is the result they expect here. The corners writers
 * do not reach are left out (link definitions over several lines, HTML blocks of
 * types 3–5, footnotes), and what they would have made stays as the writer's
 * text.
 *
 * ── WHAT IT DECIDES FOR THE WRITER ──────────────────────────────────────────
 *   - THE TITLE. A document's one `#` heading, when it comes first, is its
 *     title: returned as `title` (the editor fills an empty title box with it)
 *     and not repeated as the body's first heading — the page prints the title
 *     itself. YAML front matter's `title:` does the same, and the front matter
 *     goes. A document that uses `#` for its sections has every heading moved
 *     down one, so they are h2, as the site's sections are.
 *   - PICTURES ON THE WRITER'S COMPUTER. `![Chart](chart.png)` names a file the
 *     site does not have, and a picture that points nowhere is a broken image
 *     on the page. It is left out and returned in `missing`, so the editor can
 *     say which to add with Add image. A picture at a web address is kept.
 *   - LINE BREAKS. One inside a paragraph is a space, as in Markdown; two spaces
 *     or a backslash at a line's end is a break.
 *   - HTML in the document (an X or YouTube embed code) is kept for
 *     `Html\Sanitizer` to judge, as a paste into the HTML view would be.
 */
final class MarkdownImport
{
    /** Quotes and lists nest no deeper than this; past it, a line is text. */
    private const DEPTH = 24;

    /** Tags that open an HTML block running to the next blank line (CommonMark's sixth kind). */
    private const HTML_BLOCK_TAGS = 'address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|'
        . 'dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe|'
        . 'legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|'
        . 'td|tfoot|th|thead|title|tr|track|ul';

    /** A whole open or closing tag, or a comment, as inline HTML. Possessive, so a long line cannot backtrack. */
    private const INLINE_HTML = '/\A(?:<[A-Za-z][A-Za-z0-9-]*+(?:\s++[A-Za-z_:][\w.:-]*+(?:\s*+=\s*+(?:[^\s"\'=<>`]++|\'[^\']*+\'|"[^"]*+"))?+)*+\s*+\/?>'
        . '|<\/[A-Za-z][A-Za-z0-9-]*+\s*+>|<!--(?:(?!-->).)*+-->)/s';

    /** A table past this many cells is no article's: what follows it is read as words. */
    private const MAX_CELLS = 200000;

    /** The characters a backslash escapes. */
    private const PUNCT = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /** @var array<string, array{0: string, 1: string}> link reference label => [address, title] */
    private array $refs = [];

    /** @var list<array{src: string, alt: string}> */
    private array $missing = [];

    /**
     * @return array{html: string, title: string, missing: list<array{src: string, alt: string}>}
     */
    public static function convert(string $markdown): array
    {
        return (new self())->run($markdown);
    }

    private function run(string $markdown): array
    {
        [$text, $title] = self::frontMatter(self::normalise($markdown));
        $blocks = $this->blocks(explode("\n", $text), 0);

        // THE TITLE, AND THE LEVELS. One `#` heading, first, is the title; any
        // other use of `#` is a section, and every heading moves down one.
        $h1 = array_keys(array_filter($blocks, static fn (array $b): bool => $b['type'] === 'heading' && $b['level'] === 1));
        if ($h1 === [0]) {
            $first = self::plain($this->inline($blocks[0]['text']));
            $title = $title !== '' ? $title : $first;
            array_shift($blocks);
        } elseif ($h1 !== []) {
            $blocks = self::demote($blocks);
        }

        $html = $this->render($blocks);
        $seen = [];
        $missing = array_values(array_filter($this->missing, static function (array $m) use (&$seen): bool {
            $key = $m['src'] . "\0" . $m['alt'];
            return !isset($seen[$key]) && ($seen[$key] = true);
        }));

        return ['html' => $html, 'title' => $title, 'missing' => $missing];
    }

    // ── THE TEXT ────────────────────────────────────────────────────────────

    /**
     * One kind of line ending, no byte-order mark, tabs at four-column stops
     * (how CommonMark measures indentation). A file that is not UTF-8 is read
     * as Windows-1252, which is what a document saved by an older Windows
     * editor is, so its curly quotes come through as curly quotes.
     */
    private static function normalise(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", "\u{FFFD}"], $text);

        return (string) preg_replace_callback('/^[^\t\n]*\t[^\n]*$/m', static function (array $m): string {
            $out = '';
            foreach (preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $out .= $ch === "\t" ? str_repeat(' ', 4 - (mb_strlen($out) % 4)) : $ch;
            }
            return $out;
        }, $text);
    }

    /**
     * YAML front matter (`---`, `key: value` lines, `---`) is a document's
     * settings, not its words: it goes, and its `title:` is kept as the title.
     * Anything that does not read as settings is left for the body.
     *
     * @return array{0: string, 1: string} the text without it, and its title
     */
    private static function frontMatter(string $text): array
    {
        if (preg_match('/\A---[ \t]*\n(.*?)\n(?:---|\.\.\.)[ \t]*(?:\n|\z)/s', $text, $m) !== 1) {
            return [$text, ''];
        }
        $keyed = false;
        foreach (explode("\n", $m[1]) as $line) {
            if (trim($line) === '' || preg_match('/^(\s+\S|\s*#|\s*- )/', $line) === 1) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_][\w .-]*:(\s|$)/', $line) !== 1) {
                return [$text, ''];
            }
            $keyed = true;
        }
        if (!$keyed) {
            return [$text, ''];
        }
        $title = preg_match('/^title:[ \t]*(.+?)[ \t]*$/mi', $m[1], $t) === 1 ? $t[1] : '';
        if (preg_match('/^(["\'])(.*)\1$/', $title, $q) === 1) {
            $title = $q[2];
        }

        return [substr($text, strlen($m[0])), trim($title)];
    }

    // ── BLOCKS ──────────────────────────────────────────────────────────────

    /**
     * The blocks these lines make, each an array with a `type`.
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private function blocks(array $lines, int $depth): array
    {
        $out = [];
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // A fenced code block: to the closing fence, or to the end.
            if (preg_match('/^( {0,3})(`{3,}|~{3,})(.*)$/', $line, $m) === 1 && !($m[2][0] === '`' && str_contains($m[3], '`'))) {
                $close = '/^ {0,3}' . preg_quote($m[2][0], '/') . '{' . strlen($m[2]) . ',}[ \t]*$/';
                $code = [];
                for ($i++; $i < $n && preg_match($close, $lines[$i]) !== 1; $i++) {
                    $code[] = (string) preg_replace('/^ {0,' . strlen($m[1]) . '}/', '', $lines[$i]);
                }
                $i++;
                $out[] = ['type' => 'code', 'text' => implode("\n", $code)];
                continue;
            }

            if (preg_match('/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?[ \t]*$/', $line, $m) === 1) {
                $text = (string) preg_replace('/(?:^|[ \t]+)#+[ \t]*$/', '', $m[2] ?? '');
                $out[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => trim($text)];
                $i++;
                continue;
            }

            // A rule, before lists: `* * *` and `- - -` are rules, not items.
            if (self::isRule($line)) {
                $out[] = ['type' => 'hr'];
                $i++;
                continue;
            }

            if ($depth < self::DEPTH && preg_match('/^ {0,3}>/', $line) === 1) {
                [$quote, $i] = $this->quote($lines, $i, $depth);
                $out[] = $quote;
                continue;
            }

            if ($depth < self::DEPTH && self::marker($line) !== null) {
                [$list, $i] = $this->listBlock($lines, $i, $depth);
                $out[] = $list;
                continue;
            }

            if (preg_match('/^ {4}/', $line) === 1) {
                $code = [];
                for (; $i < $n && (preg_match('/^ {4}/', $lines[$i]) === 1 || trim($lines[$i]) === ''); $i++) {
                    $code[] = (string) substr($lines[$i], 4);
                }
                while ($code !== [] && trim((string) end($code)) === '') {
                    array_pop($code);
                }
                $out[] = ['type' => 'code', 'text' => implode("\n", $code)];
                continue;
            }

            if (($end = self::htmlBlockEnd($line)) !== null) {
                $html = [];
                while ($i < $n) {
                    $html[] = $lines[$i];
                    $done = $end !== 'blank' && preg_match($end, $lines[$i]) === 1;
                    $i++;
                    if ($done || ($end === 'blank' && $i < $n && trim($lines[$i]) === '')) {
                        break;
                    }
                }
                $out[] = ['type' => 'html', 'html' => implode("\n", $html)];
                continue;
            }

            if (isset($lines[$i + 1]) && self::tableStart($line, $lines[$i + 1])) {
                [$table, $i] = $this->table($lines, $i);
                $out[] = $table;
                continue;
            }

            // A link definition (`[name]: address "title"`) is used, never shown.
            if ($this->definition($line)) {
                $i++;
                continue;
            }

            // A paragraph: to a blank line, or a line that starts something
            // else. An `===` or `---` line under it makes it a heading.
            $para = [];
            for (; $i < $n && trim($lines[$i]) !== ''; $i++) {
                $l = $lines[$i];
                if ($para !== []) {
                    if (preg_match('/^ {0,3}(=+|-+)[ \t]*$/', $l, $m) === 1) {
                        $out[] = ['type' => 'heading', 'level' => $m[1][0] === '=' ? 1 : 2, 'text' => trim(implode("\n", $para))];
                        $para = [];
                        $i++;
                        continue 2;
                    }
                    if (self::interrupts($l) || (isset($lines[$i + 1]) && self::tableStart($l, $lines[$i + 1]))) {
                        break;
                    }
                }
                $para[] = $l;
            }
            if ($para !== []) {
                $out[] = ['type' => 'paragraph', 'text' => trim(implode("\n", $para))];
            }
        }

        return $out;
    }

    /**
     * A block quote: its `>` lines, and the lines that carry on its last
     * paragraph without a `>` (a "lazy" line, which writers leave all the time).
     *
     * @param list<string> $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function quote(array $lines, int $i, int $depth): array
    {
        $inner = [];
        $lazy = false;
        for ($n = count($lines); $i < $n; $i++) {
            if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $m) === 1) {
                $inner[] = $m[1];
                $lazy = trim($m[1]) !== '' && !self::interrupts($m[1]) && preg_match('/^ {4}/', $m[1]) !== 1;
                continue;
            }
            if (!$lazy || trim($lines[$i]) === '' || self::interrupts($lines[$i])) {
                break;
            }
            $inner[] = $lines[$i];
        }

        return [['type' => 'quote', 'children' => $this->blocks($inner, $depth + 1)], $i];
    }

    /**
     * A list: its items, each the lines indented under its marker (and lazy
     * lines carrying on its paragraph), read again as blocks — which is what
     * nests a list in a list. A different bullet, or `.` after `)`, starts a
     * new list, as CommonMark has it.
     *
     * @param list<string> $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function listBlock(array $lines, int $i, int $depth): array
    {
        $n = count($lines);
        $first = self::marker($lines[$i]);
        $items = [];
        for (;;) {
            // Blank lines between items do not end the list; anything else
            // that is not one of its items does.
            for ($j = $i; $j < $n && trim($lines[$j]) === ''; $j++) {
            }
            $m = $j < $n ? self::marker($lines[$j]) : null;
            if ($m === null || self::isRule($lines[$j]) || $m['ordered'] !== $first['ordered'] || $m['char'] !== $first['char']) {
                break;
            }
            $i = $j;
            $item = [$m['content']];
            $blank = false;
            for ($i++; $i < $n; $i++) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    // An item that starts with nothing ends at a blank line.
                    if (count($item) === 1 && trim($item[0]) === '') {
                        break;
                    }
                    $item[] = '';
                    $blank = true;
                    continue;
                }
                if (strlen($l) - strlen(ltrim($l, ' ')) >= $m['indent']) {
                    $item[] = (string) substr($l, $m['indent']);
                    $blank = false;
                    continue;
                }
                $last = (string) end($item);
                if ($blank || trim($last) === '' || self::interrupts($l) || self::interrupts($last) || self::marker($l) !== null) {
                    break;
                }
                $item[] = ltrim($l, ' ');
            }
            while (count($item) > 1 && trim((string) end($item)) === '') {
                array_pop($item);
            }
            $items[] = $this->blocks($item, $depth + 1);
        }

        return [['type' => 'list', 'ordered' => $first['ordered'], 'start' => $first['start'], 'items' => $items], $i];
    }

    /**
     * A table: a header row, the `---|---` row under it, then rows to a blank
     * line. Each row has the header's number of cells.
     *
     * @param list<string> $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function table(array $lines, int $i): array
    {
        $head = self::cells($lines[$i]);
        $rows = [];
        // EACH ROW HAS AS MANY CELLS AS THE HEADER, so a header a few thousand
        // cells wide over a long document multiplies into more than memory holds.
        for ($i += 2, $n = count($lines); $i < $n && trim($lines[$i]) !== '' && !self::interrupts($lines[$i])
            && (count($rows) + 1) * count($head) <= self::MAX_CELLS; $i++) {
            $rows[] = array_pad(array_slice(self::cells($lines[$i]), 0, count($head)), count($head), '');
        }

        return [['type' => 'table', 'head' => $head, 'rows' => $rows], $i];
    }

    /** Does a table start here: a row with a `|`, then a `---|---` row with as many cells? */
    private static function tableStart(string $line, string $next): bool
    {
        return str_contains($line, '|') && str_contains($next, '|')
            && preg_match('/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/', $next) === 1
            && count(self::cells($line)) === count(self::cells($next));
    }

    /** @return list<string> a table row's cells, an escaped `\|` kept as a `|` */
    private static function cells(string $row): array
    {
        $row = trim($row);
        if (str_starts_with($row, '|')) {
            $row = substr($row, 1);
        }
        if (str_ends_with($row, '|') && !str_ends_with($row, '\\|')) {
            $row = substr($row, 0, -1);
        }

        return array_map(static fn (string $c): string => trim(str_replace('\\|', '|', $c)), preg_split('/(?<!\\\\)\|/', $row) ?: ['']);
    }

    /**
     * A list item's marker: `-`, `+`, `*`, or a number and `.` or `)`, then a
     * space (or nothing). `indent` is where the item's own lines start.
     *
     * @return array{ordered: bool, char: string, start: int, content: string, indent: int}|null
     */
    private static function marker(string $line): ?array
    {
        if (preg_match('/^( {0,3})([-+*]|\d{1,9}[.)])( +|$)(.*)$/', $line, $m) !== 1) {
            return null;
        }
        $spaces = strlen($m[3]);
        $content = $m[4];
        if ($spaces === 0) {
            $spaces = 1;
        } elseif ($spaces > 4) {
            // Five spaces or more: the item starts with indented code, one space in.
            $content = str_repeat(' ', $spaces - 1) . $content;
            $spaces = 1;
        }
        $ordered = !in_array($m[2], ['-', '+', '*'], true);

        return [
            'ordered' => $ordered,
            'char'    => $ordered ? substr($m[2], -1) : $m[2],
            'start'   => $ordered ? (int) $m[2] : 1,
            'content' => $content,
            'indent'  => strlen($m[1]) + strlen($m[2]) + $spaces,
        ];
    }

    private static function isRule(string $line): bool
    {
        return preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line) === 1;
    }

    /**
     * May this line cut a paragraph short? A heading, a quote, a fence, a rule,
     * an HTML block, or a list item with words in it (a numbered one only from
     * 1, so "2024. A year…" carries on the sentence before it).
     */
    private static function interrupts(string $line): bool
    {
        if (preg_match('/^ {0,3}(?:#{1,6}(?:[ \t]|$)|>|`{3,}(?!.*`)|~{3,})/', $line) === 1 || self::isRule($line)
            || self::htmlBlockEnd($line, true) !== null) {
            return true;
        }
        $m = self::marker($line);

        return $m !== null && trim($m['content']) !== '' && (!$m['ordered'] || $m['start'] === 1);
    }

    /**
     * Does an HTML block start here, and where does it end? A regex for the
     * line that ends it, 'blank' for the next blank line, or null for no block.
     * A tag alone on a line (CommonMark's seventh kind) cannot cut a paragraph.
     */
    private static function htmlBlockEnd(string $line, bool $interrupting = false): ?string
    {
        $line = substr($line, 0, 4000);
        if (preg_match('/^ {0,3}<(script|pre|style|textarea)(?:\s|>|$)/i', $line, $m) === 1) {
            return '/<\/' . strtolower($m[1]) . '>/i';
        }
        if (str_starts_with(ltrim($line, ' '), '<!--') && strlen($line) - strlen(ltrim($line, ' ')) < 4) {
            return '/-->/';
        }
        if (preg_match('/^ {0,3}<\/?(?:' . self::HTML_BLOCK_TAGS . ')(?:[\s\/>]|$)/i', $line) === 1) {
            return 'blank';
        }
        if (!$interrupting && preg_match('/^ {0,3}(?:<[A-Za-z][A-Za-z0-9-]*+(?:\s++[A-Za-z_:][\w.:-]*+(?:\s*+=\s*+(?:[^\s"\'=<>`]++|\'[^\']*+\'|"[^"]*+"))?+)*+\s*+\/?>'
            . '|<\/[A-Za-z][A-Za-z0-9-]*+\s*+>)[ \t]*$/', $line) === 1) {
            return 'blank';
        }

        return null;
    }

    /** Is this line a link definition? If so, it is remembered (the first of a name wins). */
    private function definition(string $line): bool
    {
        if (preg_match('/^ {0,3}\[((?:[^\\\\\[\]]|\\\\.){1,999})\]:[ \t]*(?:<([^<>\n]*)>|(\S+))'
            . '(?:[ \t]+("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^()\\\\]|\\\\.)*\)))?[ \t]*$/', $line, $m) !== 1) {
            return false;
        }
        $label = self::label($m[1]);
        if ($label === '') {
            return false;
        }
        $this->refs[$label] ??= [self::unescape($m[2] !== '' ? $m[2] : ($m[3] ?? '')), isset($m[4]) && $m[4] !== '' ? self::unescape(substr($m[4], 1, -1)) : ''];

        return true;
    }

    /** @param list<array<string, mixed>> $blocks */
    private static function demote(array $blocks): array
    {
        foreach ($blocks as &$b) {
            if ($b['type'] === 'heading') {
                $b['level'] = min(6, $b['level'] + 1);
            } elseif ($b['type'] === 'quote') {
                $b['children'] = self::demote($b['children']);
            } elseif ($b['type'] === 'list') {
                $b['items'] = array_map(static fn (array $item): array => self::demote($item), $b['items']);
            }
        }
        unset($b);

        return $blocks;
    }

    // ── HTML ────────────────────────────────────────────────────────────────

    /**
     * The blocks as HTML: a line each at the top, for an HTML view that reads,
     * and nothing between them inside a quote or an item, where a line break
     * would be stored as text in the body.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function render(array $blocks, string $between = "\n"): string
    {
        $out = [];
        foreach ($blocks as $b) {
            $html = match ($b['type']) {
                'paragraph' => self::wrap('p', $this->inline($b['text'])),
                'heading'   => self::wrap('h' . max(2, $b['level']), $this->inline($b['text'])),
                'code'      => trim($b['text']) === '' ? '' : '<pre><code>' . self::esc($b['text']) . '</code></pre>',
                'hr'        => '<hr>',
                'quote'     => self::wrap('blockquote', $this->render($b['children'], '')),
                'list'      => $this->listHtml($b),
                'table'     => $this->tableHtml($b),
                'html'      => self::isEmpty($b['html']) ? '' : trim($b['html']),
            };
            if ($html !== '') {
                $out[] = $html;
            }
        }

        return implode($between, $out);
    }

    /**
     * A list. An item that is one paragraph (with lists under it) is that
     * paragraph's words, with no `<p>` round them — the clean form, whether or
     * not the writer left blank lines between items. An item with more in it
     * keeps its paragraphs. An empty item goes, and a list of none goes too.
     */
    private function listHtml(array $list): string
    {
        $items = [];
        foreach ($list['items'] as $children) {
            $tail = array_slice($children, 1);
            if ($children !== [] && $children[0]['type'] === 'paragraph'
                && array_filter($tail, static fn (array $c): bool => $c['type'] !== 'list') === []) {
                $html = $this->inline($children[0]['text']) . $this->render($tail, '');
            } else {
                $html = $this->render($children, '');
            }
            if (!self::isEmpty($html)) {
                $items[] = '<li>' . $html . '</li>';
            }
        }
        if ($items === []) {
            return '';
        }
        $tag = $list['ordered'] ? 'ol' : 'ul';
        $start = $list['ordered'] && $list['start'] !== 1 ? ' start="' . $list['start'] . '"' : '';

        return "<$tag$start>" . implode('', $items) . "</$tag>";
    }

    private function tableHtml(array $table): string
    {
        $row = fn (array $cells, string $tag): string => '<tr>'
            . implode('', array_map(fn (string $c): string => "<$tag>" . $this->inline($c) . "</$tag>", $cells)) . '</tr>';
        $body = array_map(static fn (array $r): string => $row($r, 'td'), $table['rows']);
        $html = '<table><thead>' . $row($table['head'], 'th') . '</thead>'
            . ($body !== [] ? '<tbody>' . implode('', $body) . '</tbody>' : '') . '</table>';

        return self::isEmpty($html) ? '' : $html;
    }

    private static function wrap(string $tag, string $inner): string
    {
        return self::isEmpty($inner) ? '' : "<$tag>$inner</$tag>";
    }

    /** No words, no picture, no frame, no rule: nothing a reader would see. */
    private static function isEmpty(string $html): bool
    {
        if (preg_match('/<(?:img|iframe|hr)\b/i', $html) === 1) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text) === '';
    }

    // ── INLINES ─────────────────────────────────────────────────────────────

    /**
     * A block's text as inline HTML: code, links, pictures, emphasis, breaks.
     * `$links` is false inside a link's own words, where a link cannot nest, and
     * `$pictures` inside a picture's description, which is one line of words. A
     * picture described by a picture described by a picture read the whole text
     * again at each level, until memory ran out.
     */
    private function inline(string $text, bool $links = true, bool $pictures = true): string
    {
        $tokens = $this->tokens($text, $links, $pictures);
        self::emphasis($tokens);

        $out = '';
        foreach ($tokens as $t) {
            $out .= match ($t['t']) {
                'text'  => self::esc($t['v']),
                'html'  => $t['v'],
                'delim' => implode('', $t['close']) . self::esc(str_repeat($t['c'], $t['n'])) . implode('', $t['open']),
            };
        }

        return trim($out);
    }

    /**
     * The text read left to right into tokens: plain text, finished HTML, and
     * runs of `*`, `_` or `~` that `emphasis()` pairs afterwards.
     *
     * @return list<array<string, mixed>>
     */
    private function tokens(string $s, bool $links, bool $pictures = true): array
    {
        $tokens = [];
        $buf = '';
        $flush = static function () use (&$tokens, &$buf): void {
            if ($buf !== '') {
                $tokens[] = ['t' => 'text', 'v' => $buf];
                $buf = '';
            }
        };
        $html = static function (string $v) use (&$tokens, $flush): void {
            $flush();
            $tokens[] = ['t' => 'html', 'v' => $v];
        };

        $failed = [];
        $pairs = self::brackets($s);
        $len = strlen($s);
        $p = 0;
        while ($p < $len) {
            $c = $s[$p];

            if ($c === '\\') {
                $next = $s[$p + 1] ?? '';
                if ($next === "\n") {
                    $html('<br>');
                    $p += 2 + strspn($s, ' ', $p + 2);
                } elseif ($next !== '' && str_contains(self::PUNCT, $next)) {
                    $buf .= $next;
                    $p += 2;
                } else {
                    $buf .= '\\';
                    $p++;
                }
                continue;
            }

            if ($c === "\n") {
                // Two spaces before the end of a line are a break; one line
                // ending is a space.
                $hard = substr($buf, -2) === '  ';
                $buf = rtrim($buf, ' ');
                if ($hard) {
                    $html('<br>');
                } else {
                    $buf .= ' ';
                }
                // HANDED ON NOW AND THEN, so that trimming a line's end copies a
                // little and not the whole paragraph so far: a 2 MB paragraph
                // whose every line ends in a space took half a minute. The line
                // just ended, so nothing that looks back at `$buf` can tell.
                if (strlen($buf) > 4096) {
                    $flush();
                }
                $p += 1 + strspn($s, ' ', $p + 1);
                continue;
            }

            if ($c === '`') {
                $run = strspn($s, '`', $p);
                $close = self::backticks($s, $p + $run, $run, $failed);
                if ($close === null) {
                    $buf .= str_repeat('`', $run);
                    $p += $run;
                    continue;
                }
                $code = str_replace("\n", ' ', substr($s, $p + $run, $close - $p - $run));
                if (strlen($code) > 2 && $code[0] === ' ' && $code[-1] === ' ' && trim($code, ' ') !== '') {
                    $code = substr($code, 1, -1);
                }
                $html('<code>' . self::esc($code) . '</code>');
                $p = $close + $run;
                continue;
            }

            // ── MATCHED IN A WINDOW, NOT THE REST OF THE TEXT ──────────────────
            // PCRE looks for a pattern's last required character (`>`, `;`, `)`)
            // through the whole subject before it tries to match, so testing at
            // every `<` of a long text with none in it was quadratic: a 480 KB
            // line took a second, and twice that took four. No tag, entity or
            // link address a writer types is longer than the window.
            if ($c === '<') {
                $w = substr($s, $p, 4000);
                if (preg_match('/\A<([A-Za-z][A-Za-z0-9+.\-]{1,31}:[^\s<>]*)>/', $w, $m) === 1) {
                    $html('<a href="' . self::attr($m[1]) . '">' . self::esc($m[1]) . '</a>');
                } elseif (preg_match('/\A<([A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]++@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*+)>/', $w, $m) === 1) {
                    $html('<a href="mailto:' . self::attr($m[1]) . '">' . self::esc($m[1]) . '</a>');
                } elseif (preg_match(self::INLINE_HTML, $w, $m) === 1) {
                    $html($m[0]);
                } else {
                    $buf .= '<';
                    $p++;
                    continue;
                }
                $p += strlen($m[0]);
                continue;
            }

            if ($c === '!' && $pictures && ($s[$p + 1] ?? '') === '[' && ($link = $this->link($s, $p + 1, $pairs)) !== null) {
                $html($this->image($link));
                $p = $link['end'];
                continue;
            }

            if ($c === '[' && $links && ($link = $this->link($s, $p, $pairs)) !== null) {
                $words = $this->inline($link['text'], false);
                if (!self::isEmpty($words)) {
                    $html('<a href="' . self::attr($link['href']) . '"'
                        . ($link['title'] !== '' ? ' title="' . self::attr($link['title']) . '"' : '') . '>' . $words . '</a>');
                }
                $p = $link['end'];
                continue;
            }

            // A bare address, linked: at the start of a word, and without the
            // punctuation that ends the sentence. The one character before it
            // is read directly: a pattern anchored at the end still walks the
            // text from its start, at every h and w in it.
            if ($links && ($c === 'h' || $c === 'H' || $c === 'w' || $c === 'W')
                && ($buf === '' || str_contains(" \t\n\r\f\v(*_~\"'", $buf[-1]))
                && preg_match('/\A(?:https?:\/\/[^\s<]+|www\.[^\s<]+)/i', substr($s, $p, 4000), $m) === 1
                && ($url = self::trimUrl($m[0])) !== '' && preg_match('/^(?:https?:\/\/[^\/\s.]|www\.[^\/\s]*\.)/i', $url) === 1) {
                $html('<a href="' . self::attr(stripos($url, 'www.') === 0 ? 'https://' . $url : $url) . '">' . self::esc($url) . '</a>');
                $p += strlen($url);
                continue;
            }

            // An entity is the character it names (`&copy;` is ©), as CommonMark
            // reads it — and as the sanitizer would store it anyway.
            if ($c === '&' && preg_match('/\A&(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/', substr($s, $p, 40), $m) === 1
                && ($ch = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== $m[0]) {
                $buf .= $ch;
                $p += strlen($m[0]);
                continue;
            }

            if ($c === '*' || $c === '_' || $c === '~') {
                $run = strspn($s, $c, $p);
                if ($c === '~' && $run > 2) {
                    $buf .= str_repeat('~', $run);
                    $p += $run;
                    continue;
                }
                $before = self::charBefore($s, $p);
                $after = self::charAt($s, $p + $run);
                $bs = $before === '' || self::isSpace($before);
                $as = $after === '' || self::isSpace($after);
                $bp = !$bs && self::isPunct($before);
                $ap = !$as && self::isPunct($after);
                $left = !$as && (!$ap || $bs || $bp);
                $right = !$bs && (!$bp || $as || $ap);
                $flush();
                $tokens[] = [
                    't' => 'delim', 'c' => $c, 'n' => $run, 'orig' => $run, 'open' => [], 'close' => [],
                    'canOpen'  => $c === '_' ? $left && (!$right || $bp) : $left,
                    'canClose' => $c === '_' ? $right && (!$left || $ap) : $right,
                ];
                $p += $run;
                continue;
            }

            $buf .= $c;
            $p++;
        }
        $flush();

        return $tokens;
    }

    /**
     * Pair the runs of `*`, `_` and `~` into emphasis, strong emphasis and
     * strikethrough — CommonMark's own procedure, so `***both***`, `*a **b**
     * c*` and `snake_case_words` come out as the writer's editor showed them.
     * A run left unpaired is its characters.
     *
     * A STACK of the runs that may still open, and a floor for each kind of
     * closer below which nothing can match it: CommonMark's two devices for
     * keeping this linear. Scanning back over every earlier run instead took
     * `*a*a*a…` from 39 ms at 2,000 characters to minutes at 100,000.
     *
     * @param list<array<string, mixed>> $tokens
     */
    private static function emphasis(array &$tokens): void
    {
        $stack = [];
        $floor = [];
        foreach (array_keys($tokens) as $c) {
            if ($tokens[$c]['t'] !== 'delim') {
                continue;
            }
            $closer = $tokens[$c];
            if ($closer['canClose']) {
                $kind = $closer['c'] . (int) $closer['canOpen'] . ($closer['orig'] % 3);
                while ($tokens[$c]['n'] > 0) {
                    $found = null;
                    for ($k = count($stack) - 1, $low = min($floor[$kind] ?? 0, count($stack)); $k >= $low; $k--) {
                        $op = $tokens[$stack[$k]];
                        if ($op['c'] !== $closer['c'] || $op['n'] === 0
                            || ($closer['c'] === '~' ? $op['n'] !== $tokens[$c]['n']
                                : (($op['canClose'] || $closer['canOpen']) && ($op['orig'] + $closer['orig']) % 3 === 0
                                   && !($op['orig'] % 3 === 0 && $closer['orig'] % 3 === 0)))) {
                            continue;
                        }
                        $found = $k;
                        break;
                    }
                    if ($found === null) {
                        $floor[$kind] = count($stack);
                        break;
                    }
                    $o = $stack[$found];
                    $use = $closer['c'] === '~' ? $tokens[$c]['n'] : ($tokens[$o]['n'] >= 2 && $tokens[$c]['n'] >= 2 ? 2 : 1);
                    $tag = $closer['c'] === '~' ? 's' : ($use === 2 ? 'strong' : 'em');
                    $tokens[$o]['n'] -= $use;
                    $tokens[$c]['n'] -= $use;
                    array_unshift($tokens[$o]['open'], "<$tag>");
                    $tokens[$c]['close'][] = "</$tag>";
                    // The runs between the two can no longer open anything.
                    array_splice($stack, $tokens[$o]['n'] === 0 ? $found : $found + 1);
                }
            }
            if ($closer['canOpen'] && $tokens[$c]['n'] > 0) {
                $stack[] = $c;
            }
        }
    }

    /**
     * A link at `[`: its words, address and title, and where it ends. Inline
     * (`[words](address "title")`), or by a definition: `[words][name]`,
     * `[name][]`, `[name]`. A name nobody defined is not a link, and its
     * brackets stay as the writer typed them.
     *
     * @param array<int, int> $pairs `brackets()` of `$s`
     * @return array{text: string, href: string, title: string, end: int}|null
     */
    private function link(string $s, int $p, array $pairs): ?array
    {
        $close = $pairs[$p] ?? null;
        if ($close === null) {
            return null;
        }
        $text = substr($s, $p + 1, $close - $p - 1);
        $q = $close + 1;
        if (($s[$q] ?? '') === '(' && preg_match('/\A\(\s*+(?:<([^<>\n]*)>|((?:[^\s()\\\\]++|\\\\.|\((?:[^\s()\\\\]++|\\\\.)*+\))*+))'
            . '(?:\s++("(?:[^"\\\\]|\\\\.)*+"|\'(?:[^\'\\\\]|\\\\.)*+\'|\((?:[^()\\\\]|\\\\.)*+\)))?\s*+\)/', substr($s, $q, 4000), $m) === 1) {
            return [
                'text'  => $text,
                'href'  => str_replace(' ', '%20', self::unescape($m[1] !== '' ? $m[1] : ($m[2] ?? ''))),
                'title' => isset($m[3]) && $m[3] !== '' ? self::unescape(substr($m[3], 1, -1)) : '',
                'end'   => $q + strlen($m[0]),
            ];
        }
        if (($s[$q] ?? '') === '[' && ($end = strpos($s, ']', $q)) !== false) {
            $name = substr($s, $q + 1, $end - $q - 1);
            $ref = $this->refs[self::label($name !== '' ? $name : $text)] ?? null;

            return $ref === null ? null : ['text' => $text, 'href' => $ref[0], 'title' => $ref[1], 'end' => $end + 1];
        }
        $ref = $this->refs[self::label($text)] ?? null;

        return $ref === null ? null : ['text' => $text, 'href' => $ref[0], 'title' => $ref[1], 'end' => $close + 1];
    }

    /**
     * Each `[` and the `]` that closes it, past escapes and code, found in one
     * pass with a stack. Scanning forward from every `[` instead was quadratic:
     * a line of 50,000 `[` did not finish.
     *
     * @return array<int, int>
     */
    private static function brackets(string $s): array
    {
        $pairs = [];
        $open = [];
        $failed = [];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '`') {
                $run = strspn($s, '`', $i);
                $end = self::backticks($s, $i + $run, $run, $failed);
                $i = ($end === null ? $i + $run : $end + $run) - 1;
            } elseif ($c === '[') {
                $open[] = $i;
            } elseif ($c === ']' && $open !== []) {
                $pairs[array_pop($open)] = $i;
            }
        }

        return $pairs;
    }

    /**
     * Where the next run of exactly `$run` backticks starts, from `$from`; or
     * null. A length that found no partner from a point never will further on,
     * so `$failed` remembers it, and a text of lone backticks stays linear.
     *
     * @param array<int, int> $failed
     */
    private static function backticks(string $s, int $from, int $run, array &$failed): ?int
    {
        if (isset($failed[$run]) && $from >= $failed[$run]) {
            return null;
        }
        for ($at = $from; ($q = strpos($s, '`', $at)) !== false; $at = $q + $r) {
            $r = strspn($s, '`', $q);
            if ($r === $run) {
                return $q;
            }
        }
        $failed[$run] = min($failed[$run] ?? PHP_INT_MAX, $from);

        return null;
    }

    /**
     * A picture. At an address (https, or on this site: `/images/…`) it is
     * kept; a file on the writer's computer is left out and listed in
     * `missing`, with its description, for the editor to say so.
     */
    private function image(array $link): string
    {
        $alt = self::plain($this->inline($link['text'], false, false));
        $src = trim($link['href']);
        if (preg_match('#^(?:https?:)?//[^/\s]#i', $src) !== 1 && preg_match('#^/(?!/)#', $src) !== 1) {
            $this->missing[] = ['src' => $src, 'alt' => $alt];
            return '';
        }

        return '<img src="' . self::attr($src) . '" alt="' . self::attr($alt) . '"'
            . ($link['title'] !== '' ? ' title="' . self::attr($link['title']) . '"' : '') . '>';
    }

    /** A bare address without what ends the sentence round it: `.`, `,`, `)`, a quote… */
    private static function trimUrl(string $url): string
    {
        while ($url !== '') {
            $last = $url[-1];
            if (str_contains('?!.,:*_~\'"', $last)) {
                $url = substr($url, 0, -1);
            } elseif ($last === ')' && substr_count($url, '(') < substr_count($url, ')')) {
                $url = substr($url, 0, -1);
            } elseif ($last === ';' && preg_match('/&[A-Za-z0-9]+;$/', $url, $m) === 1) {
                $url = substr($url, 0, -strlen($m[0]));
            } else {
                break;
            }
        }

        return $url;
    }

    // ── SMALL THINGS ────────────────────────────────────────────────────────

    private static function charBefore(string $s, int $p): string
    {
        if ($p <= 0) {
            return '';
        }
        $q = $p - 1;
        while ($q > 0 && (ord($s[$q]) & 0xC0) === 0x80) {
            $q--;
        }

        return substr($s, $q, $p - $q);
    }

    private static function charAt(string $s, int $p): string
    {
        if ($p >= strlen($s)) {
            return '';
        }
        $b = ord($s[$p]);

        return substr($s, $p, $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4)));
    }

    private static function isSpace(string $ch): bool
    {
        return preg_match('/^[\s\p{Zs}]$/u', $ch) === 1;
    }

    private static function isPunct(string $ch): bool
    {
        return preg_match('/^[\p{P}\p{S}]$/u', $ch) === 1;
    }

    /** A link name as it is matched: case, and runs of space, do not count. */
    private static function label(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
    }

    private static function unescape(string $s): string
    {
        return (string) preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $s);
    }

    /** HTML as one line of plain text: a heading's words, a picture's description. */
    private static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
