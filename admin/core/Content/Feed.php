<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Commerce\Product;
use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Url;
use Cms\Models\Post;
use Cms\SEO\Head;

/**
 * A TYPE'S ENTRIES AS A FEED (RSS 2.0), for a feed reader, or a newsletter
 * service that sends what is new: `/{type}/feed.xml`, its newest live entries
 * first, each with its title, its address, its date and its summary.
 *
 * Every type with public pages has one, but a type that is only a product:
 * a shop's catalogue is the product feed (`Commerce\ProductFeed`), and a new
 * T-shirt is not news. A type that sells and is something more, such as events
 * with tickets, keeps its feed.
 *
 * NAMED IN THE HEAD (`links()`), where a reader looks for it: the home page
 * names every feed, and a type's archive, its entries and its terms' pages
 * name their type's. A static copy draws each one as a file, since the copy
 * follows every address a page names.
 */
final class Feed
{
    /** How many entries a feed carries, newest first. */
    public const ITEMS = 20;

    /** @return list<string> the keys of the types that have a feed */
    public static function types(): array
    {
        $out = [];
        foreach (Registry::all() as $key => $type) {
            $archetypes = array_values(array_diff((array) ($type['archetypes'] ?? []), ['product']));
            $onlySells  = $archetypes === [] && Product::isSellableType((string) $key);
            if ((string) ($type['public_slug'] ?? '') !== '' && !$onlySells) {
                $out[] = (string) $key;
            }
        }
        return $out;
    }

    public static function has(string $typeKey): bool
    {
        return in_array($typeKey, self::types(), true);
    }

    /** A type's feed, as a full address. */
    public static function url(string $typeKey): string
    {
        return Url::page((string) (Registry::get($typeKey)['public_slug'] ?? '') . '/feed.xml');
    }

    /**
     * The head's lines naming these types' feeds, those that have one.
     *
     * @param list<string> $typeKeys
     */
    public static function links(array $typeKeys): string
    {
        $h    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $site = (string) Head::globalTokens()['site_name'];
        $out  = '';
        foreach (array_unique($typeKeys) as $key) {
            if (!self::has((string) $key)) {
                continue;
            }
            $label = (string) (Registry::get((string) $key)['label'] ?? $key);
            $out  .= '<link rel="alternate" type="application/rss+xml" title="' . $h($site . ' · ' . $label) . '" href="'
                . $h(self::url((string) $key)) . '">' . "\n";
        }
        return $out;
    }

    /** The feed itself, as XML. */
    public static function rss(string $typeKey): string
    {
        $type   = Registry::get($typeKey) ?? [];
        $public = (string) ($type['public_slug'] ?? '');
        $x      = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $date   = static fn (string $utc): string => gmdate('D, d M Y H:i:s', (int) strtotime($utc . ' UTC')) . ' +0000';
        $site   = (string) Head::globalTokens()['site_name'];
        $label  = (string) ($type['label'] ?? $typeKey);

        $stmt = Database::getInstance()->prepare(
            'SELECT p.title, p.slug, p.content, p.excerpt, p.published_at FROM posts p
             WHERE p.post_type = :pt AND ' . Post::live('p') . '
             ORDER BY p.published_at DESC, p.id DESC LIMIT ' . self::ITEMS
        );
        $stmt->execute([':pt' => $typeKey]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = '';
        foreach ($rows as $row) {
            $link    = Url::page($public . '/' . (string) $row['slug']);
            $summary = Post::summary($row);
            $items  .= "    <item>\n"
                . '      <title>' . $x((string) $row['title']) . "</title>\n"
                . '      <link>' . $x($link) . "</link>\n"
                . '      <guid isPermaLink="true">' . $x($link) . "</guid>\n"
                . '      <pubDate>' . $date((string) $row['published_at']) . "</pubDate>\n"
                . '      <description>' . $x($summary['text'] . ($summary['cut'] ? '…' : '')) . "</description>\n"
                . "    </item>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
            . "  <channel>\n"
            . '    <title>' . $x($site . ' · ' . $label) . "</title>\n"
            . '    <link>' . $x(Url::page($public)) . "</link>\n"
            . '    <description>' . $x('The newest from ' . $site . ': ' . $label) . "</description>\n"
            . '    <language>' . $x(strtolower(\Cms\Site\Language::current())) . "</language>\n"
            . ($rows !== [] ? '    <lastBuildDate>' . $date((string) $rows[0]['published_at']) . "</lastBuildDate>\n" : '')
            . '    <atom:link href="' . $x(self::url($typeKey)) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . $items
            . "  </channel>\n"
            . "</rss>\n";
    }
}
