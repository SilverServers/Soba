<?php

declare(strict_types=1);

namespace Cms\ContentType;

use Cms\Helpers\Url;
use Cms\Html\Sanitizer;
use Cms\Models\Media;
use Cms\Models\Post;
use Cms\Models\Term;

/**
 * A FIELD'S VALUE AS A VISITOR READS IT.
 *
 * `FieldRenderer` turns a field into the control an owner types into; this
 * turns the stored value back into something a page can print. Without it, a
 * template prints only the archetype fields it knows by name — an event's
 * date, a listing's phone number, a product's price — and every field an
 * owner adds in Field Groups is saved, shown on the edit screen, and never
 * seen by a visitor.
 *
 * One method per stored shape, and '' for a value that is empty: a template
 * prints nothing at all rather than a label over a blank.
 *
 * ── WHAT IS STORED IS WHAT WAS TYPED ────────────────────────────────────────
 * A plain-text value is stored as typed and escaped here, once. Values saved
 * before that — stored escaped, sometimes several times over — are read
 * through `FieldRenderer::plain()` first, so they print as typed too.
 */
final class FieldDisplay
{
    /** @param array<string, mixed> $field  a field as the Registry hands it */
    public static function html(array $field, mixed $value): string
    {
        $stored = is_string($value) ? trim($value) : $value;
        if ($stored === null || $stored === '' || $stored === '[]') {
            return '';
        }

        return match ((string) ($field['type'] ?? 'text')) {
            'rich_text'    => Sanitizer::clean((string) $stored),
            'textarea'     => nl2br(self::text((string) $stored), false),
            'number'       => self::text((string) $stored),
            'date'         => self::when((string) $stored, 'F j, Y'),
            'datetime'     => self::when((string) $stored, 'F j, Y · g:i A'),
            'select'       => self::text((string) (($field['options'] ?? [])[$stored] ?? $stored)),
            // A TICK SAYS YES OR NOTHING. "No" under a label reads as a claim
            // the owner never made; an unticked box is the field not applying.
            'checkbox'     => $stored === '1' ? 'Yes' : '',
            'image'        => self::image((int) $stored),
            'repeater'     => self::repeater($field, (string) $stored),
            'relationship' => self::entries((string) $stored),
            'taxonomy'     => self::terms((string) $stored),
            default        => self::textOrLink((string) $stored),
        };
    }

    /** The value as typed, escaped for the page once. */
    private static function text(string $stored): string
    {
        return htmlspecialchars(FieldRenderer::plain($stored), ENT_QUOTES, 'UTF-8');
    }

    /**
     * A DATE AS THE OWNER MEANT IT, in the theme's format ("October 1, 2019",
     * as the single prints a publish date).
     *
     * Not through `Dates`, which reads a stored MOMENT as UTC and moves it into
     * the site's zone — right for a publish time, wrong here. A field holds the
     * owner's own local date, and moving it would print 30 September for a
     * 1 October event in Vancouver. Parsed and printed in the same zone, as
     * the single's event block does, so nothing moves.
     */
    private static function when(string $stored, string $format): string
    {
        $ts = strtotime(FieldRenderer::plain($stored));

        return $ts === false ? self::text($stored) : htmlspecialchars(date($format, $ts), ENT_QUOTES, 'UTF-8');
    }

    /** A web or mail address is a link; anything else is text. */
    private static function textOrLink(string $stored): string
    {
        $plain = FieldRenderer::plain($stored);
        if (preg_match('#^https?://\S+$#i', $plain) === 1) {
            return '<a href="' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars((string) (parse_url($plain, PHP_URL_HOST) ?: $plain), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        if (filter_var($plain, FILTER_VALIDATE_EMAIL) !== false) {
            $e = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
            return '<a href="mailto:' . $e . '">' . $e . '</a>';
        }

        return self::text($stored);
    }

    private static function image(int $id): string
    {
        $row = $id > 0 ? Media::find($id) : null;
        if ($row === null) {
            return '';
        }
        $w = (int) ($row['width'] ?? 0);
        $h = (int) ($row['height'] ?? 0);

        return '<img src="' . htmlspecialchars(Media::getUrl($id), ENT_QUOTES, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars((string) ($row['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
            . ($w > 0 && $h > 0 ? ' width="' . $w . '" height="' . $h . '"' : '')
            . ' loading="lazy">';
    }

    /** Rows of sub-fields — a list, each row its sub-fields' values in order. */
    private static function repeater(array $field, string $stored): string
    {
        // JSON FIRST, then each value: decoding the JSON string itself would
        // turn a value's `&quot;` into a quote that ends the JSON string.
        $rows = json_decode($stored, true);
        if (!is_array($rows)) {
            return '';
        }
        $items = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ((array) ($field['sub_fields'] ?? []) as $sub) {
                $cell = self::html($sub, is_array($row) ? ($row[$sub['key']] ?? '') : '');
                if ($cell !== '') {
                    $cells[] = $cell;
                }
            }
            if ($cells !== []) {
                $items[] = '<li>' . implode(' — ', $cells) . '</li>';
            }
        }

        return $items === [] ? '' : '<ul>' . implode('', $items) . '</ul>';
    }

    /** Linked entries, by title — only the ones a visitor can open. */
    private static function entries(string $stored): string
    {
        $links = [];
        foreach ((array) json_decode($stored, true) as $id) {
            $post = Post::find((int) $id);
            if ($post === null || $post['status'] !== 'published' || Post::isScheduled($post)) {
                continue;
            }
            $type = Registry::get((string) $post['post_type']);
            $tp   = (string) ($type['public_slug'] ?? '');
            $t    = htmlspecialchars((string) $post['title'], ENT_QUOTES, 'UTF-8');
            $links[] = $tp !== ''
                ? '<a href="' . htmlspecialchars(Url::page($tp . '/' . $post['slug']), ENT_QUOTES, 'UTF-8') . '">' . $t . '</a>'
                : $t;
        }

        return implode(', ', $links);
    }

    /** Terms, by name — linked to their archive where the taxonomy has one. */
    private static function terms(string $stored): string
    {
        $names = [];
        foreach ((array) json_decode($stored, true) as $id) {
            $term = Term::find((int) $id);
            if ($term === null) {
                continue;
            }
            $tax = TaxonomyRegistry::get((string) $term['taxonomy_key']);
            $tp  = (string) ($tax['public_slug'] ?? '');
            $n   = htmlspecialchars((string) $term['name'], ENT_QUOTES, 'UTF-8');
            $names[] = $tp !== ''
                ? '<a href="' . htmlspecialchars(Url::page($tp . '/' . $term['slug']), ENT_QUOTES, 'UTF-8') . '">' . $n . '</a>'
                : $n;
        }

        return implode(', ', $names);
    }
}
