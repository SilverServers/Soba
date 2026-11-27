<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Paging;
use Cms\Helpers\Template;
use Cms\SEO\Head;
use Cms\SEO\Schema;
use PDO;

class HomeController
{
    public function index(array $vars = []): void
    {
        // `Helpers\Paging` — "Entries per page" applies to the front page's
        // feed as well as the archives.
        $pdo     = Database::getInstance();
        $perPage = Paging::perPage();
        $page    = Paging::current();
        $offset  = ($page - 1) * $perPage;

        // ── WHICH TYPES BELONG ON THE FRONT PAGE ─────────────────────────────
        //
        // Not `post_type = 'post'`, hard-coded. An install whose types are
        // `news` and `event` has no `post` Content Type, so the homepage would
        // list NOTHING, forever, and say "No posts published yet" on a site with
        // five published entries — while rendering perfectly and returning 200.
        //
        // THE ARCHETYPE ANSWERS THE QUESTION. `config/content-types.php`
        // describes `post` as "rich content in a feed" — which is precisely
        // what a homepage list is — so the front page shows every type carrying
        // it. That is a mechanism this system already has, not a new store, and
        // it means adding a second feed-shaped type puts it on the homepage
        // with no code change and no setting to remember.
        //
        // WITH NONE, EVERY PUBLIC TYPE IS LISTED rather than none. A brand-new
        // install whose author has not thought about archetypes yet should see
        // their content, not an empty page that looks broken.
        //
        // AND ONLY TYPES WHOSE ENTRIES HAVE PAGES (`Registry::hasPages()`): an
        // entry with no page of its own has nothing for its title to link to.
        $feedTypes = array_values(array_filter(array_keys(Registry::withArchetype('post')), static fn ($key): bool => Registry::hasPages((string) $key)));
        if ($feedTypes === []) {
            $feedTypes = array_keys(Registry::withPages());
        }

        $posts      = [];
        $total      = 0;
        $totalPages = 1;

        if ($feedTypes !== []) {
            // Placeholders, not an imploded string. `IN (:list)` with a
            // comma-joined value matches one literal containing commas, and
            // interpolating the keys would be string-concatenating input into
            // SQL, which nothing here does, even where the values come from the
            // Registry rather than a request.
            $marks = implode(',', array_fill(0, count($feedTypes), '?'));

            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM posts
                 WHERE post_type IN ($marks) AND " . \Cms\Models\Post::live()
            );
            $countStmt->execute($feedTypes);
            $total      = (int) $countStmt->fetchColumn();
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

            $stmt = $pdo->prepare(
                "SELECT p.*, " . \Cms\Models\User::bylineSql('u') . " AS author_name
                 FROM posts p
                 JOIN users u ON u.id = p.author_id
                 WHERE p.post_type IN ($marks) AND " . \Cms\Models\Post::live('p') . "
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT ? OFFSET ?"
            );
            foreach ($feedTypes as $i => $key) {
                $stmt->bindValue($i + 1, $key, PDO::PARAM_STR);
            }
            $stmt->bindValue(count($feedTypes) + 1, $perPage, PDO::PARAM_INT);
            $stmt->bindValue(count($feedTypes) + 2, $offset,  PDO::PARAM_INT);
            $stmt->execute();
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // PAST THE LAST PAGE IS A 404, as on every other paged list.
        if ($page > $totalPages) {
            Template::notFound();
            return;
        }

        // EACH ITEM LINKS THROUGH ITS OWN TYPE'S SLUG. One `$postPublicSlug` for
        // the whole list is wrong even with one type and a 404 per row with
        // two — the entry's own `post_type` is the only thing that knows where
        // it lives.
        foreach ($posts as &$row) {
            $row['public_slug'] = (string) Registry::publicSlug((string) $row['post_type']);
        }
        unset($row);
        // ── THE FRONT PAGE'S OWN WORDS, AND WHY THEY ARE A PAGE ─────────────
        //
        // A bare feed — a list of post titles and nothing else — leaves nothing
        // a visitor could point at: no heading, no opening line, nothing an
        // owner would ever ask to change, and nothing for the assistant to act
        // on when asked to "change the first thing I see" or "add exclamation
        // points to the opening message".
        //
        // The obvious fix is a hero in `home.php`, and it is the wrong one:
        // words hardcoded in a template are words the owner cannot change and
        // the assistant cannot find, so it would report text in plain sight as
        // not existing.
        //
        // So the front page's words are a PAGE — an existing mechanism, not a
        // new store. A page is already editable in the admin, already rendered,
        // already reachable by `read_page`, `find_text` and `view_page`. It is
        // optional: an install with no `home` page gets the feed alone.
        $homeStmt = $pdo->prepare(
            "SELECT id, title, content FROM pages
             WHERE slug = :slug AND " . \Cms\Models\Page::live() . " LIMIT 1"
        );
        // The one name for that page, which its address is written from too.
        $homeStmt->execute([':slug' => \Cms\Helpers\Url::FRONT_PAGE]);
        $intro = $homeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $navPages        = [];
        $pageTitle       = 'Home';

        // SEO
        $tokens = Head::globalTokens();
        if ($page > 1) {
            $tokens['page_num'] = \Cms\Site\Words::of('page_n', ['n' => $page]);
        }
        $title = Head::buildTitle(Head::titlePattern('home'), $tokens);

        // THE HOMEPAGE'S OWN TITLE AND DESCRIPTION, both collected on the SEO
        // settings screen. Without them the homepage's title would come only
        // from the `home` PATTERN, with no way to override it.
        //
        // The override WINS over the pattern when it is set, and falls back to
        // the pattern when it is blank — which is what "Homepage title" means to
        // whoever typed it, and what the blank-is-default hint on that screen
        // says.
        //
        // BOTH ARE EDITED AS PILLS, so their variables resolve
        // against the home page's own tokens. The title box starts from the Home
        // pattern and is stored empty while it still says the same
        // (`Head::ownTitle()`), so an empty option still means "the pattern".
        $homeTitle = trim((string) (\Cms\Models\Option::get('seo_homepage_title', '') ?? ''));
        $homeDesc  = Head::buildTitle(trim((string) (\Cms\Models\Option::get('seo_homepage_description', '') ?? '')), $tokens);

        // PAGE 2 OF THE FRONT PAGE NAMES ITSELF, and its neighbours, rather than
        // every page canonicalising to `/` as a duplicate of the first.
        $pageBase = \Cms\Helpers\Url::home();
        $paged    = Paging::head($pageBase, $page, $totalPages);

        $graph = Schema::siteWide();
        Head::set([
            // THE OWNER'S OWN HOMEPAGE TITLE is the front page's, which is page
            // 1; page 2 and on take the pattern, which carries the page number.
            'title'       => $homeTitle !== '' && $page === 1 ? Head::buildTitle($homeTitle, $tokens) : $title,
            'description' => $homeDesc,
            'canonical'   => $paged['canonical'],
            // The front page is in both languages; a later page of its list, in its own.
            'alternates'  => $page === 1 ? \Cms\Site\Language::versions(static fn (string $lang): string => \Cms\Helpers\Url::home($lang)) : [],
            'prev'        => $paged['prev'],
            'next'        => $paged['next'],
            'og_type'     => 'website',
            'schema_json' => Schema::encode($graph),
            'feeds'       => \Cms\Content\Feed::types(),
        ]);

        ob_start();
        require Template::locate('home.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }
}
