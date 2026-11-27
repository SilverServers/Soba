<?php

declare(strict_types=1);

namespace Cms\Site;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Url;
use PDO;

/**
 * A BUSINESS DETAIL TYPED OUT where its shortcode or variable belongs.
 *
 * A phone number written into a page as digits stays the old number the day
 * Business details changes it, while every `[phone]` and `{phone}` follows.
 * So System health lists, in production, each page and entry whose text,
 * search title or description holds a typed copy of a Business details number
 * or its email (`found()`), each with a link to its editor.
 *
 * A NUMBER IS COMPARED AS DIGITS: `(250) 555-0134`, `250.555.0134` and
 * `+1 250 555 0134` are one number, the shorter one's digits ending the longer
 * one's, so a country code in front is the same number. A number shorter than
 * seven digits is too likely to be something else and is not looked for. An
 * email is found where it ends at a word's edge, in any case.
 */
final class TypedDetails
{
    private const MIN_DIGITS = 7;

    /** A run of characters that can be a phone number: digits, spaces, dots, dashes, brackets, a plus. */
    private const NUMBERISH = '~\+?\(?\d[\d\s().\-]{5,}\d~';

    /**
     * Every page and entry out of the Trash with a typed copy: its title, the
     * address of its editor, and what was typed.
     *
     * @return list<array{text: string, url: string}>
     */
    public static function found(): array
    {
        $details = Business::get();
        $numbers = [];
        foreach ((array) ($details['phones'] ?? []) as $phone) {
            $digits = preg_replace('~\D+~', '', (string) ($phone['number'] ?? ''));
            if (is_string($digits) && strlen($digits) >= self::MIN_DIGITS) {
                $numbers[$digits] = (string) $phone['number'];
            }
        }
        $email = strtolower(trim((string) ($details['email'] ?? '')));
        if ($numbers === [] && $email === '') {
            return [];
        }

        $out = [];
        $pdo = Database::getInstance();
        if (\Cms\Helpers\Modules::enabled('pages')) {
            foreach ($pdo->query("SELECT id, title, content, seo_title, meta_description FROM pages WHERE status <> 'trash' ORDER BY title, id")
                         ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $what = self::typed([(string) $row['content'], (string) $row['seo_title'], (string) $row['meta_description']], $numbers, $email);
                if ($what !== []) {
                    $out[] = ['text' => (string) $row['title'] . ' (a page): ' . implode(', ', $what), 'url' => Url::admin('pages/' . (int) $row['id'] . '/edit')];
                }
            }
        }
        $stmt = $pdo->query(
            "SELECT p.id, p.post_type, p.title, p.content, p.excerpt,
                    (SELECT meta_value FROM post_meta m WHERE m.post_id = p.id AND m.meta_key = '_seo_title' LIMIT 1) AS seo_title,
                    (SELECT meta_value FROM post_meta m WHERE m.post_id = p.id AND m.meta_key = '_meta_description' LIMIT 1) AS meta_description
               FROM posts p WHERE p.status <> 'trash' ORDER BY p.title, p.id"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = Registry::get((string) $row['post_type']);
            if ($type === null) {
                continue;
            }
            $what = self::typed([(string) $row['content'], (string) $row['excerpt'], (string) $row['seo_title'], (string) $row['meta_description']],
                $numbers, $email);
            if ($what !== []) {
                $out[] = ['text' => (string) $row['title'] . ' (' . mb_strtolower(Words::typeLabel($type, true)) . '): ' . implode(', ', $what),
                          'url' => Url::admin((string) $type['admin_slug'] . '/' . (int) $row['id'] . '/edit')];
            }
        }

        return $out;
    }

    /**
     * What of the details these texts type out, as it is written there.
     *
     * @param list<string>          $texts
     * @param array<string, string> $numbers digits => the number as Business details writes it
     * @return list<string>
     */
    public static function typed(array $texts, array $numbers, string $email): array
    {
        $what = [];
        foreach ($texts as $text) {
            if ($text === '') {
                continue;
            }
            $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // A link's own address is text too: tel: and mailto: are what was typed.
            $plain .= ' ' . implode(' ', self::hrefs($text));
            if ($numbers !== [] && preg_match_all(self::NUMBERISH, $plain, $m) > 0) {
                foreach ($m[0] as $candidate) {
                    $digits = (string) preg_replace('~\D+~', '', $candidate);
                    foreach (array_keys($numbers) as $number) {
                        $short = strlen($digits) <= strlen((string) $number) ? $digits : (string) $number;
                        $long  = $short === $digits ? (string) $number : $digits;
                        if (strlen($short) >= self::MIN_DIGITS && str_ends_with($long, $short)) {
                            $what['n' . $number] = 'the number ' . $numbers[$number];
                        }
                    }
                }
            }
            // Ended by anything that cannot carry the address on: a full
            // stop may end the sentence, not a dot before more of a domain.
            if ($email !== '' && preg_match('~(?<![A-Za-z0-9._%+-])' . preg_quote($email, '~') . '(?![A-Za-z0-9_%+-]|\.[A-Za-z0-9])~i', $plain) === 1) {
                $what['e'] = 'the email ' . $email;
            }
        }

        return array_values($what);
    }

    /** @return list<string> every href in `$html`, decoded */
    private static function hrefs(string $html): array
    {
        preg_match_all('~\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m);

        return array_map(static fn (string $h): string => rawurldecode(html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[2] ?? []);
    }
}
