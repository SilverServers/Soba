<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\ContentType\Registry;
use Cms\Models\Meta;
use Cms\Models\Page;
use Cms\Models\Post;

/**
 * "Where do these words come from?" — one search across everything.
 *
 * **Why this exists.** A site owner points at their screen and says "change
 * that". They do not know, and must never need to know, that the words in front
 * of them might live in a content type entry, a page field, a page body, a
 * site-wide text setting, a block, or a string literal in a template. Until this
 * existed the assistant had to *guess* which of those six to look in, and it
 * guessed wrong repeatedly: it offered to edit a `content` column that renders
 * nowhere, failed to find a headline sitting in plain view, and told someone
 * their About page did not exist.
 *
 * Every one of those was the same bug — inference standing in for a lookup. This
 * replaces the inference. One string in, every place it occurs out, each one
 * naming the operation that would change it.
 */
final class SiteSearch
{
    /**
     * Find visible text anywhere on the site.
     *
     * @return array<int, array<string, mixed>> Most specific sources first
     */
    public static function find(string $needle, ToolContext $context, int $limit = 30): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [];
        }

        $hits = [];

        foreach (
            [
                self::inPages($needle),
                self::inEntries($needle, $context),
            ] as $group
        ) {
            foreach ($group as $hit) {
                $hits[] = $hit;

                if (count($hits) >= $limit) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /** @return array<int, array<string, mixed>> */
    private static function inPages(string $needle): array
    {
        $hits = [];

        foreach (Page::all() as $page) {
            $pageId = (int) $page['id'];

            if (self::matches((string) $page['title'], $needle)) {
                $hits[] = [
                    'where'       => 'The "' . $page['title'] . '" page — its title',
                    'what'        => 'Page title',
                    'value'       => $page['title'],
                    'change_with' => ['op' => 'update_page', 'page_id' => $pageId, 'field' => 'title'],
                ];
            }

            if (self::matches((string) $page['content'], $needle)) {
                $hits[] = [
                    'where'       => 'The "' . $page['title'] . '" page — its main text',
                    'what'        => 'Page content',
                    'value'       => self::excerpt((string) $page['content'], $needle),
                    'change_with' => ['op' => 'update_page', 'page_id' => $pageId, 'field' => 'content'],
                ];
            }
        }

        return $hits;
    }

    /** @return array<int, array<string, mixed>> */
    private static function inEntries(string $needle, ToolContext $context): array
    {
        $hits = [];

        foreach (Registry::all() as $typeKey => $definition) {
            foreach (Post::all($typeKey, $context->authorFilter()) as $entry) {
                $entryId = (int) $entry['id'];

                if (self::matches((string) $entry['title'], $needle)) {
                    $hits[] = [
                        'where'       => $definition['label_single'] . ' "' . $entry['title'] . '" — its title',
                        'what'        => 'Title',
                        'value'       => $entry['title'],
                        'change_with' => ['op' => 'update_core', 'entry_id' => $entryId, 'field' => 'title'],
                    ];
                }

                if (self::matches((string) $entry['content'], $needle)) {
                    $hits[] = [
                        'where'       => $definition['label_single'] . ' "' . $entry['title'] . '" — its main text',
                        'what'        => 'Content',
                        'value'       => self::excerpt((string) $entry['content'], $needle),
                        'change_with' => ['op' => 'update_core', 'entry_id' => $entryId, 'field' => 'content'],
                    ];
                }

                foreach (Meta::getAll($entryId) as $key => $value) {
                    if (str_starts_with((string) $key, '_') || !self::matchesDeep($value, $needle)) {
                        continue;
                    }

                    $hits[] = [
                        'where'       => $definition['label_single'] . ' "' . $entry['title'] . '" — ' . $key,
                        'what'        => $key,
                        'value'       => self::excerpt((string) $value, $needle),
                        'change_with' => ['op' => 'update_field', 'entry_id' => $entryId, 'field' => $key],
                    ];
                }
            }
        }

        return $hits;
    }

    // ----------------------------------------------------------------

    private static function matches(string $haystack, string $needle): bool
    {
        return $haystack !== '' && stripos(strip_tags($haystack), $needle) !== false;
    }

    /** Repeaters and lists are JSON; search inside them too. */
    private static function matchesDeep(mixed $value, string $needle): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return stripos(json_encode($value) ?: '', $needle) !== false;
        }

        return self::matches((string) $value, $needle);
    }

    /** A window around the match, so the model sees context not a whole article. */
    private static function excerpt(string $text, string $needle, int $window = 90): string
    {
        $plain    = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        $position = stripos($plain, $needle);

        if ($position === false) {
            return mb_substr($plain, 0, $window * 2);
        }

        $start = max(0, $position - $window);

        return ($start > 0 ? '…' : '')
            . mb_substr($plain, $start, $window * 2 + mb_strlen($needle))
            . ($start + $window * 2 < mb_strlen($plain) ? '…' : '');
    }
}
