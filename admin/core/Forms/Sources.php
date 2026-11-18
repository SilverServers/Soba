<?php

declare(strict_types=1);

namespace Cms\Forms;

use Cms\ContentType\Registry;
use Cms\Models\Post;

/**
 * OPTIONS THAT COME FROM THE SITE'S OWN CONTENT.
 *
 * "Which event are you booking" is a list that changes every week, and a
 * hand-typed one is wrong the moment somebody adds an event. The Dynamic and
 * Lookup fields ask a Content Type for its entries instead.
 *
 * ── IT ASKS THE REGISTRY, NOT THE TABLE ─────────────────────────────────────
 * Anything answering "what exists in this install?" asks the Registry. A model
 * query answers the narrower question "what is in this table", and returning
 * that to a screen labelled with the broader one tells the owner there are no
 * content types while five are working in the sidebar.
 *
 * ── PUBLISHED ONLY ──────────────────────────────────────────────────────────
 * A draft is not on the site. Offering one in a public dropdown publishes its
 * title to every visitor of every page the form appears on — which is a
 * disclosure nobody chose, made by a field that looks like a convenience.
 */
final class Sources
{
    /** @return array<string, string> type key => its plural name, for the rail. */
    public static function types(): array
    {
        $out = [];
        foreach (Registry::all() as $key => $definition) {
            $out[(string) $key] = (string) ($definition['labels']['name'] ?? $key);
        }
        ksort($out);

        return $out;
    }

    /**
     * The choices one sourced field offers.
     *
     * The VALUE and the LABEL are both the entry's title. Storing an id would
     * be tidier and is wrong here: a submission is a record of what somebody
     * answered, and an id that later points at a renamed or deleted entry
     * turns that record into a number nobody can resolve. The title is what
     * they picked, and it stays true.
     *
     * @param  array<string, mixed> $field
     * @return list<string>
     */
    public static function choicesFor(array $field): array
    {
        $type = trim((string) ($field['source'] ?? ''));
        if ($type === '' || Registry::get($type) === null) {
            return [];
        }

        // A form is in the site's own language, and so are its choices: an
        // entry's translation is not one of them.
        $out = [];
        foreach (Post::all($type) as $row) {
            if (($row['status'] ?? '') !== 'published' || Post::isScheduled($row) || Post::hasEnded($row)
                || (string) ($row['lang'] ?? '') !== '') {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '') {
                $out[] = $title;
            }
        }

        return $out;
    }
}
