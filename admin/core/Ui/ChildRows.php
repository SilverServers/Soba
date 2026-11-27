<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\ContentType\FieldRenderer;
use Cms\Helpers\Url;
use Cms\Models\Meta;
use Cms\Models\Post;

/**
 * A PARENT'S CHILDREN ON ITS OWN EDITOR — one child type's panel of rows
 * (`ContentType\Children`), which `child-rows.js` drives: each row saved as
 * it is added or changed, through the child's own save and checks, its
 * refusal said in the row; reordered by its grip or by Up one and Down one;
 * Edit opens the child's own screen and Trash moves it to the trash.
 *
 * ONE MARKUP FOR A KEPT ROW AND A NEW ONE: the panel's template is `row()`
 * with `__ROW__` for its name, which the script replaces, so a row added in
 * place is the row the server would have drawn.
 *
 * ITS CONTROLS ARE NOT THE PARENT'S. They post as `rows[{row}][title]` and
 * `rows[{row}][field_{key}]`, which the parent's own Save never reads, the
 * script stripping the prefix when it sends a row; they carry no `required`
 * or logic of their own (`FieldRenderer::renderRow()`); and the panel is
 * `[data-saves-itself]`, which keep-writing.js leaves alone, since a row
 * saved is not writing left unsaved.
 */
final class ChildRows
{
    /**
     * One child type's panel on its parent's editor; on a parent not yet
     * saved, the line that says its rows come once it is.
     *
     * @param array<string, mixed>|null $parent the parent entry, null while it is new
     * @param array{key: string, type: array<string, mixed>, columns: list<array<string, mixed>>} $child
     */
    public static function panel(?array $parent, array $child, string $parentSingle): string
    {
        $type   = $child['type'];
        $label  = (string) ($type['label'] ?? $child['key']);
        $single = (string) ($type['label_single'] ?? $label);
        $glyph  = Icons::render(Sidebar::iconFor((string) ($type['menu_icon'] ?? '')), 'icon icon--sm');
        $html   = '<div class="form-card__section" data-children="' . View::escape($child['key']) . '"';

        if ($parent === null) {
            return $html . '>'
                . '<h2 class="form-card__title">' . $glyph . View::escape($label) . '</h2>'
                . '<div class="empty">' . Icons::render(Sidebar::iconFor((string) ($type['menu_icon'] ?? '')), 'empty__glyph', '', 24)
                . '<p class="empty__title">' . View::escape($label) . ' are added here once this '
                . View::escape(mb_strtolower($parentSingle)) . ' is saved.</p></div></div>';
        }

        $rows = Post::childrenOfType((int) $parent['id'], $child['key']);
        $meta = Meta::getAllFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $slug = (string) $type['admin_slug'];

        $html .= ' data-saves-itself data-row-base="' . View::escape(Url::admin($slug)) . '"'
               . ' data-parent="' . (int) $parent['id'] . '" data-single="' . View::escape($single) . '">';
        $html .= '<h2 class="form-card__title">' . $glyph . View::escape($label) . '</h2>';
        $html .= '<div class="child-rows" data-child-rows>';
        foreach ($rows as $row) {
            $html .= self::row($child, $row, $meta[(int) $row['id']] ?? [], (string) (int) $row['id']);
        }
        $html .= '</div>';
        $html .= '<div class="empty" data-child-empty' . ($rows === [] ? '' : ' hidden') . '>'
               . Icons::render(Sidebar::iconFor((string) ($type['menu_icon'] ?? '')), 'empty__glyph', '', 24)
               . '<p class="empty__title">No ' . View::escape(mb_strtolower($label)) . ' yet</p></div>';
        $html .= '<div class="form-actions form-actions--bare">'
               . '<button type="button" class="btn" data-child-add>' . Icons::render('plus', 'icon icon--sm')
               . 'Add ' . View::escape(self::article($single)) . ' ' . View::escape(mb_strtolower($single)) . '</button></div>';
        $html .= '<template data-child-template>' . self::row($child, null, [], '__ROW__') . '</template>';

        return $html . '</div>';
    }

    /**
     * One row: its grip, its state, its actions, and a control for each
     * column, holding what the child keeps.
     *
     * @param array{key: string, type: array<string, mixed>, columns: list<array<string, mixed>>} $child
     * @param array<string, mixed>|null $row  the child entry, null for a new row
     * @param array<string, mixed>      $meta its fields as kept
     */
    public static function row(array $child, ?array $row, array $meta, string $token): string
    {
        $type   = $child['type'];
        $single = (string) ($type['label_single'] ?? $child['key']);
        $title  = $row === null ? '' : (string) $row['title'];
        $name   = $title !== '' ? $title : 'New ' . mb_strtolower($single);
        $id     = $row === null ? '' : (string) (int) $row['id'];
        $edit   = $row === null ? '' : Url::admin((string) $type['admin_slug'] . '/' . $id . '/edit');

        $html  = '<div class="child-row" data-child-row data-id="' . $id . '" role="group" aria-label="' . View::escape($name) . '">';
        $html .= '<div class="child-row__bar">';
        // DRAGGED BY ITS GRIP, which a keyboard does not take: Up one and
        // Down one are its keyboard's way, so the grip is no stop on the way.
        $html .= '<span class="child-row__grip" data-child-grip aria-hidden="true">' . Icons::render('grip-vertical', 'icon icon--sm') . '</span>';
        $html .= '<span class="child-row__status" data-child-status>' . ($row === null ? '' : Status::render(Status::forEntry($row))) . '</span>';
        $html .= '<span class="child-row__state" data-child-state role="status" aria-live="polite"></span>';
        $html .= '<span class="child-row__actions">'
               . '<button type="button" class="icon-btn" data-child-up aria-label="Up one" data-tip="Up one">' . Icons::render('arrow-up', 'icon icon--sm') . '</button>'
               . '<button type="button" class="icon-btn" data-child-down aria-label="Down one" data-tip="Down one">' . Icons::render('arrow-down', 'icon icon--sm') . '</button>'
               . '<a class="icon-btn" data-child-edit href="' . View::escape($edit) . '" aria-label="Edit" data-tip="Edit"' . ($row === null ? ' hidden' : '') . '>'
               . Icons::render('pen-to-square', 'icon icon--sm') . '</a>'
               . '<button type="button" class="icon-btn" data-child-trash aria-label="Move to trash" data-tip="Move to trash"'
               . ' data-confirm="Move this ' . View::escape(mb_strtolower($single)) . ' to the trash?"'
               . ' data-confirm-detail="It can be restored from the trash of ' . View::escape((string) ($type['label'] ?? $single)) . '."'
               . ' data-confirm-verb="Move to trash">' . Icons::render('trash-can', 'icon icon--sm') . '</button>'
               . '</span>';
        $html .= '</div>';
        // ITS REFUSAL, IN ITS ROW: the inline alert surface, for a dense row.
        $html .= '<div class="child-row__error" data-child-error hidden><span class="alert-flag">'
               . Icons::render('triangle-exclamation', 'icon') . '<span data-child-error-text></span></span></div>';

        $html .= '<div class="child-row__fields form-grid form-grid--12">';
        foreach ($child['columns'] as $column) {
            $field = 'rows[' . $token . ']';
            if (($column['type'] ?? '') === 'title') {
                $html .= '<div class="field"><label class="label" for="f-rows-' . View::escape($token) . '-title">Title</label>'
                       . '<input class="input" type="text" id="f-rows-' . View::escape($token) . '-title" name="' . View::escape($field . '[title]') . '"'
                       . ' value="' . View::escape($title) . '" maxlength="500" autocomplete="off" data-child-title></div>';
                continue;
            }
            $key    = (string) $column['key'];
            $html  .= FieldRenderer::renderRow($column, $field . '[field_' . $key . ']', $meta[$key] ?? null);
        }

        return $html . '</div></div>';
    }

    /** "a" or "an" before a word, by its first letter. */
    private static function article(string $word): string
    {
        return preg_match('/^[aeiou]/i', $word) === 1 ? 'an' : 'a';
    }
}
