<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Auth\Auth;
use Cms\ContentType\Registry;
use Cms\Helpers\Template;
use Cms\Helpers\Theme;
use Cms\Models\Post;
use Cms\Models\Meta;
use Cms\SEO\Head;
use Cms\SEO\Schema;
use Cms\SEO\Breadcrumbs;

class PostController
{
    private string $postTypeKey;

    public function __construct(?string $postTypeKey = null)
    {
        $this->postTypeKey = $postTypeKey ?? 'post';
    }

    public function show(array $vars = []): void
    {
        $post = Post::findBySlug($vars['slug']);

        // AN ENTRY WHOSE TYPE HAS NO PAGES has no page, at any address and
        // however it is reached (`Registry::hasPages()`).
        if ($post === null || $post['post_type'] !== $this->postTypeKey || !Registry::hasPages($this->postTypeKey)) {
            Template::notFound($this->postTypeKey);
            return;
        }

        // Private: require login — or the entry's share link while it works
        // (`Content\Share`), which opens it as signing in would. A sample
        // entry is private where samples are not shown (`Post::siteStatus()`).
        $shared = !Auth::check() && \Cms\Content\Share::grants('post', (int) $post['id']);
        if (Post::siteStatus($post) === 'private' && !Auth::check() && !$shared) {
            Template::notFound($this->postTypeKey);
            return;
        }

        // SCHEDULED: published, with a date still to come. A visitor gets the
        // 404 a private entry gives; a signed-in owner gets the page, so the
        // address works as a preview of what goes out. ENDED, its unpublish
        // date come, is the same to a visitor.
        if ((Post::isScheduled($post) || Post::hasEnded($post)) && !Auth::check() && !$shared) {
            Template::notFound($this->postTypeKey);
            return;
        }

        // The page exists — so the other ending of its address 301s here now, and
        // not before: a 404 stays a 404 (`Url::settle()`).
        \Cms\Helpers\Url::settle();
        // Edit this entry, for an account that may change it (`Site\EditBar`).
        \Cms\Site\EditBar::forEntry($post);

        // Password protected: check session or POST
        if ($post['status'] === 'password') {
            $sessionKey = 'password_unlocked_' . $post['id'];
            if (!empty($_POST['content_password'])) {
                if (password_verify($_POST['content_password'], $post['password_hash'] ?? '')) {
                    \Cms\Security\Session::ensure();   // a visitor's first session: the entry is unlocked for it
                    $_SESSION[$sessionKey] = true;
                } else {
                    $passwordError = true;
                }
            }
            if (empty($_SESSION[$sessionKey])) {
                $pageTitle = $post['title'];

                // SAME AS `PageController::show()` — see the long note there.
                // Without it the gate would render `<title></title>` and invite
                // indexing of the lock screen of protected content.
                $slugBase = (string) Registry::publicSlug($this->postTypeKey);
                \Cms\SEO\Head::set([
                    'title'     => $post['title'],
                    'robots'    => 'noindex, nofollow',
                    'canonical' => \Cms\Helpers\Url::entry($post, $slugBase),
                ]);

                // A WHOLE-PAGE THEME DRAWS ITS OWN GATE when it has a `password`
                // template (`Helpers\Theme`), handed the entry, the field its
                // form posts back to this address, the head's robots and
                // canonical, and whether the password given was wrong. One
                // without it gets the CMS's own gate, framed.
                $gate = Theme::template('password', $this->postTypeKey);
                if ($gate !== null) {
                    Theme::render($gate, [
                        'post'           => $post,
                        'contentType'    => Registry::get($this->postTypeKey),
                        'contentTypeKey' => $this->postTypeKey,
                        'title'          => (string) $post['title'],
                        'canonical'      => \Cms\Helpers\Url::entry($post, $slugBase),
                        'robots'         => 'noindex, nofollow',
                        'passwordField'  => 'content_password',
                        'passwordError'  => !empty($passwordError),
                    ]);
                    return;
                }

                ob_start();
                require Template::locate('password-form.php');
                $pageContent = ob_get_clean();
                require Template::locate('base.php');
                return;
            }
        }

        $navPages  = [];
        $pageTitle = $post['title'];

        // SEO: what the entry's page puts in its head, read where a site that
        // draws its own head reads it (`Head::forEntry()`), so the two agree.
        // Not the body for a missing description: a cut sentence fragment in a
        // search result is worse than letting the engine choose.
        $contentType = Registry::get($this->postTypeKey);
        $publicSlug  = (string) Registry::publicSlug($this->postTypeKey);
        $ctLabel     = isset($contentType['label']) ? \Cms\Site\Words::typeLabel($contentType) : ucfirst($this->postTypeKey);
        $meta        = Meta::getAll((int) $post['id']);
        $seo         = Head::forEntry($post, $meta);
        $canonical   = $seo['canonical'];
        $seoDesc     = $seo['description'];

        $breadcrumbItems = [
            ['label' => \Cms\Site\Words::of('home'), 'url' => \Cms\Helpers\Url::home()],
            ['label' => $ctLabel, 'url' => \Cms\Helpers\Url::archive($publicSlug)],
            ['label' => $post['title'], 'url' => $canonical],
        ];
        Breadcrumbs::set($breadcrumbItems);

        // ── EVERY ARCHETYPE THE TYPE CARRIES, NOT THE FIRST ONE MATCHED ──────
        //
        // The entry page and the archive card both read this list ADDITIVELY,
        // and so does this. An `if/elseif` chain would stop at the first hit: a
        // ticketed event — Event and Product, which is the case the whole model
        // exists to allow — would go out as `"@type": "Event"` and leave its
        // price, SKU and availability out of the structured data entirely,
        // while the page itself printed all three.
        //
        // Order matters only for the single-archetype case's bare string, and it
        // is specific before general.
        //
        // `post` STAYS THE FALLBACK rather than adding `Article` to the list.
        // Every type carries it unless somebody takes it off — it is the
        // registration default — so claiming Article for it would put Article on
        // very nearly every entry on every install, beside the type that
        // actually describes it. That is not additive, it is noise: `post` here
        // means "nothing more specific", and the moment something more specific
        // is true, it is the thing to say.
        $archetypes  = $contentType['archetypes'] ?? ['post'];
        $schemaTypes = [];
        foreach (['event' => 'Event', 'product' => 'Product', 'listing' => 'WebPage'] as $a => $t) {
            if (in_array($a, $archetypes, true)) {
                $schemaTypes[] = $t;
            }
        }
        $schemaType = $schemaTypes === [] ? 'Article' : $schemaTypes;

        $graph   = Schema::siteWide();
        // The entry's own address and description ride along.
        $graph[] = Schema::forEntry($schemaType, $post, $meta, ['url' => $canonical, 'description' => $seoDesc]);
        $graph[] = Schema::breadcrumbList($breadcrumbItems);

        Head::set([
            'title'       => $seo['title'],
            'description' => $seoDesc,
            'canonical'   => $canonical,
            // The entry and its translation, both on the site (`Content\Translation`).
            'alternates'  => \Cms\Content\Translation::versions('posts', $post,
                static fn (array $row): string => \Cms\Helpers\Url::entry($row, (string) $publicSlug)),
            'robots'      => $seo['robots'],
            'og_type'     => 'article',
            'og_image'    => $seo['image'],
            'schema_json' => Schema::encode($graph),
            'feeds'       => [(string) $this->postTypeKey],
        ]);

        // A WHOLE-PAGE THEME renders the entire page when it has a template
        // for it (`Helpers\Theme`): the rows and the SEO computed above are
        // handed over by the names a site's own theme reads.
        $themed = Theme::template('single', $this->postTypeKey);
        if ($themed !== null) {
            Theme::render($themed, [
                'post'           => $post,
                'meta'           => $meta,
                'contentType'    => $contentType,
                'contentTypeKey' => $this->postTypeKey,
                'title'          => $seo['title'],
                'description'    => $seoDesc,
                'canonical'      => $canonical,
                'robots'         => $seo['robots'],
                'ogImage'        => $seo['image'],
                'schemaGraph'    => $graph,
                'breadcrumbs'    => $breadcrumbItems,
            ]);
            return;
        }

        ob_start();
        require Template::locate(...Template::forType('post', $this->postTypeKey));
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }
}
