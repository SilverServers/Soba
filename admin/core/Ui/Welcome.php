<?php

declare(strict_types=1);

namespace Cms\Ui;

use Cms\ContentType\Registry;
use Cms\Helpers\Modules;
use Cms\Helpers\Url;
use Cms\Models\Option;
use Cms\Models\Page;

/**
 * A NEW SITE'S NEXT STEPS, at the top of the dashboard: write a post, edit
 * the About page, put words on the front page, name the site. Each step is a
 * link to where it is done; there is no greeting, and no link the top bar has
 * already (View site).
 *
 * Only a new site has it: the set-up starts it (`StartingContent`), so a site
 * that updates to this release never does. Administrators see it until one
 * hides it, and then it is gone for everyone, for good.
 */
final class Welcome
{
    /** '1' while the panel is shown (an option, `options` table). */
    public const KEY = 'welcome_panel';

    /** Show the next steps: for a site just set up. */
    public static function begin(): void
    {
        Option::set(self::KEY, '1');
    }

    /** Does this account see them now? */
    public static function shown(?array $user): bool
    {
        return ($user['role'] ?? '') === 'admin' && Option::get(self::KEY) === '1';
    }

    /** Hide them, for good. */
    public static function hide(): void
    {
        Option::set(self::KEY, '0');
    }

    /**
     * The steps, for the site as it is now: a step whose way in is gone (the
     * Posts type deleted, pages switched off) is left out.
     *
     * @return list<array{label: string, text: string, href: string, icon: string}>
     */
    public static function steps(): array
    {
        $steps = [];
        $feed  = Registry::withArchetype('post');
        if ($feed !== []) {
            $type    = reset($feed);
            $steps[] = [
                'label' => 'Write a ' . mb_strtolower((string) ($type['label_single'] ?? 'post')),
                'text'  => 'It joins the list on the front page.',
                'href'  => Url::admin(trim((string) ($type['admin_slug'] ?? ''), '/') . '/new'),
                'icon'  => Sidebar::iconFor((string) ($type['menu_icon'] ?? 'file')),
            ];
        }
        if (Modules::enabled('pages')) {
            $about   = Page::findBySlug('about');
            $steps[] = $about !== null
                ? ['label' => 'Edit the About page', 'text' => 'Who the site is for, and how to reach you.',
                   'href' => Url::admin('pages/' . (int) $about['id'] . '/edit'), 'icon' => Sidebar::iconFor('pages')]
                : ['label' => 'Add a page', 'text' => 'Who the site is for, and how to reach you.',
                   'href' => Url::admin('pages/new'), 'icon' => Sidebar::iconFor('pages')];
            if (Page::findBySlug(Url::FRONT_PAGE) === null) {
                $steps[] = ['label' => 'Put words on the front page', 'text' => 'A page with the address ' . Url::FRONT_PAGE
                    . ' shows above the posts.', 'href' => Url::admin('pages/new'), 'icon' => 'window-maximize'];
            }
        }
        $steps[] = ['label' => 'Name the site', 'text' => 'Its title, tagline and time zone, in Settings → General.',
                    'href' => Url::admin('settings/general'), 'icon' => Sidebar::iconFor('settings')];

        return $steps;
    }
}
