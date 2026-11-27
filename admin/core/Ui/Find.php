<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\ContentType\TaxonomyRegistry;
use Cms\Database\Database;
use Cms\Helpers\Url;
use PDO;

/**
 * FIND ANYTHING BY ITS NAME, from the top bar: staff know what they want by
 * its name, an event, a person, a page, rather than by its type, and each
 * list searches its own type alone.
 *
 * What the account may open, and nothing else:
 *   screens  the admin's own, as the account's column names them
 *            (`AdminMenu::sidebarSpec()`), so a screen it would be turned
 *            away from is never offered, and a site's arranged column
 *            (`Site\Column`) names them in its own words
 *   entries  of every type, by title; an author's own alone, as
 *            `Auth::canChange()` lets an author change only theirs
 *   pages    by title, for whoever opens Pages
 *   terms    by name, for an editor and up, who edit them
 * each with its kind, an entry's its type's name, best first: a name that
 * starts with what was typed, then one with a word that does, then the rest.
 */
final class Find
{
    /** The most a search offers. */
    public const LIMIT = 12;

    /** What a search needs before it looks. */
    public const MIN = 2;

    /**
     * What the signed-in account may open whose name holds `$q`, best first.
     *
     * @return list<array{name: string, kind: string, href: string}>
     */
    public static function results(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < self::MIN || !Auth::check()) {
            return [];
        }
        $found = [...self::screens($q), ...self::entries($q), ...self::pages($q), ...self::terms($q)];
        foreach ($found as $i => $row) {
            $found[$i]['rank'] = self::rank($row['name'], $q);
            $found[$i]['at']   = $i;
        }
        usort($found, static fn (array $a, array $b): int => [$a['rank'], $a['at']] <=> [$b['rank'], $b['at']]);

        return array_map(static fn (array $r): array => ['name' => $r['name'], 'kind' => $r['kind'], 'href' => $r['href']],
            array_slice($found, 0, self::LIMIT));
    }

    /** 0 when the name starts with `$q`, 1 when a word of it does, 2 when it holds it elsewhere. */
    private static function rank(string $name, string $q): int
    {
        $name = mb_strtolower($name);
        $q    = mb_strtolower($q);

        return str_starts_with($name, $q) ? 0 : (preg_match('/(?:^|[\s\p{P}])' . preg_quote($q, '/') . '/u', $name) === 1 ? 1 : 2);
    }

    /**
     * The admin's own screens as the account's column names them: a row in a
     * group by the group and the row ("Settings › Words"), once each.
     *
     * @return list<array{name: string, kind: string, href: string}>
     */
    private static function screens(string $q): array
    {
        $out  = [];
        $seen = [];
        foreach ((array) (AdminMenu::sidebarSpec('/admin/find')['nav'] ?? []) as $section) {
            $group = (string) ($section['label'] ?? '');
            foreach ((array) ($section['items'] ?? []) as $item) {
                $label = (string) ($item['full'] ?? $item['label'] ?? '');
                $name  = $group !== '' && $group !== $label && !str_contains($label, $group) ? $group . ' › ' . $label : $label;
                $href  = (string) ($item['href'] ?? '');
                if ($href === '' || isset($seen[$href]) || mb_stripos($name, $q) === false) {
                    continue;
                }
                $seen[$href] = true;
                $out[]       = ['name' => $name, 'kind' => 'Screen', 'href' => $href];
            }
        }

        return $out;
    }

    /**
     * Entries of every registered type by title, those an author may change
     * alone for an author (`Auth::canChange()`), each named by its type.
     *
     * @return list<array{name: string, kind: string, href: string}>
     */
    private static function entries(string $q): array
    {
        $types = Registry::all();
        if ($types === []) {
            return [];
        }
        $keys   = array_keys($types);
        $params = array_merge([self::like($q)], array_map('strval', $keys));
        $mine   = '';
        if (!Auth::hasRole('editor')) {
            $mine     = ' AND author_id = ?';
            $params[] = (int) (Auth::user()['id'] ?? 0);
        }
        $stmt = Database::getInstance()->prepare(
            "SELECT id, post_type, title FROM posts
              WHERE title LIKE ? ESCAPE '!' AND status <> 'trash'
                AND post_type IN (" . implode(', ', array_fill(0, count($keys), '?')) . ")$mine
           ORDER BY updated_at DESC, id DESC LIMIT " . (self::LIMIT * 2)
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type  = $types[(string) $row['post_type']] ?? null;
            if ($type === null) {
                continue;
            }
            $out[] = ['name' => (string) $row['title'], 'kind' => (string) $type['label_single'],
                      'href' => Url::admin(trim((string) $type['admin_slug'], '/') . '/' . (int) $row['id'] . '/edit')];
        }

        return $out;
    }

    /**
     * Pages by title, for whoever opens Pages (`AdminMenu::admits('pages')`).
     *
     * @return list<array{name: string, kind: string, href: string}>
     */
    private static function pages(string $q): array
    {
        if (!AdminMenu::admits('pages')) {
            return [];
        }
        $stmt = Database::getInstance()->prepare(
            "SELECT id, title FROM pages WHERE title LIKE ? ESCAPE '!' AND status <> 'trash' ORDER BY updated_at DESC, id DESC LIMIT " . self::LIMIT
        );
        $stmt->execute([self::like($q)]);

        return array_map(static fn (array $row): array => ['name' => (string) $row['title'], 'kind' => 'Page',
            'href' => Url::admin('pages/' . (int) $row['id'] . '/edit')], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Terms by name, of every registered taxonomy, for an editor and up, whose
     * the terms screens are; each named by its taxonomy.
     *
     * @return list<array{name: string, kind: string, href: string}>
     */
    private static function terms(string $q): array
    {
        $taxonomies = TaxonomyRegistry::all();
        if (!Auth::hasRole('editor') || $taxonomies === []) {
            return [];
        }
        $keys = array_keys($taxonomies);
        $stmt = Database::getInstance()->prepare(
            "SELECT id, taxonomy_key, name FROM terms WHERE name LIKE ? ESCAPE '!'
                AND taxonomy_key IN (" . implode(', ', array_fill(0, count($keys), '?')) . ")
           ORDER BY name ASC LIMIT " . self::LIMIT
        );
        $stmt->execute(array_merge([self::like($q)], array_map('strval', $keys)));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tax   = $taxonomies[(string) $row['taxonomy_key']];
            $out[] = ['name' => (string) $row['name'], 'kind' => (string) $tax['label_single'],
                      'href' => Url::admin('taxonomies/' . trim((string) $tax['admin_slug'], '/') . '/terms/' . (int) $row['id'] . '/edit')];
        }

        return $out;
    }

    /** `%q%` for LIKE, its own % and _ taken as typed. */
    private static function like(string $q): string
    {
        return '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
