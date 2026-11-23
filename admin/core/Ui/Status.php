<?php

declare(strict_types=1);

namespace Cms\Ui;

/**
 * The entry-status chip — ONE chip, built on ACCENT + A GLYPH.
 *
 * Never a per-state hue and never an alert-colour tint. The state reads from the
 * GLYPH, not from the colour. That is not minimalism, it is what makes the chip
 * survive every theme: amber and red are plausible ACCENT colours, so the moment a
 * theme's accent is orange, a "warning" chip and a "brand" chip are the same
 * colour and the alarm stops meaning anything.
 *
 * The intensity ramp is muted → neutral → accent, and nothing else:
 *
 *   published   ACCENT   it is live; the only actionable state
 *   password    neutral  live, but gated
 *   private     neutral  not public — the address 404s unless you are signed in
 *   trash       muted    not live, and on its way out
 *
 * ── THERE WERE FIVE, AND TWO OF THEM WERE THE SAME STATE ────────────────────
 * `hidden` sat between `private` and `trash` and did, to a visitor, exactly
 * what `private` does: 404. One state is enough, and Private 404s for anyone
 * not signed in. Migration 043 remaps every `hidden` row and narrows both
 * ENUMs.
 *
 * `private` is NEUTRAL and not muted, because it is the working state — the
 * thing being written before it goes out. Muted is for the state on its way to
 * being deleted, and there is now only one of those.
 *
 * ── THE GLYPH LIVES IN THE REGISTRY, BESIDE THE LABEL ───────────────────────
 * Put them in separate maps and they drift; when they drift, `Icons::render()`
 * returns '' for the missing slug and every state becomes an identical grey
 * chip — the system dies silently and nothing looks broken.
 *
 * `render()` therefore CANNOT emit an iconless chip, and `registry()` is public
 * so it can be asserted total (`Icons::assertTotal()`).
 */
final class Status
{
    /**
     * The status vocabulary, in the order the `posts.status` ENUM declares it.
     *
     * @return array<string, array{label: string, icon: string, class: string}>
     */
    public static function registry(): array
    {
        return [
            'published' => ['label' => 'Published', 'icon' => 'check',          'class' => 'status--active'],
            'password'  => ['label' => 'Password',  'icon' => 'lock',           'class' => ''],
            'private'   => ['label' => 'Private',   'icon' => 'shield-halved',  'class' => ''],
            'trash'     => ['label' => 'Trash',     'icon' => 'trash-can',      'class' => 'status--archived'],
        ];
    }

    /**
     * States an entry is SHOWN in that the column does not store.
     *
     * `scheduled` is `published` (or `password`) with a date still to come —
     * see Post::isScheduled(). It is neutral, not accent: accent means "it is
     * live", and it is not live yet. The glyph is a clock because the thing it
     * is waiting for is a time.
     *
     * @return array<string, array{label: string, icon: string, class: string}>
     */
    public static function derived(): array
    {
        return [
            'scheduled' => ['label' => 'Scheduled', 'icon' => 'clock', 'class' => ''],
            // Published or password-protected, and its unpublish date has
            // come (Post::hasEnded()): off the site, as Private is. Neutral,
            // for the same reason; the hourglass is time that has run out.
            'ended'     => ['label' => 'Ended', 'icon' => 'hourglass', 'class' => ''],
        ];
    }

    /** The state an entry's or a page's chip shows: stored, or derived from its dates. */
    public static function forEntry(array $row): string
    {
        if (\Cms\Models\Post::isScheduled($row)) {
            return 'scheduled';
        }
        return \Cms\Models\Post::hasEnded($row) ? 'ended' : (string) ($row['status'] ?? '');
    }

    /** Just the labels — for the STATUS SELECT, which offers what can be stored. */
    public static function labels(): array
    {
        return array_map(static fn (array $s): string => $s['label'], self::registry());
    }

    /**
     * Labels for a LIST FILTER: what can be stored plus what can be shown, with
     * Scheduled and Ended beside Published. A derived state is not something you choose
     * in the entry's select — it is a date you set — but it is something you
     * look for in a list.
     *
     * @return array<string, string>
     */
    public static function filterLabels(): array
    {
        $all = self::registry();
        $out = ['published' => $all['published']] + self::derived() + $all;
        return array_map(static fn (array $s): string => $s['label'], $out);
    }

    /** slug map, for `Icons::assertTotal()` — derived states too, since they render. @return array<string, string> */
    public static function glyphs(): array
    {
        return array_map(static fn (array $s): string => $s['icon'], self::registry() + self::derived());
    }

    /**
     * The chip.
     *
     * An unregistered status renders with the raw value as its label and the
     * neutral question-free fallback glyph, rather than an empty chip — the row
     * still says *something*, and the miss is logged by `Icons`. It cannot
     * happen while the column is an ENUM; it is here because the column will not
     * always be one.
     */
    public static function render(string $status): string
    {
        $s = (self::registry() + self::derived())[$status] ?? [
            'label' => ucfirst($status),
            'icon'  => 'circle-info',
            'class' => '',
        ];

        $cls = 'status' . ($s['class'] !== '' ? ' ' . $s['class'] : '');

        return '<span class="' . View::escape($cls) . '">'
            . Icons::render($s['icon'], 'status__icon icon', '', 12)
            . View::escape($s['label'])
            . '</span>';
    }
}
