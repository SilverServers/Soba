<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\Models\Option;

/**
 * THE THEME'S OWN WORDS: what a page says that no page's content holds — a
 * list's "Older", search's "Nothing on this site matches", the password
 * gate, the not-found page, the menu's button, the cookie banner, the days in
 * opening hours and the months in a date — in the language of the page being
 * drawn (`Language`).
 *
 * Each is English here, in the code, and French ships beside it (`FRENCH`):
 * a site in French, or with French as its second language, says them in
 * French. The owner can change any of them, for the site's own language and
 * for its second, on Settings → Words; one left empty is the shipped word.
 * A language with no set here is written in English until the owner gives
 * its words. A page in the second language also names each translated type
 * and taxonomy as the owner gives it there (`typeLabel()`, `taxonomyLabel()`),
 * its own name standing in until then.
 *
 * The shop's three words are its own (`Commerce\Words`). The admin is
 * English, and a date written there keeps PHP's names (`dateFormat()`).
 */
final class Words
{
    /** The owner's words for the site's own language, and for its second: key => word, as JSON. */
    public const OPTION        = 'site_words';
    public const OPTION_SECOND = 'site_words_second';

    /**
     * EVERY WORD, grouped as Settings → Words shows them: key => [the English
     * word, where a visitor sees it]. `{n}` stands for a number, `{q}` for the
     * words searched for, and `{type}` for a type's name.
     */
    public const GROUPS = [
        'Lists and pages' => [
            'home'               => ['Home', 'The way back to the front page, and the first step of a page\'s trail'],
            'newer'              => ['Newer', 'The front page\'s link to newer entries'],
            'older'              => ['Older', 'The front page\'s link to older entries'],
            'previous'           => ['Previous', 'A list\'s link to its page before, and an entry\'s to the one before it'],
            'next'               => ['Next', 'A list\'s link to its page after, and an entry\'s to the one after it'],
            'page_n'             => ['Page {n}', 'A later page of a list, in its title'],
            'more_of'            => ['More {type}', 'An entry\'s links to the ones before and after it, to a screen reader'],
            'related'            => ['Related', 'The heading over entries like the one on the page'],
            'archive'            => ['Archive', 'A list\'s heading when its type has no name'],
            'past_events'        => ['Past events', 'The heading over an events list\'s past events'],
            'calendar_subscribe' => ['Subscribe in your calendar', 'An events list\'s link to its calendar'],
            'calendar_add'       => ['Add to calendar', 'An event\'s link to put it in a calendar'],
            'empty_home'         => ['No posts published yet.', 'The front page\'s list with nothing in it'],
            'empty_archive'      => ['No entries published yet.', 'A list with nothing in it'],
            'empty_term'         => ['Nothing has been filed under this yet.', 'A category\'s page with nothing in it'],
            'skip'               => ['Skip to content', 'The keyboard\'s way past the header'],
            'by'                 => ['By', 'Before the author\'s name over an entry and in the front page\'s list'],
        ],
        'Search' => [
            'search'       => ['Search', 'The search page\'s heading and button, and the search box\'s button'],
            'search_label' => ['Search this site', 'The search box, to a screen reader and before anything is typed'],
            'search_title' => ['Search: {q}', 'The search page\'s title once something is searched for'],
            'search_none'  => ['Nothing on this site matches “{q}”.', 'A search that found nothing'],
            'search_one'   => ['{n} result for “{q}”', 'A search that found one thing'],
            'search_many'  => ['{n} results for “{q}”', 'A search that found more'],
            'search_first' => ['The first {n} results for “{q}”', 'A search that found more than it shows'],
        ],
        'Not found and passwords' => [
            'not_found_title' => ['Page Not Found', 'The title of a page that is not there'],
            'not_found_tab'   => ['404 Not Found', 'The same, on the page drawn when the theme has none of its own for it'],
            'not_found'       => ['Page not found.', 'What a page that is not there says'],
            'go_home'         => ['Go home', 'Its link back to the front page'],
            'password_lead'   => ['This content is password protected. Enter the password to view it.', 'What a page behind a password says'],
            'password_wrong'  => ['Incorrect password. Please try again.', 'A password that was wrong'],
            'password_label'  => ['Password', 'The password box'],
            'password_submit' => ['Submit', 'Its button'],
        ],
        'Menus and cookies' => [
            'nav_main'        => ['Main', 'The header\'s menu, to a screen reader'],
            'menu_toggle'     => ['Menu', 'The button that opens the menu on a phone'],
            'cookies'         => ['Cookies', 'The cookie banner, to a screen reader'],
            'cookie_words'    => ['This site uses cookies from Google to see how it is visited. They are set only if you accept.', 'The cookie banner, in the second language (the site\'s own is on Settings → SEO → Google)'],
            'cookie_accept'   => ['Accept', 'The banner\'s button that accepts'],
            'cookie_decline'  => ['Decline', 'Its button that declines'],
            'cookie_settings' => ['Cookie settings', 'The footer\'s button that asks again'],
        ],
        'Opening hours' => [
            'hours_and'   => ['and', 'Between days: Monday and Wednesday'],
            'hours_days'  => ['to', 'Between the first and last of days in a row: Monday to Friday'],
            'hours_times' => ['to', 'Between opening and closing: 9:00 AM to 5:00 PM'],
        ],
        'Months and days' => [
            'month_1' => ['January', ''], 'month_2' => ['February', ''], 'month_3' => ['March', ''], 'month_4' => ['April', ''],
            'month_5' => ['May', ''], 'month_6' => ['June', ''], 'month_7' => ['July', ''], 'month_8' => ['August', ''],
            'month_9' => ['September', ''], 'month_10' => ['October', ''], 'month_11' => ['November', ''], 'month_12' => ['December', ''],
            'mon_1' => ['Jan', 'Short'], 'mon_2' => ['Feb', 'Short'], 'mon_3' => ['Mar', 'Short'], 'mon_4' => ['Apr', 'Short'],
            'mon_5' => ['May', 'Short'], 'mon_6' => ['Jun', 'Short'], 'mon_7' => ['Jul', 'Short'], 'mon_8' => ['Aug', 'Short'],
            'mon_9' => ['Sep', 'Short'], 'mon_10' => ['Oct', 'Short'], 'mon_11' => ['Nov', 'Short'], 'mon_12' => ['Dec', 'Short'],
            'day_1' => ['Monday', ''], 'day_2' => ['Tuesday', ''], 'day_3' => ['Wednesday', ''], 'day_4' => ['Thursday', ''],
            'day_5' => ['Friday', ''], 'day_6' => ['Saturday', ''], 'day_0' => ['Sunday', ''],
            'dy_1' => ['Mon', 'Short'], 'dy_2' => ['Tue', 'Short'], 'dy_3' => ['Wed', 'Short'], 'dy_4' => ['Thu', 'Short'],
            'dy_5' => ['Fri', 'Short'], 'dy_6' => ['Sat', 'Short'], 'dy_0' => ['Sun', 'Short'],
        ],
    ];

    /** The French words, for a site in French or with French as its second language. */
    public const FRENCH = [
        'home'               => 'Accueil',
        'newer'              => 'Plus récents',
        'older'              => 'Plus anciens',
        'previous'           => 'Précédent',
        'next'               => 'Suivant',
        'page_n'             => 'Page {n}',
        'more_of'            => 'Autres : {type}',
        'related'            => 'À lire aussi',
        'archive'            => 'Archives',
        'past_events'        => 'Événements passés',
        'calendar_subscribe' => 'S’abonner dans votre agenda',
        'calendar_add'       => 'Ajouter à l’agenda',
        'empty_home'         => 'Rien n’a encore été publié.',
        'empty_archive'      => 'Rien n’a encore été publié.',
        'empty_term'         => 'Rien n’est encore classé ici.',
        'skip'               => 'Aller au contenu',
        'by'                 => 'Par',
        'search'             => 'Rechercher',
        'search_label'       => 'Rechercher sur ce site',
        'search_title'       => 'Recherche : {q}',
        'search_none'        => 'Rien sur ce site ne correspond à « {q} ».',
        'search_one'         => '{n} résultat pour « {q} »',
        'search_many'        => '{n} résultats pour « {q} »',
        'search_first'       => 'Les {n} premiers résultats pour « {q} »',
        'not_found_title'    => 'Page introuvable',
        'not_found_tab'      => '404 Page introuvable',
        'not_found'          => 'Cette page est introuvable.',
        'go_home'            => 'Retour à l’accueil',
        'password_lead'      => 'Ce contenu est protégé par un mot de passe. Entrez-le pour le voir.',
        'password_wrong'     => 'Mot de passe incorrect. Veuillez réessayer.',
        'password_label'     => 'Mot de passe',
        'password_submit'    => 'Envoyer',
        'nav_main'           => 'Principal',
        'menu_toggle'        => 'Menu',
        'cookies'            => 'Témoins',
        'cookie_words'       => 'Ce site utilise des témoins de Google pour savoir comment il est visité. Ils ne sont déposés que si vous acceptez.',
        'cookie_accept'      => 'Accepter',
        'cookie_decline'     => 'Refuser',
        'cookie_settings'    => 'Paramètres des témoins',
        'hours_and'          => 'et',
        'hours_days'         => 'au',
        'hours_times'        => 'à',
        'month_1' => 'janvier', 'month_2' => 'février', 'month_3' => 'mars', 'month_4' => 'avril',
        'month_5' => 'mai', 'month_6' => 'juin', 'month_7' => 'juillet', 'month_8' => 'août',
        'month_9' => 'septembre', 'month_10' => 'octobre', 'month_11' => 'novembre', 'month_12' => 'décembre',
        'mon_1' => 'janv.', 'mon_2' => 'févr.', 'mon_3' => 'mars', 'mon_4' => 'avr.',
        'mon_5' => 'mai', 'mon_6' => 'juin', 'mon_7' => 'juill.', 'mon_8' => 'août',
        'mon_9' => 'sept.', 'mon_10' => 'oct.', 'mon_11' => 'nov.', 'mon_12' => 'déc.',
        'day_1' => 'lundi', 'day_2' => 'mardi', 'day_3' => 'mercredi', 'day_4' => 'jeudi',
        'day_5' => 'vendredi', 'day_6' => 'samedi', 'day_0' => 'dimanche',
        'dy_1' => 'lun.', 'dy_2' => 'mar.', 'dy_3' => 'mer.', 'dy_4' => 'jeu.',
        'dy_5' => 'ven.', 'dy_6' => 'sam.', 'dy_0' => 'dim.',
    ];

    /** The owner's words, per language, read once a request: [false => own, true => second]. */
    private static array $owner = [];

    /**
     * A WORD IN THE LANGUAGE OF THE PAGE BEING DRAWN, with `{n}`, `{q}` or
     * `{type}` filled from `$fill` (['n' => 3, 'q' => 'club']).
     *
     * @param array<string, string|int> $fill
     */
    public static function of(string $key, array $fill = []): string
    {
        $word = self::word($key, Language::drawn() !== '');
        $swap = [];
        foreach ($fill as $name => $value) {
            $swap['{' . $name . '}'] = (string) $value;
        }

        return $swap === [] ? $word : strtr($word, $swap);
    }

    /** One word in the site's own language (false) or its second (true): the owner's, else the one that ships. */
    public static function word(string $key, bool $second): string
    {
        $mine = self::owner($second)[$key] ?? '';

        return $mine !== '' ? $mine : (self::shipped($second)[$key] ?? '');
    }

    /**
     * The words that ship for the site's own language (false) or its second
     * (true): French's for French, English's for any other.
     *
     * @return array<string, string>
     */
    public static function shipped(bool $second): array
    {
        $tag = $second ? Language::second() : Language::own();

        return strtolower(substr($tag, 0, 2)) === 'fr' ? self::FRENCH + self::english() : self::english();
    }

    /** @return array<string, string> key => its English word */
    public static function english(): array
    {
        $out = [];
        foreach (self::GROUPS as $words) {
            foreach ($words as $key => [$word]) {
                $out[$key] = $word;
            }
        }

        return $out;
    }

    /**
     * The owner's own words for the site's language (false) or its second
     * (true), key => word, empty ones left out.
     *
     * @return array<string, string>
     */
    public static function owner(bool $second): array
    {
        if (!isset(self::$owner[(int) $second])) {
            $stored = json_decode((string) (Option::get($second ? self::OPTION_SECOND : self::OPTION, '') ?? ''), true);
            $words  = [];
            foreach (is_array($stored) ? $stored : [] as $key => $word) {
                if (is_string($key) && is_string($word) && trim($word) !== '') {
                    $words[$key] = trim($word);
                }
            }
            self::$owner[(int) $second] = $words;
        }

        return self::$owner[(int) $second];
    }

    /**
     * Keep the owner's words for a language: each key this class knows, or a
     * type's or taxonomy's name for the second language (`type:`, `type1:`,
     * `tax:`), with what was typed; an empty one goes back to the shipped word.
     *
     * @param array<string, mixed> $words
     */
    public static function save(bool $second, array $words): void
    {
        $known = self::english();
        $keep  = [];
        foreach ($words as $key => $word) {
            $key  = (string) $key;
            $word = is_string($word) ? trim($word) : '';
            $name = $second && preg_match('/^(type|type1|tax):[a-z0-9_-]+$/', $key) === 1;
            if ($word !== '' && (isset($known[$key]) || $name)) {
                $keep[$key] = mb_substr($word, 0, 500);
            }
        }
        Option::set($second ? self::OPTION_SECOND : self::OPTION, (string) json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        unset(self::$owner[(int) $second]);
    }

    /** A type's name as the page being drawn says it: in the second language, the owner's name for it there, else its own. */
    public static function typeLabel(array $type, bool $single = false): string
    {
        $own = (string) ($type[$single ? 'label_single' : 'label'] ?? '');
        if (Language::drawn() === '') {
            return $own;
        }
        $word = self::owner(true)[($single ? 'type1:' : 'type:') . (string) ($type['key'] ?? '')] ?? '';

        return $word !== '' ? $word : $own;
    }

    /** A taxonomy's name as the page being drawn says it, as `typeLabel()` does a type's. */
    public static function taxonomyLabel(array $taxonomy): string
    {
        $own = (string) ($taxonomy['label'] ?? '');
        if (Language::drawn() === '') {
            return $own;
        }
        $word = self::owner(true)['tax:' . (string) ($taxonomy['key'] ?? '')] ?? '';

        return $word !== '' ? $word : $own;
    }

    /** A day of the week (`monday` … `sunday`) as the page being drawn writes it. */
    public static function day(string $day): string
    {
        $n = array_search(strtolower($day), ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'], true);

        return $n === false ? $day : self::of('day_' . $n);
    }

    /**
     * `$format`, for writing `$at` on a page a visitor reads, with the month's
     * and the day's names (F, M, l, D) in the page's language when they are
     * not PHP's own: the site's French, a second language's, or the owner's.
     * Unchanged anywhere else, so the admin and a site in English write dates
     * exactly as PHP does.
     */
    public static function dateFormat(string $format, \DateTimeInterface $at): string
    {
        if (!Language::visiting() || preg_match('/[FMlD]/', $format) !== 1) {
            return $format;
        }
        $names = [
            'F' => self::of('month_' . $at->format('n')),
            'M' => self::of('mon_' . $at->format('n')),
            'l' => self::of('day_' . $at->format('w')),
            'D' => self::of('dy_' . $at->format('w')),
        ];
        $out = '';
        for ($i = 0, $n = strlen($format); $i < $n; $i++) {
            $c = $format[$i];
            if ($c === '\\') {
                $out .= $c . ($format[$i + 1] ?? '');
                $i++;
            } elseif (isset($names[$c]) && $names[$c] !== $at->format($c) && $names[$c] !== '') {
                // Each byte escaped, so the name is written as it is.
                $out .= '\\' . implode('\\', str_split($names[$c]));
            } else {
                $out .= $c;
            }
        }

        return $out;
    }
}
