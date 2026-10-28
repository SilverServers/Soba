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

        if ($post === null || $post['post_type'] !== $this->postTypeKey) {
            Template::notFound($this->postTypeKey);
            return;
        }

        // Private: require login
        if ($post['status'] === 'private' && !Auth::check()) {
            Template::notFound($this->postTypeKey);
            return;
        }

        // SCHEDULED: published, with a date still to come. A visitor gets the
        // 404 a private entry gives; a signed-in owner gets the page, so the
        // address works as a preview of what goes out.
        if (Post::isScheduled($post) && !Auth::check()) {
            Template::notFound($this->postTypeKey);
            return;
        }

        // The page exists — so the other ending of its address 301s here now, and
        // not before: a 404 stays a 404 (`Url::settle()`).
        \Cms\Helpers\Url::settle();

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
                $slugBase = (string) (Registry::get($this->postTypeKey)['public_slug']
                    ?? $this->postTypeKey);
                \Cms\SEO\Head::set([
                    'title'     => $post['title'],
                    'robots'    => 'noindex, nofollow',
                    'canonical' => \Cms\Helpers\Url::page($slugBase . '/' . $post['slug']),
                ]);

                ob_start();
                require Template::locate('password-form.php');
                $pageContent = ob_get_clean();
                require Template::locate('base.php');
                return;
            }
        }

        $navPages  = [];
        $pageTitle = $post['title'];

        // SEO
        $contentType = Registry::get($this->postTypeKey);
        $publicSlug  = $contentType['public_slug'] ?? $this->postTypeKey;
        $ctLabel     = $contentType['label'] ?? ucfirst($this->postTypeKey);

        $tokens = Head::globalTokens();
        $tokens['title']              = $post['title'];
        $tokens['content_type_label'] = $ctLabel;
        $title = Head::buildTitle(Head::titlePattern('content_type'), $tokens);

        // `Url::page()` — the one builder of a page's address.
        $canonical = \Cms\Helpers\Url::page($publicSlug . '/' . $post['slug']);

        // Per-entry SEO overrides from post_meta
        $meta    = Meta::getAll((int) $post['id']);
        // VARIABLES AND ALL — the Search engines panel edits both boxes as
        // pills, so `{title}` or `{sep}` typed there resolves here the way the
        // pattern's do. An empty title is one left on the pattern
        // (`Head::ownTitle()`).
        $seoTitle = trim((string) ($meta['_seo_title'] ?? ''));
        // THE OWNER'S OWN DESCRIPTION FIRST, THEN THEIR EXCERPT (migration 053).
        // An entry with neither would otherwise go out with no description; the
        // excerpt is a summary the owner already wrote. Not the body — a cut
        // sentence fragment in a search result is worse than letting the engine
        // choose. The excerpt is prose, not a pattern, so it is not run through
        // the variables.
        $seoDesc  = Head::buildTitle(trim((string) ($meta['_meta_description'] ?? '')), $tokens);
        if ($seoDesc === '') {
            $seoDesc = trim((string) ($post['excerpt'] ?? ''));
        }
        // THE ENTRY'S OWN SOCIAL IMAGE: the entry form stores the
        // picture's id; a full address — from an import, or from before the
        // form wrote this — is used as it is.
        $ogImage  = (string) ($meta['_og_image'] ?? '');
        if (ctype_digit($ogImage)) {
            $ogImage = \Cms\Models\Media::getUrl((int) $ogImage);
        }
        $robots   = $meta['_seo_robots'] ?? 'index, follow';

        // Featured image for OG fallback
        if ($ogImage === '' && !empty($post['featured_image_id'])) {
            $ogImage = \Cms\Models\Media::getUrl((int) $post['featured_image_id']);
        }

        $breadcrumbItems = [
            ['label' => 'Home',   'url' => BASE_URL . '/'],
            ['label' => $ctLabel, 'url' => \Cms\Helpers\Url::page($publicSlug)],
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
            'title'       => $seoTitle !== '' ? Head::buildTitle($seoTitle, $tokens) : $title,
            'description' => $seoDesc,
            'canonical'   => $canonical,
            'robots'      => $robots,
            'og_type'     => 'article',
            'og_image'    => $ogImage,
            'schema_json' => Schema::encode($graph),
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
                'title'          => $seoTitle !== '' ? Head::buildTitle($seoTitle, $tokens) : $title,
                'description'    => $seoDesc,
                'canonical'      => $canonical,
                'robots'         => $robots,
                'ogImage'        => $ogImage,
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
