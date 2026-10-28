<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * A deliberately small Markdown renderer for assistant replies.
 *
 * Models write Markdown whether or not you ask them to — bold, bullet lists and
 * tables show up constantly. Rendering the raw text left literal `**asterisks**`
 * on screen; rendering it with a full Markdown library would mean a dependency
 * and a much larger surface for a string that originates from a model.
 *
 * So: a fixed subset, applied to already-escaped text.
 *
 * **The escaping order is the security property.** Input is passed through
 * htmlspecialchars() FIRST, so any HTML in the model's output becomes inert text.
 * Only then are the handful of Markdown patterns turned into tags. Nothing here
 * can emit a tag that did not come from this file, which is what makes it safe to
 * run over model output — and over anything a model read out of the CMS, since
 * page content is untrusted input too.
 *
 * Supports: headings, bold, italic, inline code, fenced code, links, bullet and
 * numbered lists, tables, and paragraphs. Everything else renders as plain text.
 */
final class Markdown
{
    public static function render(string $text): string
    {
        $text = str_replace("\r\n", "\n", trim($text));
        if ($text === '') {
            return '';
        }

        // Escape everything up front — see the class docblock.
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Fenced code blocks are extracted before anything else touches them, so
        // their contents are never treated as Markdown.
        $blocks = [];
        $text = preg_replace_callback(
            '/```[a-z]*\n(.*?)```/s',
            static function (array $m) use (&$blocks): string {
                $blocks[] = '<pre class="c-md__pre"><code>' . rtrim($m[1]) . '</code></pre>';
                return "\0BLOCK" . (count($blocks) - 1) . "\0";
            },
            $text
        ) ?? $text;

        $out   = [];
        $lines = explode("\n", $text);
        $i     = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Extracted code block placeholder.
            if (preg_match('/^\0BLOCK(\d+)\0$/', trim($line), $m)) {
                $out[] = $blocks[(int) $m[1]];
                $i++;
                continue;
            }

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Heading
            if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
                $level = min(6, strlen($m[1]) + 2); // # -> h3, so it sits under the page h1/h2
                $out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                $i++;
                continue;
            }

            // Table: a header row followed by a |---|---| separator.
            if (str_contains($line, '|') && isset($lines[$i + 1])
                && preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1])) {
                [$html, $i] = self::table($lines, $i);
                $out[] = $html;
                continue;
            }

            // Bullet list
            if (preg_match('/^\s*[-*]\s+/', $line)) {
                $items = [];
                while ($i < $count && preg_match('/^\s*[-*]\s+(.*)$/', $lines[$i], $m)) {
                    $items[] = '<li>' . self::inline($m[1]) . '</li>';
                    $i++;
                }
                $out[] = '<ul class="c-md__list">' . implode('', $items) . '</ul>';
                continue;
            }

            // Numbered list
            if (preg_match('/^\s*\d+[.)]\s+/', $line)) {
                $items = [];
                while ($i < $count && preg_match('/^\s*\d+[.)]\s+(.*)$/', $lines[$i], $m)) {
                    $items[] = '<li>' . self::inline($m[1]) . '</li>';
                    $i++;
                }
                $out[] = '<ol class="c-md__list">' . implode('', $items) . '</ol>';
                continue;
            }

            // Paragraph — consume until a blank line or a line that starts a block.
            $para = [];
            while ($i < $count && trim($lines[$i]) !== ''
                   && !preg_match('/^(#{1,4}\s|\s*[-*]\s|\s*\d+[.)]\s|\0BLOCK)/', $lines[$i])) {
                $para[] = $lines[$i];
                $i++;
            }
            if ($para !== []) {
                $out[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
            }
        }

        return implode("\n", $out);
    }

    /**
     * Consume a Markdown table starting at $start.
     *
     * @param array<int, string> $lines
     * @return array{0: string, 1: int} [html, next line index]
     */
    private static function table(array $lines, int $start): array
    {
        $cells = static fn (string $row): array => array_map(
            'trim',
            explode('|', trim(trim($row), '|'))
        );

        $head = $cells($lines[$start]);
        $i    = $start + 2; // skip the separator row
        $body = [];

        while ($i < count($lines) && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
            $body[] = $cells($lines[$i]);
            $i++;
        }

        $html = '<div class="c-md__table-wrap"><table class="c-md__table"><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>' . self::inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($body as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . self::inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return [$html . '</tbody></table></div>', $i];
    }

    /** Inline formatting. Operates on already-escaped text. */
    private static function inline(string $text): string
    {
        // Inline code first, so formatting inside backticks is left alone.
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;

        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '<em>$1</em>', $text) ?? $text;

        // Links: the URL is validated rather than trusted, so a model cannot emit
        // javascript: or data: through this.
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            static fn (array $m): string =>
                '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
            $text
        ) ?? $text;

        return $text;
    }
}
