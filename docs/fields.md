# Fields

A field written in code is a list of options. Fields are written in four places: a content type's `'fields'` in `admin/config/content-types.php`, a taxonomy's `'fields'` in `admin/config/taxonomies.php`, for each of its terms, a settings page's sections in `admin/config/settings-pages.php`, and a repeater's `'sub_fields'`, for its columns. This page names every option a field takes, with an example of each. A field group made in Structure → Field groups has the same options, set on its screen.

## A field, from start to finish

```php
\Cms\ContentType\Registry::register('event', [
    'label'        => 'Events',
    'label_single' => 'Event',
    'fields'       => [
        ['key' => 'format', 'label' => 'Format', 'type' => 'select', 'required' => true,
         'options' => ['in_person' => 'In person', 'online' => 'Online']],
        ['key' => 'room', 'label' => 'Room', 'type' => 'text', 'width' => 'half',
         'required' => true, 'msg_required' => 'Say which room it is in.',
         'instructions' => 'The number on the door, such as B204.',
         'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [
             ['field' => 'format', 'op' => 'is', 'value' => 'in_person'],
         ]]],
    ],
]);
```

The editor draws Format as a choice of In person or Online, with no None since it is required, so every event has one. Room is drawn half as wide, with its instructions under it, and only while Format is In person: choosing Online hides it as one chooses. While it shows, an event saved without a room is refused with "Say which room it is in."; while it is hidden, it is not checked, and whatever it holds is kept.

## Every field's options

- `key`: the field's name, which its value is kept under (a template reads an entry's with `\Cms\Models\Meta::get($post['id'], 'room')`) and a logic rule names it by. Lower-case letters, digits and underscores, starting with a letter: `'key' => 'room'`.
- `label`: the words over the control, and in the error that names it: `'label' => 'Room'`.
- `type`: one of the types below, `text` when it is left out: `'type' => 'textarea'`.
- `required`: the field cannot be saved empty: `'required' => true`. A picture, file, gallery, relationship or taxonomy field with nothing chosen is empty, and so is a repeater with no rows. The message is "{label} is required." unless `msg_required` gives one.
- `msg_required`: the sentence a required field left empty is refused with: `'msg_required' => 'Say which room it is in.'`.
- `instructions`: help printed under the control: `'instructions' => 'The number on the door, such as B204.'`. `description` is the same option under another name, and wins when both are given: `['key' => 'room', 'label' => 'Room', 'description' => 'The number on the door, such as B204.']`.
- `width`: how much of the row the field takes, in twelfths: `full` (the default), `three-quarters`, `two-thirds`, `half`, `third` or `quarter`. Fields side by side fill a row, so a `quarter` sits beside a `three-quarters`. A picture, file, gallery, repeater or relationship field always takes the whole row: `'width' => 'half'`.
- `logic`: when the field shows, from the values of other fields (below).

## The types, and their own options

- `text`: one line. `placeholder` is grey text in the empty box: `['key' => 'venue', 'label' => 'Venue', 'type' => 'text', 'placeholder' => 'e.g. Conference Room A']`. A value that is a web address or an email address is printed as a link.
- `textarea`: several lines, printed with its line breaks. `rows` is its height in lines, 4 unless it says, and it takes a `placeholder`: `['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 6]`.
- `rich_text`: formatted text, written with the editor's toolbar and cleaned when it is saved. `rows` is its height, 12 unless it says: `['key' => 'bio', 'label' => 'Biography', 'type' => 'rich_text', 'rows' => 8]`.
- `number`: a number, or nothing. `min` and `max` are the least and most it may be, refused on save with "{label} must be at least {min}." or "… no more than {max}."; `step` is the steps it goes up in; `decimals` is how many places it takes when it names no step (2 is steps of 0.01); `prefix` is shown in front of the box, such as `$`, and never kept: `['key' => 'price', 'label' => 'Price', 'type' => 'number', 'min' => 0, 'decimals' => 2, 'prefix' => '$']`, or `['key' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'min' => 1, 'max' => 500, 'step' => 1]`.
- `phone`: a phone number, kept as typed, such as (250) 555-0142, and refused on save when it cannot be dialled as written: in Canada and the rest of +1, ten digits with the area code; elsewhere a + and the country's code. The country is the business's own, from Settings → Business details. The page prints it as a link to call it, and a template that draws its own link gets the number to dial from `\Cms\ContentType\FieldDisplay::dialable($value)`, '' for one that cannot be dialled. It takes a `placeholder`: `['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'placeholder' => '(250) 555-0142']`.
- `email`: an email address, refused on save when it is not one, and printed as a link to write to: `['key' => 'email', 'label' => 'Email', 'type' => 'email']`.
- `date`: a day, kept as `2026-11-27` and printed in the site's own date format.
- `datetime`: a day and a time, kept as `2026-11-27 09:00:00` and printed in the site's own formats.
- `select`: one of a list. `options` gives each choice, what is kept before what is shown: `'options' => ['in_person' => 'In person', 'online' => 'Online']`. The page prints what is shown. A field that is not required offers None as well.
- `checkbox`: a tick box, its label beside it. It keeps `1` or `0`, and the page prints "Yes" when it is ticked and nothing when it is not: `['key' => 'free_entry', 'label' => 'Free entry', 'type' => 'checkbox']`.
- `image`: a picture from the library, chosen or uploaded, kept by its id. A template draws it with `Media::image($id)`.
- `file`: a file from the library, such as a PDF, kept by its id. `accept` names the kinds it takes, every kind when it names none, and a file of another kind is refused: `['key' => 'menu', 'label' => 'Menu', 'type' => 'file', 'accept' => 'pdf, docx']`. The page prints it as a link with its name and size; a template reads it with `Media::file($id)`.
- `gallery`: pictures in order, each with a caption. `layout` is `grid` (the default) or `masonry`, `columns` is 2 to 6 (3 unless it says), and `lightbox` opens each picture full size: `['key' => 'photos', 'label' => 'Photos', 'type' => 'gallery', 'layout' => 'masonry', 'columns' => 4, 'lightbox' => true]`. A template reads it with `Media::gallery($value)`.
- `repeater`: rows of the fields its `sub_fields` give, which take the options above. `layout` is `table` for each row on one line, its columns side by side, or left out for rows stacked one under another. `preset_rows` makes a `select` column's choices the rows, each named by its choice, with no Add row or Remove, in a table: `['key' => 'prices', 'label' => 'Prices', 'type' => 'repeater', 'layout' => 'table', 'preset_rows' => ['field' => 'weight'], 'sub_fields' => [['key' => 'weight', 'label' => 'Weight', 'type' => 'select', 'options' => ['250g' => '250 g', '1kg' => '1 kg']], ['key' => 'price', 'label' => 'Price', 'type' => 'number', 'decimals' => 2, 'prefix' => '$']]]`. In stacked rows each column is drawn with its label, instructions and width; in a table, each column's label heads it. It is kept as a list of rows, each the columns' values by their keys. A column takes `required`, filled in every row, and a phone, email or number column is checked as its field would be: a save that breaks one is refused naming the row and the column, "Contacts, row 2: Phone, 555-0199, cannot be dialled as written…", or the row's choice in a table whose choices are its rows, "Hours, Tuesday: Opens is required.".
- `relationship`: entries of another type, chosen by their titles. `content_type` names the type, by its key: `['key' => 'speakers', 'label' => 'Speakers', 'type' => 'relationship', 'content_type' => 'team']`. It keeps the entries' ids, and the page prints each one a visitor can open, as a link to it.
- `taxonomy`: terms of a taxonomy, kept on the field itself and apart from the entry's own terms in its taxonomy panels. `taxonomy_key` names the taxonomy; `field_mode` is `select` (the default), `checkbox` or `radio`; `multiple` takes several: `['key' => 'main_topic', 'label' => 'Main topic', 'type' => 'taxonomy', 'taxonomy_key' => 'category', 'field_mode' => 'checkbox', 'multiple' => false]`. With `checkbox` or `radio` it is a list of the terms, ticked several at once when it is `multiple` and one at a time when it is not, with a box to add a term; a `select` that is `multiple` is typed into and chosen from.

## Showing a field only when others say so

`logic` shows or hides a field by the values of others in the same form:

```php
'logic' => [
    'action' => 'show',            // or 'hide'
    'match'  => 'all',             // every rule holds; 'any': one is enough
    'rules'  => [
        ['field' => 'format', 'op' => 'is', 'value' => 'in_person'],
        ['field' => 'capacity', 'op' => 'gt', 'value' => '100'],
    ],
],
```

A rule's `field` is another field's `key`, and its `op` is one of `is`, `is_not`, `contains`, `not_contains`, `gt` (is greater than), `lt` (is less than), `empty` and `not_empty`, the last two needing no `value`. A rule compares the words the other field holds as they are kept: a select's kept value (`in_person`, not In person), and `1` for a ticked box, so `is` `1` holds while it is ticked and `is_not` `1` while it is not. `gt` and `lt` compare numbers. A field of several values, a relationship's entries or a taxonomy field's terms, holds `is` when any one of them is the value.

A rule on one of an entry's taxonomies names it `tax:` and the taxonomy's key, and gives a term's slug: `['field' => 'tax:location', 'op' => 'is', 'value' => 'harbour']` holds while the entry is filed under the location whose slug is harbour, from the moment it is ticked, or chosen as a tag, and on save. `taxonomy_location`, with a term's id as its value, is read too.

A field is shown while its rules hold, or hidden while they hold with `'action' => 'hide'`, changing as one types or chooses, and the same rules decide on save: a required field that is hidden is not checked, and what it holds is kept. Logic is read on a field, not on a repeater's column.

## A typo is said in the log

An option the CMS does not know, a type it does not know, a `width`, `field_mode` or `layout` that is not one of its words, a field with no `key`, and a logic rule it cannot read are each said in the PHP error log when the definition is read, in one line for the type, taxonomy or settings page:

```
Fields in code: on the type "event", "room" has an option the CMS does not know ("placholder"), which does nothing.
```

The field is drawn as before, as if the option were not there. Since the site's definitions are read on every request, the line is said once, and again an hour later while it is still there.
