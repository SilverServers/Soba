<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Auth\Auth;
use Cms\Helpers\Template;
use Cms\Models\Page;
use Cms\Models\Meta;
use Cms\SEO\Head;
use Cms\SEO\Schema;
use Cms\SEO\Breadcrumbs;

class PageController
{
    public function show(array $vars = []): void
    {
        $page = Page::findBySlug($vars['slug']);

        if ($page === null) {
            Template::notFound();
            return;
        }

        // Private: require login
        // ENDED, its unpublish date come, is private to a visitor. Either opens
        // for whoever has its share link while that works (`Content\Share`).
        if (($page['status'] === 'private' || \Cms\Models\Post::hasEnded($page)) && !Auth::check()
            && !\Cms\Content\Share::grants('page', (int) $page['id'])) {
            Template::notFound();
            return;
        }

        // The page exists — so the other ending of its address 301s here now, and
        // not before: a 404 stays a 404 (`Url::settle()`).
        \Cms\Helpers\Url::settle();
        // Edit this page, for a signed-in editor (`Site\EditBar`).
        \Cms\Site\EditBar::forPage($page);

        // Password protected: check session or POST
        if ($page['status'] === 'password') {
            $sessionKey = 'password_unlocked_' . $page['id'];
            if (!empty($_POST['content_password'])) {
                if (password_verify($_POST['content_password'], $page['password_hash'] ?? '')) {
                    \Cms\Security\Session::ensure();   // a visitor's first session: the page is unlocked for it
                    $_SESSION[$sessionKey] = true;
                } else {
                    $passwordError = true;
                }
            }
            if (empty($_SESSION[$sessionKey])) {
                $pageTitle = $page['title'];

                // THE GATE HAS A TITLE AND IS NOT INDEXABLE. `base.php` builds
                // its <head> from `Head`, so without `Head::set()` on this path
                // a password-protected page would render `<title></title>`: a
                // blank browser tab, a blank bookmark, and a blank entry in
                // every history list.
                //
                // `noindex` matters more. Without it the default is
                // `index, follow`, so a search engine is invited to index the
                // LOCK SCREEN of protected content — putting the page's title
                // into public results for a page whose whole point is that it is
                // not public. Also `nofollow`: there is nothing behind the form
                // for a crawler to reach, and the links in the site chrome are
                // reachable from every other page anyway.
                \Cms\SEO\Head::set([
                    'title'     => $page['title'],
                    'robots'    => 'noindex, nofollow',
                    'canonical' => \Cms\Helpers\Url::pageOf($page),
                ]);

                ob_start();
                require Template::locate('password-form.php');
                $pageContent = ob_get_clean();
                require Template::locate('base.php');
                return;
            }
        }

        $navPages  = [];
        $pageTitle = $page['title'];

        // SEO
        $tokens = Head::globalTokens();
        $tokens['title'] = $page['title'];
        $title = Head::buildTitle(Head::titlePattern('page'), $tokens);

        $canonical = \Cms\Helpers\Url::pageOf($page);

        // THE PAGE'S OWN TITLE AND DESCRIPTION, variables and all. The Search
        // engines panel edits both as pills, so `{title}` or
        // `{sep}` typed there resolves here exactly as the pattern's do; an
        // empty title is one left on the pattern (`Head::ownTitle()`).
        $seoTitle = trim((string) ($page['seo_title'] ?? ''));
        $seoDesc  = Head::buildTitle(trim((string) ($page['meta_description'] ?? '')), $tokens);
        // AN EMPTY DESCRIPTION IS THE PAGE'S SUMMARY, as an entry's is its
        // excerpt (`Head::forEntry()`): prose, so its braces stay as written.
        if ($seoDesc === '') {
            $seoDesc = trim((string) ($page['excerpt'] ?? ''));
        }

        // THE FEATURED IMAGE IS THE SOCIAL PREVIEW, exactly as it is for an
        // entry (PostController::show). It was collected by the Pages form,
        // stored on the row, and then read by nothing at all — so the admin
        // shipped a picker whose only effect was a sentence underneath it
        // saying it had no effect. `Head` has supported `og_image` since it was
        // written; only the call site was missing.
        $ogImage = '';
        if (!empty($page['featured_image_id'])) {
            $ogImage = \Cms\Models\Media::getUrl((int) $page['featured_image_id']);
        }

        $breadcrumbItems = [
            ['label' => \Cms\Site\Words::of('home'), 'url' => \Cms\Helpers\Url::home()],
            ['label' => $page['title'], 'url' => $canonical],
        ];
        Breadcrumbs::set($breadcrumbItems);

        $graph   = Schema::siteWide();
        $graph[] = Schema::forEntry('WebPage', $page, [], ['url' => $canonical, 'description' => (string) $seoDesc]);
        $graph[] = Schema::breadcrumbList($breadcrumbItems);

        Head::set([
            'title'       => $seoTitle !== '' ? Head::buildTitle($seoTitle, $tokens) : $title,
            'description' => $seoDesc,
            'canonical'   => $canonical,
            // The page and its translation, both on the site (`Content\Translation`).
            'alternates'  => \Cms\Content\Translation::versions('pages', $page, static fn (array $row): string => \Cms\Helpers\Url::pageOf($row)),
            'og_image'    => $ogImage,
            'og_type'     => 'website',
            'schema_json' => Schema::encode($graph),
        ]);

        ob_start();
        require Template::locate('page.php');
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }
}
