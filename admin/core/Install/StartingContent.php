<?php

declare(strict_types=1);

namespace Cms\Install;

use Cms\ContentType\ArchetypeDefaults;
use Cms\Database\Database;
use Cms\Models\ContentTypeDefinition;
use Cms\Models\Page;
use Cms\Models\Post;

/**
 * A NEW SITE'S FIRST CONTENT: a Posts type with one post, and an About
 * page, so neither the front page nor the admin opens empty. Each is edited
 * or deleted like anything else. The type is made as the Content Types
 * screen makes one with the post archetype, through the same registration as
 * every other type; the post and the page through their models.
 *
 * Made once, when a site is set up: by the browser set-up (`Installer`), and
 * by `migrate.php run` when it gives a new site's first account its
 * password. Never on an update, and never where the site has content of any
 * kind.
 */
final class StartingContent
{
    /** Make it, by `$authorId`: false, and nothing made, when the site has content already. */
    public static function create(int $authorId): bool
    {
        $pdo = Database::getInstance();
        if ((int) $pdo->query('SELECT COUNT(*) FROM content_types')->fetchColumn() > 0
            || (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn() > 0
            || (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn() > 0) {
            return false;
        }

        ContentTypeDefinition::create([
            'type_key'       => 'post',
            'plural_label'   => 'Posts',
            'singular_label' => 'Post',
            'admin_slug'     => 'posts',
            'public_slug'    => 'posts',
            'menu_icon'      => ArchetypeDefaults::glyphs()['post'],
            'supports'       => ArchetypeDefaults::getSupports('post'),
            'archetypes'     => ['post'],
        ]);
        Post::create([
            'post_type' => 'post',
            'title'     => 'Hello world!',
            'slug'      => 'hello-world',
            'content'   => '<p>This is the site\'s first post. Edit it or delete it, then start writing.</p>',
            'status'    => 'published',
            'author_id' => $authorId,
        ]);
        Page::create([
            'title'     => 'About',
            'slug'      => 'about',
            'content'   => '<p>This is a page. A page holds what stays put, such as who you are and how to reach you,'
                . ' and is not one of the posts. Edit it or delete it.</p>',
            'status'    => 'published',
            'author_id' => $authorId,
        ]);
        // And the dashboard's next steps, until an administrator hides them.
        \Cms\Ui\Welcome::begin();

        return true;
    }
}
