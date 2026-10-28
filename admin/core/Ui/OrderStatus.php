<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Models\Order;

/**
 * THE ORDER-STATUS CHIP — the same one chip as `Ui\Status`, for orders.
 *
 * Read `Status`' header first; every word of its reasoning applies here and is
 * not repeated. The short version: ACCENT PLUS A GLYPH, never a per-state hue.
 * Amber and red are plausible accent colours, so the moment a theme's accent is
 * orange a "warning" chip and a "brand" chip are the same colour and the alarm
 * stops meaning anything. The state reads from the glyph.
 *
 * ── WHY A SECOND CLASS RATHER THAN ROWS ADDED TO `Status` ───────────────────
 * `Status::registry()` is documented as being in the order the `posts.status`
 * ENUM declares it, and `Ui\Status` is used everywhere an ENTRY is listed. An
 * order is not an entry and its seven statuses are not that ENUM's five;
 * merging them would produce one registry where half the rows are illegal for
 * any given caller, and `labels()` — which feeds a filter's options — would
 * offer "Refunded" as a state to publish a news article in.
 *
 * What IS shared is the markup and the CSS class, so the two chips are the same
 * object on screen. Same component, two vocabularies.
 *
 * ── THE INTENSITY RAMP ──────────────────────────────────────────────────────
 *   pending      neutral  waiting on the customer's money
 *   processing   ACCENT   paid, and the owner's turn to act — the actionable one
 *   on-hold      neutral  paused on purpose
 *   completed    ACCENT   done, and the state the owner is aiming for
 *   cancelled    muted    over, no money moved
 *   refunded     muted    over, money went back
 *   failed       muted    over, and nobody chose it
 *
 * Two accent states rather than one, unlike `Status`. That is deliberate: on
 * this screen the eye is looking for "what needs me" (processing) and "what is
 * finished" (completed), and everything else is context. A ramp with one bright
 * state would make an owner read every row to find the done ones.
 */
final class OrderStatus
{
    /**
     * The vocabulary.
     *
     * ── THE LABELS COME FROM `Order::STATUSES` ─────────────────────────────
     * One list of statuses, not two. A label typed here would drift from the
     * model's the first time one was reworded, and the drift would be invisible
     * — a chip reading "On hold" beside a filter reading "On-hold", both
     * correct-looking. Only the GLYPH and the CLASS are this file's business.
     *
     * Glyph beside label, in one registry, or they drift and `Icons::render()`
     * silently returns '' for the missing slug — at which point every state is
     * an identical grey chip and nothing looks broken.
     *
     * @return array<string, array{label: string, icon: string, class: string}>
     */
    public static function registry(): array
    {
        $marks = [
            // Waiting on somebody else. An hourglass, not a clock: a clock is
            // a time, an hourglass is a wait.
            'pending'    => ['hourglass',          ''],
            // The one that needs the owner. Accent.
            'processing' => ['box-open',           'status--active'],
            'on-hold'    => ['pause',              ''],
            'completed'  => ['check',              'status--active'],
            'cancelled'  => ['x',                  'status--archived'],
            // Money going back the way it came.
            'refunded'   => ['arrow-rotate-left',  'status--archived'],
            'failed'     => ['triangle-exclamation', 'status--archived'],
        ];

        $out = [];
        foreach (Order::STATUSES as $key => $label) {
            [$icon, $class] = $marks[$key] ?? ['circle-info', ''];
            $out[$key] = ['label' => $label, 'icon' => $icon, 'class' => $class];
        }

        return $out;
    }

    /** slug map, for `Icons::assertTotal()`. @return array<string, string> */
    public static function glyphs(): array
    {
        return array_map(static fn (array $s): string => $s['icon'], self::registry());
    }

    /**
     * The chip.
     *
     * CANNOT EMIT AN ICONLESS CHIP — same rule as `Status::render()`. A status
     * this registry does not know still gets a glyph, because the alternative
     * is a row that looks like every other row.
     */
    public static function render(string $status): string
    {
        $s = self::registry()[$status] ?? [
            'label' => Order::statusLabel($status),
            'icon'  => 'circle-info',
            'class' => '',
        ];

        // BYTE-FOR-BYTE `Status::render()`'s markup, including the 12px size
        // and the `status__icon icon` class pair. The two chips are the same
        // object on screen and a stylesheet change to one must land on both;
        // writing this freehand produced `icon icon--sm` and no size attribute,
        // which drew a chip a different height from every other chip in the
        // admin. The vocabularies differ, the component does not.
        // ── A MULTI-WORD LABEL IS A PHRASE, AND THE CHIP KNOWS THE WORD ────
        // `.status` sets `text-transform: capitalize`, which is right for the
        // one-word states it was built for — published, hidden, private — and
        // Title-Cases anything longer: "Pending payment" rendered as "Pending
        // Payment". Three of these seven labels are phrases.
        //
        // `.status--phrase` already exists for exactly this and core.css says
        // so on the line above the transform. Applied BY RULE rather than by
        // listing which labels have a space in them, so a status reworded in
        // `Order::STATUSES` gets the right treatment without anyone
        // remembering to come here.
        $cls = 'status' . ($s['class'] !== '' ? ' ' . $s['class'] : '');
        if (str_contains(trim($s['label']), ' ')) {
            $cls .= ' status--phrase';
        }

        return '<span class="' . View::escape($cls) . '">'
            . Icons::render($s['icon'], 'status__icon icon', '', 12)
            . View::escape($s['label'])
            . '</span>';
    }
}
