<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Forms\Logic;
use Cms\Models\FieldGroup;
use Cms\Ui\FieldWidth;

/**
 * FieldGroupController — admin CRUD for field groups.
 *
 * Field groups are sets of custom fields assigned to one or more post types
 * via location rules. They're separate from CPT definitions and can be reused
 * across multiple post types.
 */
class FieldGroupController
{
    // ----------------------------------------------------------------
    // List
    // ----------------------------------------------------------------

    public function index(array $vars = []): void
    {
        Auth::requireRole('admin');

        $groups      = FieldGroup::all();

        // A code-registered Content Type carries its fields in its registration,
        // so those fields *are* a field group — they just have no row in the
        // `field_groups` table. Reading the table alone told an install whose
        // schema is defined in code that it had no fields at all, while every
        // edit screen was rendering them.
        $codeGroups = [];
        foreach (Registry::all() as $ct) {
            if (!empty($ct['fields'])) {
                $codeGroups[] = [
                    'title'         => $ct['label'] . ' fields',
                    'content_types' => [$ct['key']],
                    'count'         => count($ct['fields']),
                    'admin_slug'    => $ct['admin_slug'],
                ];
            }
        }

        $pageTitle   = 'Field Groups';
        $currentPage = 'field-groups';

        ob_start();
        require ROOT_PATH . '/admin/templates/field-groups/list.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Create
    // ----------------------------------------------------------------

    public function showCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $group       = null;
        $formData    = [];
        $errors      = [];
        $allContentTypes = Registry::all();
        $pageTitle   = 'Add Field Group';
        $currentPage = 'field-groups';

        ob_start();
        require ROOT_PATH . '/admin/templates/field-groups/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleCreate(array $vars = []): void
    {
        Auth::requireRole('admin');

        [$errors, $data] = $this->validateAndSanitize($_POST);

        if (!empty($errors)) {
            $group       = null;
            $formData    = $_POST;
            $allContentTypes = Registry::all();
            $pageTitle   = 'Add Field Group';
            $currentPage = 'field-groups';

            ob_start();
            require ROOT_PATH . '/admin/templates/field-groups/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        $id = FieldGroup::create($data);

        header('Location: ' . ADMIN_URL . '/field-groups/' . $id . '/edit');
        exit;
    }

    // ----------------------------------------------------------------
    // Edit
    // ----------------------------------------------------------------

    public function showEdit(array $vars = []): void
    {
        Auth::requireRole('admin');

        $group = FieldGroup::find((int) $vars['id']);

        if ($group === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        $formData    = [];
        $errors      = [];
        $allContentTypes = Registry::all();
        $pageTitle   = 'Edit Field Group';
        $currentPage = 'field-groups';

        ob_start();
        require ROOT_PATH . '/admin/templates/field-groups/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    public function handleUpdate(array $vars = []): void
    {
        Auth::requireRole('admin');

        $group = FieldGroup::find((int) $vars['id']);

        if ($group === null) {
            http_response_code(404);
            require ROOT_PATH . '/templates/404.php';
            return;
        }

        [$errors, $data] = $this->validateAndSanitize($_POST);

        if (!empty($errors)) {
            $formData    = $_POST;
            $allContentTypes = Registry::all();
            $pageTitle   = 'Edit Field Group';
            $currentPage = 'field-groups';

            ob_start();
            require ROOT_PATH . '/admin/templates/field-groups/edit.php';
            $pageContent = ob_get_clean();

            require ROOT_PATH . '/admin/templates/layout.php';
            return;
        }

        FieldGroup::update((int) $vars['id'], $data);

        $group       = FieldGroup::find((int) $vars['id']);
        $formData    = [];
        $errors      = [];
        $saved       = true;
        $allContentTypes = Registry::all();
        $pageTitle   = 'Edit Field Group';
        $currentPage = 'field-groups';

        ob_start();
        require ROOT_PATH . '/admin/templates/field-groups/edit.php';
        $pageContent = ob_get_clean();

        require ROOT_PATH . '/admin/templates/layout.php';
    }

    // ----------------------------------------------------------------
    // Delete
    // ----------------------------------------------------------------

    public function handleDelete(array $vars = []): void
    {
        Auth::requireRole('admin');

        FieldGroup::delete((int) $vars['id']);

        header('Location: ' . ADMIN_URL . '/field-groups');
        exit;
    }

    // ----------------------------------------------------------------
    // Validation + parsing
    // ----------------------------------------------------------------

    /**
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function validateAndSanitize(array $post): array
    {
        $errors = [];

        $title      = trim($post['title'] ?? '');
        $postTypes  = array_values(array_filter((array) ($post['content_types'] ?? []), 'is_string'));
        $isActive   = isset($post['is_active']) ? 1 : 0;
        $rawFields  = (array) ($post['fields'] ?? []);

        if ($title === '') {
            $errors['title'] = 'Field group title is required.';
        }

        // Parse and validate fields
        $fields = [];
        foreach ($rawFields as $i => $f) {
            $label = trim($f['label'] ?? '');
            $key   = trim($f['key']   ?? '');
            $type  = trim($f['type']  ?? 'text');

            if ($label === '' && $key === '') {
                continue; // skip empty rows
            }

            if ($key === '' && $label !== '') {
                $key = $this->labelToKey($label);
            }

            if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key)) {
                // NAMED, NOT NUMBERED. "Field #{$i}" would be the ARRAY index —
                // so the first row would be "Field #0" and the message would
                // point one row off for every reader who counts from one. The
                // editor also renders this same string as a flag ON the
                // offending row, so a position would be redundant as well as
                // wrong; what the reader needs is which field, and they named it.
                $errors['field_' . $i] = ($label !== '' ? '"' . $label . '"' : 'A field')
                    . ' needs a key of lowercase letters, digits and underscores,'
                    . ' starting with a letter — "' . $key . '" is not one.';
                continue;
            }

            $field = [
                // RAW, NOT ESCAPED. Storing htmlspecialchars($label) would be
                // escape-on-STORE — and that is wrong twice.
                //
                // 1. Every reader escapes again at output (FieldRenderer::wrap()
                //    calls View::escape, every template here calls h()), so the
                //    author would see `&amp;` where they typed `&`.
                // 2. It is not idempotent. FieldGroup::update() deletes every
                //    field row and re-inserts from the POST, and the POST is
                //    whatever the form last rendered — so each save would escape
                //    the previous save's escape: `&` → `&amp;` → `&amp;amp;`,
                //    compounding forever with nothing to stop it.
                //
                // Saving with NO changes would still turn "Start Date & Time"
                // into "Start Date &amp; Time" in the table, while the label
                // LOOKS right on the render that produced it.
                //
                // Escape every user-supplied value AT OUTPUT.
                'label'    => $label,
                'key'      => $key,
                'type'     => $type,
                'required' => !empty($f['required']) ? 1 : 0,
            ];

            // Type-specific settings
            $settings = (array) ($f['settings'] ?? []);

            switch ($type) {
                case 'textarea':
                case 'rich_text':
                    if (!empty($settings['rows'])) {
                        $field['rows'] = max(2, (int) $settings['rows']);
                    }
                    break;

                case 'number':
                    if (isset($settings['min']) && $settings['min'] !== '') {
                        $field['min'] = $settings['min'];
                    }
                    if (isset($settings['max']) && $settings['max'] !== '') {
                        $field['max'] = $settings['max'];
                    }
                    break;

                case 'select':
                    $field['options'] = $this->parseOptions($settings['options_text'] ?? '');
                    break;

                case 'relationship':
                    // Accept content_type_target (new) with fallback to post_type_target (legacy DB records)
                    $field['content_type_target'] = $settings['content_type_target'] ?? $settings['post_type_target'] ?? '';
                    break;

                // A TAXONOMY FIELD HAS THREE SETTINGS AND THIS SWITCH CARRIED
                // NONE OF THEM. FieldRenderer::renderTaxonomy() reads
                // `taxonomy_key`, `field_mode` and `multiple`; none survived a
                // save, so a taxonomy field round-tripped through this form came
                // back with no taxonomy — and renderTaxonomy's own no-taxonomy
                // branch then printed "This field has no taxonomy set" on every
                // entry form, which looks like a renderer bug and is a save bug.
                //
                // Found while building the editor: the type was offered in the
                // dropdown and there was nowhere for its settings to go. A
                // control that posts nowhere is worse than a missing one.
                case 'taxonomy':
                    $field['taxonomy_key'] = (string) ($settings['taxonomy_key'] ?? '');
                    // `select` is the renderer's own default; the other two are
                    // checkbox and radio, and anything else would silently fall
                    // through to select there anyway. Pin it here so the stored
                    // value is always one the renderer recognises.
                    $mode = (string) ($settings['field_mode'] ?? 'select');
                    $field['field_mode'] = in_array($mode, ['select', 'checkbox', 'radio'], true) ? $mode : 'select';
                    $field['multiple']   = !empty($settings['multiple']) ? 1 : 0;
                    break;

                case 'repeater':
                    $rawSubFields = $settings['sub_fields'] ?? [];
                    $subFields = [];
                    foreach ($rawSubFields as $sf) {
                        $sfLabel = trim($sf['label'] ?? '');
                        $sfKey   = trim($sf['key'] ?? '');
                        $sfType  = trim($sf['type'] ?? 'text');
                        if ($sfLabel === '' && $sfKey === '') {
                            continue;
                        }
                        if ($sfKey === '' && $sfLabel !== '') {
                            $sfKey = $this->labelToKey($sfLabel);
                        }
                        $sfSettings = [];
                        if ($sfType === 'textarea' || $sfType === 'rich_text') {
                            if (!empty($sf['settings']['rows'])) {
                                $sfSettings['rows'] = max(2, (int) $sf['settings']['rows']);
                            }
                        }
                        if ($sfType === 'number') {
                            if (isset($sf['settings']['min']) && $sf['settings']['min'] !== '') {
                                $sfSettings['min'] = $sf['settings']['min'];
                            }
                            if (isset($sf['settings']['max']) && $sf['settings']['max'] !== '') {
                                $sfSettings['max'] = $sf['settings']['max'];
                            }
                        }
                        if ($sfType === 'select') {
                            $sfSettings['options'] = $this->parseOptions($sf['settings']['options_text'] ?? '');
                        }
                        $subFields[] = [
                            // Raw, for the same reason as the parent label above.
                            'label'    => $sfLabel,
                            'key'      => $sfKey,
                            'type'     => $sfType,
                            'required' => !empty($sf['required']),
                            'settings' => $sfSettings,
                        ];
                    }
                    $field['sub_fields'] = $subFields;
                    break;
            }

            // ── SETTINGS EVERY TYPE HAS ─────────────────────────────────────
            // Outside the switch, because none of them is about what KIND of
            // control this is: every type has all four, per field — putting
            // them in the switch would mean repeating the same four lines
            // eleven times and forgetting one.
            //
            // Each is stored only when it has content: a settings blob carrying
            // `instructions: ""` on every field of every group is noise in every
            // dump and every diff.
            $instructions = trim((string) ($settings['instructions'] ?? ''));
            if ($instructions !== '') {
                $field['instructions'] = $instructions;
            }

            // `full` is the default the renderer already assumes, so storing it
            // would make "has a width" true for every field ever saved — which
            // is why `FieldWidth::store()` returns null for it. The reasoning
            // moved into that class with the list; four widths now, and this
            // does not have to name any of them.
            $width = FieldWidth::store($settings['width'] ?? null);
            if ($width !== null) {
                $field['width'] = $width;
            }

            $msgRequired = trim((string) ($settings['msg_required'] ?? ''));
            if ($msgRequired !== '') {
                $field['msg_required'] = $msgRequired;
            }

            // CONDITIONAL LOGIC GOES THROUGH THE SHARED PARSER. `Logic::parse()`
            // drops rules with no subject and unknown operators and returns null
            // when nothing is left, so a half-built rule set cannot be stored as
            // one that evaluates. It arrives as a JSON string from one hidden
            // input per row — the same wire shape the form builder uses, and for
            // the same reason: a rule list is a nested array, and
            // `fields[0][settings][logic][rules][0][op]` is a name nobody can
            // read or debug.
            $logic = Logic::parse($settings['logic'] ?? null);
            if ($logic !== null) {
                $field['logic'] = $logic;
            }

            $fields[] = $field;
        }

        $data = [
            // Raw, for the same reason as the field labels above. The list and
            // the editor both render it through h().
            'title'         => $title,
            'content_types' => $postTypes,
            'is_active'     => $isActive,
            'fields'        => $fields,
        ];

        return [$errors, $data];
    }

    /**
     * Convert a human label to a snake_case field key.
     * e.g. "Event Date" → "event_date"
     */
    private function labelToKey(string $label): string
    {
        $key = strtolower($label);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        return substr($key, 0, 50);
    }

    /**
     * Parse a textarea of "value : Label" lines into an associative array.
     * Lines with no colon use the line itself as both key and label.
     */
    private function parseOptions(string $text): array
    {
        $options = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_contains($line, ':')) {
                [$value, $label] = explode(':', $line, 2);
                $value = trim($value);
                $label = trim($label);
            } else {
                $value = $label = $line;
            }
            if ($value !== '') {
                $options[$value] = $label;
            }
        }
        return $options;
    }
}
