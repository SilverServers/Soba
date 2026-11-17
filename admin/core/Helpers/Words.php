<?php

declare(strict_types=1);

namespace Cms\Helpers;

/**
 * THE WORDS ON A PAGE, AND WHAT A SEARCH COMPARES (`Public\SearchController`).
 *
 * `of()` is the text a visitor reads, from what an editor wrote. The markup is
 * gone, so a search for "table" or "strong" finds the word, not every page
 * holding that tag, and each tag's edge is a space, so "one</p><p>two" stays
 * two words. Entities are read ("&amp;" is "&"). A script's or a style's
 * contents go with it. The shortcodes the site expands go too, since a
 * gallery's or a form's place in the text is not its words. Spaces run
 * together.
 *
 * `fold()` is what two spellings of a word share, so a match ignores capitals
 * and accents: lower case, and each letter with an accent as the letter
 * without it, "Café" as "cafe". A static copy's search does the same in the
 * visitor's browser (`search-copy.js`), so the copy finds what the site finds.
 */
final class Words
{
    /**
     * Each letter of the Latin, Greek and Cyrillic alphabets that is a letter
     * and its accents, and beside it in `PLAIN`, the letter alone: Unicode's
     * canonical decomposition with the combining accents (U+0300 to U+036F)
     * taken off, as a browser's `normalize('NFD')` and the same range give.
     * Written out because PHP decomposes only with the intl extension, which
     * a host need not have.
     */
    private const ACCENTED = 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöùúûüýÿĀāĂăĄąĆ'
        . 'ćĈĉĊċČčĎďĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĨĩĪīĬĭĮįİĴĵĶķĹĺĻļĽľŃńŅņŇňŌōŎŏŐő'
        . 'ŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžƠơƯưǍǎǏǐǑǒǓǔǕǖǗǘǙǚǛ'
        . 'ǜǞǟǠǡǢǣǦǧǨǩǪǫǬǭǮǯǰǴǵǸǹǺǻǼǽǾǿȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏȐȑȒȓȔȕȖȗȘșȚțȞȟȦȧ'
        . 'ȨȩȪȫȬȭȮȯȰȱȲȳʹΆΈΉΊΌΎΏΐΪΫάέήίΰϊϋόύώϓϔЀЁЃЇЌЍЎЙйѐёѓїќѝўѶѷӁӂӐӑӒӓӖ'
        . 'ӗӚӛӜӝӞӟӢӣӤӥӦӧӪӫӬӭӮӯӰӱӲӳӴӵӸӹḀḁḂḃḄḅḆḇḈḉḊḋḌḍḎḏḐḑḒḓḔḕḖḗḘḙḚḛḜḝḞḟḠ'
        . 'ḡḢḣḤḥḦḧḨḩḪḫḬḭḮḯḰḱḲḳḴḵḶḷḸḹḺḻḼḽḾḿṀṁṂṃṄṅṆṇṈṉṊṋṌṍṎṏṐṑṒṓṔṕṖṗṘṙṚṛṜ'
        . 'ṝṞṟṠṡṢṣṤṥṦṧṨṩṪṫṬṭṮṯṰṱṲṳṴṵṶṷṸṹṺṻṼṽṾṿẀẁẂẃẄẅẆẇẈẉẊẋẌẍẎẏẐẑẒẓẔẕẖẗẘ'
        . 'ẙẛẠạẢảẤấẦầẨẩẪẫẬậẮắẰằẲẳẴẵẶặẸẹẺẻẼẽẾếỀềỂểỄễỆệỈỉỊịỌọỎỏỐốỒồỔổỖỗỘộ'
        . 'ỚớỜờỞởỠỡỢợỤụỦủỨứỪừỬửỮữỰựỲỳỴỵỶỷỸỹἀἁἂἃἄἅἆἇἈἉἊἋἌἍἎἏἐἑἒἓἔἕἘἙἚἛἜἝ'
        . 'ἠἡἢἣἤἥἦἧἨἩἪἫἬἭἮἯἰἱἲἳἴἵἶἷἸἹἺἻἼἽἾἿὀὁὂὃὄὅὈὉὊὋὌὍὐὑὒὓὔὕὖὗὙὛὝὟὠὡὢὣ'
        . 'ὤὥὦὧὨὩὪὫὬὭὮὯὰάὲέὴήὶίὸόὺύὼώᾀᾁᾂᾃᾄᾅᾆᾇᾈᾉᾊᾋᾌᾍᾎᾏᾐᾑᾒᾓᾔᾕᾖᾗᾘᾙᾚᾛᾜᾝᾞᾟᾠᾡ'
        . 'ᾢᾣᾤᾥᾦᾧᾨᾩᾪᾫᾬᾭᾮᾯᾰᾱᾲᾳᾴᾶᾷᾸᾹᾺΆᾼιῂῃῄῆῇῈΈῊΉῌῐῑῒΐῖῗῘῙῚΊῠῡῢΰῤῥῦῧῨῩῪΎῬ'
        . 'ῲῳῴῶῷῸΌῺΏῼ';

    private const PLAIN = 'AAAAAACEEEEIIIINOOOOOUUUUYaaaaaaceeeeiiiinooooouuuuyyAaAaAaC'
        . 'cCcCcCcDdEeEeEeEeEeGgGgGgGgHhIiIiIiIiIJjKkLlLlLlNnNnNnOoOoOo'
        . 'RrRrRrSsSsSsSsTtTtUuUuUuUuUuUuWwYyYZzZzZzOoUuAaIiOoUuUuUuUuU'
        . 'uAaAaÆæGgKkOoOoƷʒjGgNnAaÆæØøAaAaEeEeIiIiOoOoRrRrUuUuSsTtHhAa'
        . 'EeOoOoOoOoYyʹΑΕΗΙΟΥΩιΙΥαεηιυιυουωϒϒЕЕГІКИУИиеегікиуѴѵЖжАаАаЕ'
        . 'еӘәЖжЗзИиИиОоӨөЭэУуУуУуЧчЫыAaBbBbBbCcDdDdDdDdDdEeEeEeEeEeFfG'
        . 'gHhHhHhHhHhIiIiKkKkKkLlLlLlLlMmMmMmNnNnNnNnOoOoOoOoPpPpRrRrR'
        . 'rRrSsSsSsSsSsTtTtTtTtUuUuUuUuUuVvVvWwWwWwWwWwXxXxYyZzZzZzhtw'
        . 'yſAaAaAaAaAaAaAaAaAaAaAaAaEeEeEeEeEeEeEeEeIiIiOoOoOoOoOoOoOo'
        . 'OoOoOoOoOoUuUuUuUuUuUuUuYyYyYyYyααααααααΑΑΑΑΑΑΑΑεεεεεεΕΕΕΕΕΕ'
        . 'ηηηηηηηηΗΗΗΗΗΗΗΗιιιιιιιιΙΙΙΙΙΙΙΙοοοοοοΟΟΟΟΟΟυυυυυυυυΥΥΥΥωωωω'
        . 'ωωωωΩΩΩΩΩΩΩΩααεεηηιιοουυωωααααααααΑΑΑΑΑΑΑΑηηηηηηηηΗΗΗΗΗΗΗΗωω'
        . 'ωωωωωωΩΩΩΩΩΩΩΩαααααααΑΑΑΑΑιηηηηηΕΕΗΗΗιιιιιιΙΙΙΙυυυυρρυυΥΥΥΥΡ'
        . 'ωωωωωΟΟΩΩΩ';

    /** The words a visitor reads, from a page's or an entry's HTML. */
    public static function of(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = (string) preg_replace('#\[(?:gallery|form)\s+id=["\']?\d+["\']?\s*\]|\[video\s+[^\]]*\]#i', ' ', $html);
        // The business's details read as the visitor reads them: the number, not [phone].
        $html = \Cms\Site\Business::shortcodes($html);
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Lower case and without accents: the form two spellings of a word share. */
    public static function fold(string $text): string
    {
        static $letters = null;
        $letters ??= array_combine(mb_str_split(self::ACCENTED), mb_str_split(self::PLAIN));

        return mb_strtolower((string) preg_replace('/[\x{0300}-\x{036F}]+/u', '', strtr($text, $letters)));
    }
}
