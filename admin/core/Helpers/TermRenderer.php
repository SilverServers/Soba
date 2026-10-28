<?php

declare(strict_types=1);

namespace Cms\Helpers;

use Cms\ContentType\FieldRenderer;
use Cms\ContentType\TaxonomyRegistry;

/**
 * TermRenderer — the taxonomy PANEL on an entry's edit form.
 *
 * Distinct from `FieldRenderer`'s `taxonomy` field type: this renders the real
 * `post_term_relationships` assignment, which the controller collects from
 * `taxonomy_{key}[]`. The field type stores ids in post_meta and is a separate
 * mechanism for a separate job.
 *
 * A static class rather than a function so including a template twice cannot
 * redeclare it.
 *
 * ── THE SAME CONTROL AS THE TAXONOMY FIELD ──────────────────────────────────
 * `checkboxTree()` composes `.check-list`, which is the same control the
 * taxonomy FIELD renders, so the panel and the field cannot look like two
 * different systems. No inline `style`: an indent such as `padding-left:
 * {$depth * 20}px` is a hardcoded spacing value off the `--sp-*` scale,
 * computed in PHP, where no stylesheet can reach it.
 */
class TermRenderer
{
    /**
     * The checkbox tree for a hierarchical taxonomy panel.
     *
     * @param array<int, array<string, mixed>> $tree        from Term::treeForTaxonomy()
     * @param int[]                            $assignedIds currently assigned term ids
     * @param string                           $taxonomyKey names the inputs
     */
    public static function checkboxTree(array $tree, array $assignedIds, string $taxonomyKey): string
    {
        $name  = 'taxonomy_' . $taxonomyKey . '[]';
        $empty = $tree === [];

        // ── ADD A TERM WITHOUT LEAVING THE ENTRY ────────────────────────────
        // The panel carries its own add control (`FieldRenderer::termAdder()`).
        // Without it, an author who needs a category that does not exist yet
        // has to abandon a half-written entry, walk to Structure → Taxonomies →
        // Terms, create it, come back and find their draft again. Filing a post
        // under a new category is the most ordinary thing anyone does on this
        // screen.
        //
        // THE EMPTY CASE GETS THE SAME CONTROL rather than a link away. Pointing
        // at the terms screen would be a round trip for the one case where you
        // certainly need to make a term.
        $tax   = TaxonomyRegistry::get($taxonomyKey) ?? [];
        $label = (string) ($tax['label_single'] ?? 'term');

        // THE TAXONOMY'S RULE, drawn (migration 056). Exactly one is a single
        // choice, so it is radios, and a term added here arrives as one. Any
        // other limit keeps the tick boxes and says it under them; the save
        // refuses what breaks it, in the same words.
        $min  = (int) ($tax['min_terms'] ?? 0);
        $max  = isset($tax['max_terms']) ? (int) $tax['max_terms'] : null;
        $type = ($min === 1 && $max === 1) ? 'radio' : 'checkbox';
        $rule = match (true) {
            $min === 1 && $max === 1 => 'Exactly one.',
            $min === 1 && $max === null => 'At least one.',
            $max !== null => 'No more than ' . $max . '.',
            default => '',
        };

        return '<div class="check-list" data-term-list>'
            . ($empty ? '' : FieldRenderer::checkTree($tree, $assignedIds, $type, $name))
            . '</div>'
            . ($rule !== '' ? '<p class="form-help">' . htmlspecialchars($rule) . '</p>' : '')
            . FieldRenderer::termAdder($taxonomyKey, $label, $type, $name, $empty);
    }

    /**
     * The panel for a NON-hierarchical (tag-style) taxonomy — a chip-picker.
     *
     * Not a checkbox list. `TaxonomyRegistry`'s `hierarchical` flag says what the
     * two kinds are for: true is category style (checkboxes + parent/child),
     * false is tag style (chip input + typeahead). A flat taxonomy is the one
     * that grows without bound — a site with 200 tags rendered as 200 checkboxes
     * is a column you scroll past, not a control — and the admin's own rule
     * settles it anyway: multi-select is a chip-picker.
     *
     * @param list<array<string, mixed>> $terms
     * @param int[]                      $assignedIds
     */
    public static function tagPicker(array $terms, array $assignedIds, string $taxonomyKey, string $termLabel): string
    {
        // AN EMPTY TAG TAXONOMY STILL GETS THE PICKER, because the picker can
        // create. Sending the author to another screen to make the first tag —
        // and then back to find their draft — is the whole cost this removes.
        $options = [];
        foreach ($terms as $term) {
            $options[(int) $term['id']] = (string) $term['name'];
        }

        return FieldRenderer::chipPicker(
            'taxonomy_' . $taxonomyKey,
            $options,
            $assignedIds,
            $termLabel,
            $taxonomyKey
        );
    }

    /**
     * A flat `<option>` list for a hierarchical taxonomy, indented by depth.
     *
     * The em-dash prefix stays here and ONLY here: an `<option>` cannot carry
     * layout, so text is genuinely the only way to show nesting inside a native
     * select. Everywhere the markup CAN nest — `checkboxTree()` above — it does.
     *
     * @param array<int, array<string, mixed>> $tree
     * @param int[]                            $selected
     */
    public static function selectOptions(array $tree, array $selected, int $depth = 0): string
    {
        $html   = '';
        $prefix = str_repeat('— ', $depth);

        foreach ($tree as $term) {
            $id = (int) $term['id'];

            $html .= '<option value="' . $id . '"'
                . (in_array($id, $selected, true) ? ' selected' : '') . '>'
                . \Cms\Ui\View::escape($prefix . (string) $term['name'])
                . '</option>';

            if (!empty($term['children'])) {
                $html .= self::selectOptions($term['children'], $selected, $depth + 1);
            }
        }

        return $html;
    }
}
