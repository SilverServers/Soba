-- Migration 149: where an entry's fields are drawn on its editor.
--
-- field_groups.position     a field group's Position, as on its own screen:
--                           'high' between the title and the text, 'normal'
--                           after the text, 'side' in the column beside. NULL
--                           follows its content type.
-- content_types.fields_position
--                           where a type's own fields go, and its groups that
--                           name no Position: 'high', or NULL for after the
--                           text. A type registered in code says
--                           'fields_position' in its definition.
--
-- IF NOT EXISTS, as 145's and 146's: a site that made the columns itself, with
-- these names, finds nothing to do here and keeps its values.
ALTER TABLE field_groups
    ADD COLUMN IF NOT EXISTS position VARCHAR(10) NULL DEFAULT NULL AFTER archetype_key;
ALTER TABLE content_types
    ADD COLUMN IF NOT EXISTS fields_position VARCHAR(10) NULL DEFAULT NULL AFTER slug_pattern;
