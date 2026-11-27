<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Models\Meta;
use Cms\Models\Page;
use Cms\Models\Post;
use Cms\Models\Term;
use Cms\Site\Language;

/**
 * A PAGE OR AN ENTRY IN THE SECOND LANGUAGE (`Site\Language`): a row like any
 * other, in that language (`lang`) and linked to the row it translates
 * (`translation_of`). Its revisions, share link, editing lock and unpublish
 * date are its own, as any row's are.
 *
 * TRANSLATE MAKES ONE: a Private copy at the original's slug under the
 * language's prefix (/fr/about), with the original's title, words, picture,
 * search text, date, fields and terms (the terms are shared, not translated),
 * under the translation of the original's parent when that parent has one,
 * and in no menu. The words are then the translator's to change. Deleting the
 * original leaves the translation, unlinked; each keeps its own status.
 *
 * A TYPE THAT SELLS AND PUBLISHES TOO — events with tickets: the `product`
 * archetype beside `post` or `event` — is translated, and its translation
 * sells the original's product (`Commerce\Product::fromPost()`): one code,
 * one price, one stock count. The translation keeps no product field or
 * option of its own, and the product feed, the stock screen and the reports
 * never list it. A type that only sells has none: the shop is in the site's
 * own language, and so are its products.
 */
final class Translation
{
    /** Can a type's entries be translated? A registered type that does not sell, or one that publishes too. */
    public static function translates(string $typeKey): bool
    {
        return Registry::get($typeKey) !== null
            && (!\Cms\Commerce\Product::isSellableType($typeKey) || self::sellsAndPublishes($typeKey));
    }

    /** A type that sells and publishes too: the `product` archetype beside `post` or `event`. */
    public static function sellsAndPublishes(string $typeKey): bool
    {
        $archetypes = (array) ((Registry::get($typeKey) ?? [])['archetypes'] ?? []);

        return \Cms\Commerce\Product::isSellableType($typeKey) && array_intersect($archetypes, ['post', 'event']) !== [];
    }

    /**
     * Is this entry a translation of one that sells? Then it sells its
     * original's product, and has no product field or option of its own.
     *
     * @param array<string, mixed> $post
     */
    public static function sellsOriginal(array $post): bool
    {
        return (string) ($post['lang'] ?? '') !== '' && (int) ($post['translation_of'] ?? 0) > 0
            && \Cms\Commerce\Product::isSellableType((string) ($post['post_type'] ?? ''));
    }

    /**
     * Has a taxonomy term pages in the second language? When one of the
     * types it files is translated: its terms are shared, and a term's page
     * lists the entries in the page's language.
     */
    public static function taxonomyTranslates(string $taxonomyKey): bool
    {
        foreach ((array) ((\Cms\ContentType\TaxonomyRegistry::get($taxonomyKey) ?? [])['content_types'] ?? []) as $typeKey) {
            if (self::translates((string) $typeKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The translation of a page or an entry into `$lang`, or null.
     *
     * @param 'pages'|'posts' $table
     * @return array<string, mixed>|null
     */
    public static function of(string $table, int $id, string $lang): ?array
    {
        if ($lang === '' || $id < 1) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM ' . self::table($table) . ' WHERE translation_of = :id AND ' . Post::inLanguage('', $lang) . ' LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * The row a translation translates, or null: for an original, and for a
     * translation whose original was deleted.
     *
     * @param 'pages'|'posts'      $table
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function original(string $table, array $row): ?array
    {
        $of = (int) ($row['translation_of'] ?? 0);
        if ($of < 1) {
            return null;
        }

        return self::table($table) === 'pages' ? Page::find($of) : Post::find($of);
    }

    /**
     * Why `$row` cannot be translated now, or null when it can: there must be
     * a second language, the row must be in the site's own language and not
     * in the trash, and an entry's type must not only sell.
     *
     * @param 'pages'|'posts'      $table
     * @param array<string, mixed> $row
     */
    public static function problem(string $table, array $row): ?string
    {
        if (Language::prefix() === '') {
            return 'Choose a second language on Settings → General first.';
        }
        if ((string) ($row['lang'] ?? '') !== '') {
            return 'This is already a translation.';
        }
        if ((string) ($row['status'] ?? '') === 'trash') {
            return 'Restore it from the trash first.';
        }
        if (self::table($table) === 'posts' && Registry::get((string) ($row['post_type'] ?? '')) === null) {
            return 'Its type is not one this site has.';
        }
        if (self::table($table) === 'posts' && !self::translates((string) ($row['post_type'] ?? ''))) {
            return 'What the shop sells stays in the site\'s own language.';
        }

        return null;
    }

    /**
     * A PAGE'S TRANSLATION into the second language: the one it has, or one
     * made now. Its id.
     *
     * @param array<string, mixed> $page a row as `Page::find()` returns it
     */
    public static function page(array $page, int $authorId): int
    {
        $why = self::problem('pages', $page);
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }
        $lang = Language::prefix();
        $have = self::of('pages', (int) $page['id'], $lang);
        if ($have !== null) {
            return (int) $have['id'];
        }
        $parent = !empty($page['parent_id']) ? self::of('pages', (int) $page['parent_id'], $lang) : null;

        return Page::create([
            'parent_id'         => $parent !== null ? (int) $parent['id'] : null,
            'title'             => (string) $page['title'],
            'slug'              => self::slug('pages', (string) $page['slug'], $lang),
            'lang'              => $lang,
            'translation_of'    => (int) $page['id'],
            'content'           => (string) ($page['content'] ?? ''),
            'menu_order'        => (int) ($page['menu_order'] ?? 0),
            'status'            => 'private',
            'featured_image_id' => !empty($page['featured_image_id']) ? (int) $page['featured_image_id'] : null,
            'seo_title'         => (string) ($page['seo_title'] ?? ''),
            'meta_description'  => (string) ($page['meta_description'] ?? ''),
            'author_id'         => $authorId,
        ]);
    }

    /**
     * AN ENTRY'S TRANSLATION into the second language: the one it has, or one
     * made now, with the entry's fields and terms. Its id.
     *
     * @param array<string, mixed> $post a row as `Post::find()` returns it
     */
    public static function entry(array $post, int $authorId): int
    {
        $why = self::problem('posts', $post);
        if ($why !== null) {
            throw new \InvalidArgumentException($why);
        }
        $lang = Language::prefix();
        $have = self::of('posts', (int) $post['id'], $lang);
        if ($have !== null) {
            return (int) $have['id'];
        }

        $id = Post::create([
            'post_type'         => (string) $post['post_type'],
            'title'             => (string) $post['title'],
            'slug'              => self::slug('posts', (string) $post['slug'], $lang),
            'lang'              => $lang,
            'translation_of'    => (int) $post['id'],
            'content'           => (string) ($post['content'] ?? ''),
            'excerpt'           => $post['excerpt'] ?? null,
            'status'            => 'private',
            // The story's own date: published, the translation is listed
            // where the original is, not as the newest.
            'published_at'      => $post['published_at'] ?? null,
            'featured_image_id' => $post['featured_image_id'] ?? null,
            'menu_order'        => (int) ($post['menu_order'] ?? 0),
            'author_id'         => $authorId,
        ]);
        if (!empty($post['parent_id'])) {
            $parent = self::of('posts', (int) $post['parent_id'], $lang);
            if ($parent !== null) {
                Post::setParent($id, (int) $parent['id']);
            }
        }
        // WHAT SELLS STAYS THE ORIGINAL'S: no product field (its price, code,
        // stock and the rest, all `product_…`) and no option taxonomy's terms.
        $type  = (string) $post['post_type'];
        $sells = \Cms\Commerce\Product::isSellableType($type);
        foreach (Meta::getAll((int) $post['id']) as $key => $value) {
            if ($sells && str_starts_with((string) $key, 'product_')) {
                continue;
            }
            Meta::set($id, (string) $key, $value);
        }
        $options = $sells ? array_keys(\Cms\Commerce\Variation::optionTaxonomies($type)) : [];
        foreach (array_keys(\Cms\ContentType\TaxonomyRegistry::getForContentType($type)) as $taxKey) {
            if (in_array($taxKey, $options, true)) {
                continue;
            }
            Term::setPostTerms($id, (string) $taxKey, array_map(
                static fn (array $t): int => (int) $t['id'],
                Term::forPosts([(int) $post['id']], [(string) $taxKey])[(int) $post['id']][(string) $taxKey] ?? []
            ));
        }

        return $id;
    }

    /**
     * A TRANSLATION LINKED TO ITS ORIGINAL, found by the original's slug in
     * the site's own language: an import's, whose file names originals by
     * slug, their ids being another install's. Null once linked; otherwise
     * why not: it is not a translation, there is no such original here (an
     * entry's of its own type), or the original has a translation in that
     * language already.
     *
     * @param 'pages'|'posts' $table
     */
    public static function link(string $table, int $id, string $originalSlug): ?string
    {
        $table = self::table($table);
        $row   = $table === 'pages' ? Page::find($id) : Post::find($id);
        if ($row === null || (string) $row['lang'] === '') {
            return 'it is not a translation';
        }
        $original = $table === 'pages' ? Page::findBySlug($originalSlug, '') : Post::findBySlug($originalSlug, '');
        if ($original === null || ($table === 'posts' && (string) $original['post_type'] !== (string) $row['post_type'])) {
            return 'there is no such ' . ($table === 'pages' ? 'page' : 'entry') . ' here';
        }
        $have = self::of($table, (int) $original['id'], (string) $row['lang']);
        if ($have !== null && (int) $have['id'] !== $id) {
            return 'it has a translation already';
        }
        Database::getInstance()
            ->prepare('UPDATE ' . $table . ' SET translation_of = :of WHERE id = :id')
            ->execute([':of' => (int) $original['id'], ':id' => $id]);

        return null;
    }

    /**
     * A PAGE'S OR AN ENTRY'S VERSIONS, as `Site\Language::versions()` gives a
     * list's: each language's tag => the address of the version in it, the
     * site's own first, when the original and its translation are both on
     * the site (published, their dates come and not gone); empty otherwise.
     *
     * @param 'pages'|'posts'                       $table
     * @param array<string, mixed>                  $row
     * @param callable(array<string, mixed>): string $url the address of a row of its kind
     * @return array<string, string>
     */
    public static function versions(string $table, array $row, callable $url): array
    {
        $second = Language::prefix();
        $lang   = (string) ($row['lang'] ?? '');
        if ($second === '' || ($lang !== '' && $lang !== $second)) {
            return [];
        }
        $original    = $lang === '' ? $row : self::original($table, $row);
        $translation = $lang === '' ? self::of($table, (int) ($row['id'] ?? 0), $second) : $row;
        if ($original === null || $translation === null || !self::onSite($original) || !self::onSite($translation)) {
            return [];
        }

        return [Language::own() => $url($original), Language::second() => $url($translation)];
    }

    /** On the site: published, its date come and its unpublish date not gone (`Post::onSite()`; a page has no Sample). */
    private static function onSite(array $row): bool
    {
        return Post::onSite($row);
    }

    /** The original's slug in `$lang`, or the next free one there. */
    private static function slug(string $table, string $slug, string $lang): string
    {
        if (self::table($table) === 'pages') {
            return Page::slugExists($slug, null, $lang) || in_array($slug, \Cms\Helpers\Slug::reservedFirstSegments(), true)
                ? Page::generateSlug($slug, null, $lang)
                : $slug;
        }

        return Post::slugExists($slug, null, $lang) ? Post::generateSlug($slug, null, $lang) : $slug;
    }

    /** One of the two tables a translation can be in. */
    private static function table(string $table): string
    {
        if (!in_array($table, ['pages', 'posts'], true)) {
            throw new \InvalidArgumentException('Only a page or an entry has a translation.');
        }

        return $table;
    }
}
