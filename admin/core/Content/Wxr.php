<?php

declare(strict_types=1);

namespace Cms\Content;

/**
 * A WORDPRESS EXPORT FILE, READ: the `.xml` that WordPress's Tools → Export
 * writes (WXR, "WordPress eXtended RSS"), versions 1.0 to 1.2.
 *
 * READ AS A STREAM, an item at a time. An export of a site that has written
 * for ten years is tens of megabytes of HTML, and a host's memory limit is not
 * a size a migration should have to fit in: `scan()` hands each item to its
 * caller and keeps nothing of it.
 *
 * NOTHING IN THE FILE IS LOOKED UP ELSEWHERE. A document with a DOCTYPE is
 * refused before it is parsed (WordPress never writes one, and it is where an
 * entity that expands to gigabytes, or one that reads a file of this server's,
 * would be declared), and the parser loads nothing from the network.
 *
 * What the file means here, and what is done with it, is `WordPressImport`'s.
 */
final class Wxr
{
    /** The first bytes say whether a file is an export, before it is parsed. */
    public static function is(string $head): bool
    {
        $head = (string) preg_replace('/^\xEF\xBB\xBF/', '', $head);

        return preg_match('#^\s*(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<rss\b#s', $head) === 1
            && preg_match('#xmlns:wp\s*=\s*["\']https?://wordpress\.org/export/1\.\d+/["\']#', $head) === 1;
    }

    /**
     * Read the export, handing each `<item>` to `$onItem` as it is reached,
     * and return what the file says about the site: its name and address,
     * and its categories and tags.
     *
     * An item: `id`, `type` (WordPress's post type), `title`, `slug`,
     * `status`, `content`, `excerpt`, `date` and `date_gmt` (as the export
     * writes them, `Y-m-d H:i:s`), `parent` (a WordPress id), `order`, `link`
     * (its address there), `password`, `url` (an attachment's file), `terms`
     * (`domain`, `slug`, `name`), `meta` (key => value), `comments` (how many).
     *
     * WordPress writes its categories and tags before its items, so with
     * `$siteOnly` the reading stops at the first item: what the site says,
     * without reading its content.
     *
     * @param  callable(array<string, mixed>): void $onItem
     * @return array{title: string, url: string, blog: string, version: string,
     *               categories: array<string, array{name: string, parent: string, description: string}>,
     *               tags: array<string, array{name: string, description: string}>, items: int}
     * @throws \RuntimeException the file is not an export WordPress wrote, said in a sentence
     */
    public static function scan(string $path, callable $onItem, bool $siteOnly = false): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8192);
        if (!self::is($head)) {
            throw new \RuntimeException('That is not a WordPress export. WordPress writes one at Tools → Export, as an .xml file.');
        }
        if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
            throw new \RuntimeException('That export declares a DOCTYPE, which WordPress never writes, so it is not read.');
        }

        $reader = new \XMLReader();
        $old    = libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (!$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($old);
            throw new \RuntimeException('That export could not be opened.');
        }

        $site = ['title' => '', 'url' => '', 'blog' => '', 'version' => '', 'categories' => [], 'tags' => [], 'items' => 0];
        try {
            $depth = null;   // the channel's depth: its own children are the site's facts
            // AFTER `next()` THE READER IS ON THE NEXT SIBLING ALREADY: reading
            // again would step over it, which an export with no space between
            // two elements would lose an element to.
            $moved = false;
            while ($moved || self::advance($reader)) {
                $moved = false;
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'channel' && $depth === null) {
                    $depth = $reader->depth;
                    continue;
                }
                if ($depth === null || $reader->depth !== $depth + 1) {
                    continue;
                }
                $name = $reader->localName;
                $wp   = self::isWp((string) $reader->namespaceURI);
                if ($name === 'item' && $reader->namespaceURI === '') {
                    if ($siteOnly) {
                        break;
                    }
                    $node = $reader->expand();
                    if ($node instanceof \DOMElement) {
                        $site['items']++;
                        $onItem(self::item($node));
                    }
                    $moved = $reader->next();
                    continue;
                }
                if (!in_array($name, ['title', 'base_site_url', 'base_blog_url', 'wxr_version', 'category', 'tag', 'link'], true)) {
                    continue;
                }
                $node = $reader->expand();
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                if (!$wp && $name === 'title') {
                    $site['title'] = trim($node->textContent);
                } elseif (!$wp && $name === 'link' && $site['url'] === '') {
                    $site['url'] = trim($node->textContent);
                } elseif ($wp && $name === 'base_site_url') {
                    $site['url'] = trim($node->textContent);
                } elseif ($wp && $name === 'base_blog_url') {
                    $site['blog'] = trim($node->textContent);
                } elseif ($wp && $name === 'wxr_version') {
                    $site['version'] = trim($node->textContent);
                } elseif ($wp && $name === 'category') {
                    $slug = self::wp($node, 'category_nicename');
                    if ($slug !== '') {
                        $site['categories'][$slug] = [
                            'name'        => self::wp($node, 'cat_name') !== '' ? self::wp($node, 'cat_name') : $slug,
                            'parent'      => self::wp($node, 'category_parent'),
                            'description' => self::wp($node, 'category_description'),
                        ];
                    }
                } elseif ($wp && $name === 'tag') {
                    $slug = self::wp($node, 'tag_slug');
                    if ($slug !== '') {
                        $site['tags'][$slug] = [
                            'name'        => self::wp($node, 'tag_name') !== '' ? self::wp($node, 'tag_name') : $slug,
                            'description' => self::wp($node, 'tag_description'),
                        ];
                    }
                }
                $moved = $reader->next();
            }
            $errors = libxml_get_errors();
            $fatal  = array_values(array_filter($errors, static fn (\LibXMLError $e): bool => $e->level >= LIBXML_ERR_ERROR));
            if ($fatal !== []) {
                throw new \RuntimeException(sprintf('The export is not whole: line %d says "%s". Export it again from WordPress.',
                    $fatal[0]->line, trim($fatal[0]->message)));
            }
            if ($depth === null) {
                throw new \RuntimeException('That export has no channel, so nothing in it can be read.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        return $site;
    }

    /** `read()` that stops on a file cut short instead of looping on it. */
    private static function advance(\XMLReader $reader): bool
    {
        $ok   = @$reader->read();
        $last = libxml_get_last_error();
        return $ok && !($last instanceof \LibXMLError && $last->level === LIBXML_ERR_FATAL);
    }

    /** @return array<string, mixed> */
    private static function item(\DOMElement $node): array
    {
        $out = [
            'id' => 0, 'type' => '', 'title' => '', 'slug' => '', 'status' => '', 'content' => '', 'excerpt' => '',
            'date' => '', 'date_gmt' => '', 'parent' => 0, 'order' => 0, 'link' => '', 'password' => '', 'url' => '',
            'terms' => [], 'meta' => [], 'comments' => 0,
        ];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $ns   = (string) $child->namespaceURI;
            $name = $child->localName;
            $text = $child->textContent;
            if ($ns === '') {
                match ($name) {
                    'title'    => $out['title'] = trim($text),
                    'link'     => $out['link'] = trim($text),
                    'category' => $out['terms'][] = [
                        'domain' => $child->getAttribute('domain'),
                        'slug'   => trim($child->getAttribute('nicename')),
                        'name'   => trim($text),
                    ],
                    default    => null,
                };
                continue;
            }
            if (preg_match('#^https?://purl\.org/rss/1\.0/modules/content/$#', $ns) === 1 && $name === 'encoded') {
                $out['content'] = $text;
            } elseif (preg_match('#^https?://wordpress\.org/export/1\.\d+/excerpt/$#', $ns) === 1 && $name === 'encoded') {
                $out['excerpt'] = $text;
            } elseif (self::isWp($ns)) {
                match ($name) {
                    'post_id'        => $out['id'] = (int) $text,
                    'post_type'      => $out['type'] = trim($text),
                    'post_name'      => $out['slug'] = trim($text),
                    'status'         => $out['status'] = trim($text),
                    'post_date'      => $out['date'] = trim($text),
                    'post_date_gmt'  => $out['date_gmt'] = trim($text),
                    'post_parent'    => $out['parent'] = (int) $text,
                    'menu_order'     => $out['order'] = (int) $text,
                    'post_password'  => $out['password'] = $text,
                    'attachment_url' => $out['url'] = trim($text),
                    'postmeta'       => $out['meta'][self::wp($child, 'meta_key')] = self::wp($child, 'meta_value'),
                    'comment'        => $out['comments']++,
                    default          => null,
                };
            }
        }
        unset($out['meta']['']);

        return $out;
    }

    private static function isWp(string $ns): bool
    {
        return preg_match('#^https?://wordpress\.org/export/1\.\d+/$#', $ns) === 1;
    }

    /** The text of a `wp:` child of `$node`, or ''. */
    private static function wp(\DOMElement $node, string $name): string
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name && self::isWp((string) $child->namespaceURI)) {
                return trim($child->textContent);
            }
        }
        return '';
    }
}
