<?php

declare(strict_types=1);

namespace Cms\Export;

use Cms\ContentType\Registry;
use Cms\Database\Database;
use Cms\Helpers\Theme;
use Cms\Helpers\Url;
use Cms\Models\Post;
use Cms\SEO\Redirects;
use Cms\Security\LoginUrl;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * A STATIC COPY OF THE SITE: every public page as a file, with the files they
 * use, for a host that runs no CMS.
 *
 * ── EVERY PAGE IS THE CMS'S OWN ─────────────────────────────────────────────
 * Nothing here draws a page. Each address is drawn by the site's own front
 * controller in a child process (`render-page.php`), signed out, at the
 * address the copy will be published at — so a page in the copy is the page a
 * visitor gets from the CMS, whatever theme draws it, and every link,
 * canonical and sitemap entry in it already names the copy's address.
 *
 * ── WHAT IS IN IT ───────────────────────────────────────────────────────────
 * The front page, the sitemaps and robots.txt, every published Page, every
 * live entry and archive of a type with pages on the site, and every address
 * on this site those link to, page after page: a crawl, so a theme's own links
 * and a list's later pages come along. A file a page uses — a stylesheet and
 * what it imports, a script, a font, an upload, a theme's picture — is copied
 * as it is. A page is written as `<path>/index.html`, which every host serves
 * at `<path>/`; a list's page 2, `?page=2` here, is `<path>/page/2/`, since a
 * static host ignores a query, and every link to it is rewritten so.
 *
 * ── WHAT IS NOT ─────────────────────────────────────────────────────────────
 * What only a server can answer (SERVER_ONLY): the admin, the API, private
 * files, the shop's basket, checkout and accounts. Links to those are left as
 * they are.
 *
 * ── A JOB, IN STEPS ─────────────────────────────────────────────────────────
 * A site of a few hundred pages takes a minute, longer than a web request may
 * run on shared hosting, so the copy is a job on disk (`admin/exports/<id>/`)
 * that `step()` advances for so many seconds at a time: the command line
 * (`admin/bin/export-static.php`) runs it to the end, and a screen can poll
 * it. Its state is `job.json`; the pages are written into `site/`, and the
 * last step packs `site/` into one archive beside it.
 *
 * ── THE SAME CRAWL, AS A CHECK OF THE SITE'S LINKS ───────────────────────────
 * `startCheck()` walks the site the same way and writes nothing: every page
 * is drawn and every link to the site's own addresses followed, and what
 * finds nothing — a page not found, a file not there — is kept with each page
 * that links to it (`broken()`), for Tools → Broken links. Its jobs live in a
 * folder of their own (`checkRoot()`), so a check never takes a copy's place.
 *
 * ── A PAGE THAT LOGGED AN ERROR HAS NOT PASSED ──────────────────────────────
 * Each child runs with an error log of the job's own. A line in it that names
 * this site is that page's error, reported with it, whatever status the page
 * returned; a line that does not is the host talking about itself.
 */
final class StaticCopy
{
    /**
     * Addresses only a server can answer, by first segment, and what each is.
     * Every public route is one of these or a page the copy draws: a route
     * added to the site is decided here, or the copy draws whatever it answers.
     */
    public const SERVER_ONLY = [
        '/admin'    => 'the admin',
        '/api'      => 'the API',
        '/forms'    => 'where a form is sent',
        '/files'    => 'private files',
        '/cart'     => 'the basket',
        '/checkout' => 'the checkout',
        '/order'    => 'an order',
        '/account'  => "a customer's account",
        '/shop'     => 'the shop',
        '/feed'     => 'the product feed',
    ];

    /** The longest the copy's queue is let grow: a crawl that runs away stops. */
    private const MAX_ADDRESSES = 20000;

    /** A problem worth listing: the copy is not complete without a look. */
    public const ERROR = 'error';

    /** Worth knowing; the copy is still sound. */
    public const NOTE = 'note';

    /** @var array<string, mixed> */
    private array $job;

    /** @var resource|null */
    private $lock = null;

    private function __construct(private string $dir, array $job)
    {
        $this->job = $job;
    }

    // ----------------------------------------------------------------
    // Jobs
    // ----------------------------------------------------------------

    /** Where the jobs are: outside the web root, and an update leaves it alone. */
    public static function root(): string
    {
        return ROOT_PATH . '/exports';
    }

    /**
     * A new copy, for the site at `$address`. `$php`: the host runs PHP, so
     * the copy's forms are sent by a handler of their own (`Export\Forms`).
     * `$instead`: the words shown in place of a form the copy cannot send —
     * every form, without PHP — such as "Email us at hello@example.org".
     * `$anywhere`: its links are relative and name each page's file
     * (`relativeLinks()`), so the copy works at any address, and opened from
     * a folder with no web server.
     */
    public static function start(string $address, bool $php, string $instead = '', bool $anywhere = false): self
    {
        $address = self::address($address);
        $front   = PUBLIC_PATH . '/index.php';
        if (!is_file($front)) {
            throw new RuntimeException('This site has its own front controller in front of the CMS, so the CMS does not draw all of its pages; a static copy is made of a site the CMS serves whole.');
        }

        // EACH COPY HOLDS EVERY UPLOAD THE SITE SHOWS, twice (the folder and
        // its archive), so the older ones go: the last two stay.
        self::prune(2);

        $id  = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir = self::root() . '/' . $id;
        if (!is_dir($dir . '/site') && !@mkdir($dir . '/site', 0775, true) && !is_dir($dir . '/site')) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }

        $copy = new self($dir, [
            'id'       => $id,
            'address'  => $address,
            'source'   => self::sourceAddress(),
            'php'      => $php,
            'instead'  => trim($instead),
            'anywhere' => $anywhere,
            'phase'    => 'pages',
            'queue'    => [],
            'seen'     => [],
            'written'  => [],
            'forms'    => [],
            'swapped'  => [],
            'pages'    => 0,
            'files'    => 0,
            'problems' => [],
            'log'      => 0,
            'started'  => time(),
            'finished' => null,
            'archive'  => null,
        ]);
        // WHAT A CHILD NEEDS, AND NOTHING ELSE, in a file of its own: the
        // queue can be large, and the child reads this once per page.
        self::writeFile($dir . '/render.json', (string) json_encode([
            'address' => $address,
            'php'     => $php,
            'instead' => trim($instead),
            // THE COPY'S OWN, for its form handler — never the site's app_secret.
            'secret'  => bin2hex(random_bytes(32)),
        ]));

        $copy->lock();
        // THE SEARCH INDEX IS WRITTEN, NOT CRAWLED: the search page names it,
        // and it exists only once `extras()` has made it.
        $copy->job['seen'][rtrim(Url::pagePath('search'), '/') . '/index.js'] = true;
        foreach ($copy->seeds() as $key) {
            $copy->enqueue($key, '');
        }
        $copy->save();
        $copy->unlock();

        return $copy;
    }

    /** Every job's id, newest first. */
    public static function ids(): array
    {
        $ids = [];
        foreach (is_dir(self::root()) ? (scandir(self::root()) ?: []) : [] as $name) {
            if (self::isId($name) && is_dir(self::root() . '/' . $name)) {
                $ids[] = $name;
            }
        }
        rsort($ids);
        return $ids;
    }

    /**
     * A job, gone: its folder and everything in it. Only a job's own folder,
     * named as a job is, under root(); anything else is left alone.
     */
    public static function delete(string $id): void
    {
        if (!self::isId($id)) {
            return;
        }
        self::deleteDir(self::root() . '/' . $id);
    }

    /** A job's folder and everything in it. */
    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
        if (is_dir($dir)) {
            throw new RuntimeException('Could not delete ' . $dir . '.');
        }
    }

    /** All but the newest `$keep` jobs, deleted — never one a step touched in the last hour. */
    private static function prune(int $keep): void
    {
        foreach (array_slice(self::ids(), $keep) as $id) {
            $job = self::root() . '/' . $id . '/job.json';
            if (is_file($job) && filemtime($job) > time() - 3600 && self::open($id)?->done() === false) {
                continue;
            }
            self::delete($id);
        }
    }

    private static function isId(string $name): bool
    {
        return preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $name) === 1;
    }

    /** A job already started, or null. */
    public static function open(string $id): ?self
    {
        if (!self::isId($id)) {
            return null;
        }
        $dir = self::root() . '/' . $id;
        $job = json_decode((string) @file_get_contents($dir . '/job.json'), true);
        return is_array($job) ? new self($dir, $job) : null;
    }

    /** Where the link checks are: their own folder, so neither prunes the other. */
    public static function checkRoot(): string
    {
        return self::root() . '/checks';
    }

    /**
     * A new check of the site's links: the same crawl as a copy, at the
     * site's own address, writing nothing (see the class's note). The last
     * check before it goes.
     */
    public static function startCheck(): self
    {
        if (!is_file(PUBLIC_PATH . '/index.php')) {
            throw new RuntimeException('This site has its own front controller in front of the CMS, so the CMS does not draw all of its pages; its links are checked where the site is served whole.');
        }
        foreach (self::checkIds() as $old) {
            $dir = self::checkRoot() . '/' . $old;
            $job = $dir . '/job.json';
            if (is_file($job) && filemtime($job) > time() - 600 && self::openCheck($old)?->done() === false) {
                throw new RuntimeException('A check is already running; it is shown below.');
            }
            self::deleteDir($dir);
        }

        $address = self::sourceAddress();
        $id      = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $dir     = self::checkRoot() . '/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }
        $check = new self($dir, [
            'id' => $id, 'address' => $address, 'source' => $address, 'php' => true, 'instead' => '',
            'anywhere' => false, 'check' => true, 'phase' => 'pages', 'queue' => [], 'seen' => [],
            'written' => [], 'forms' => [], 'swapped' => [], 'pages' => 0, 'files' => 0, 'problems' => [],
            'links' => [], 'broken' => [], 'log' => 0, 'started' => time(), 'finished' => null, 'archive' => null,
        ]);
        self::writeFile($dir . '/render.json', (string) json_encode([
            'address' => $address, 'php' => true, 'instead' => '', 'secret' => bin2hex(random_bytes(32)),
        ]));
        $check->lock();
        foreach ($check->seeds() as $key) {
            $check->enqueue($key, '');
        }
        $check->save();
        $check->unlock();

        return $check;
    }

    /** Every link check's id, newest first. @return list<string> */
    public static function checkIds(): array
    {
        $ids = [];
        foreach (is_dir(self::checkRoot()) ? (scandir(self::checkRoot()) ?: []) : [] as $name) {
            if (self::isId($name) && is_dir(self::checkRoot() . '/' . $name)) {
                $ids[] = $name;
            }
        }
        rsort($ids);
        return $ids;
    }

    /** A link check already started, or null. */
    public static function openCheck(string $id): ?self
    {
        if (!self::isId($id)) {
            return null;
        }
        $dir = self::checkRoot() . '/' . $id;
        $job = json_decode((string) @file_get_contents($dir . '/job.json'), true);
        return is_array($job) && !empty($job['check']) ? new self($dir, $job) : null;
    }

    /**
     * What a check found: each address on the site that a link reaches and
     * that finds nothing, what it is (`page`: not found; `file`: not there;
     * `error`: the page failed to draw), and the pages that link to it.
     *
     * @return list<array{to: string, kind: string, from: list<string>}>
     */
    public function broken(): array
    {
        $out = [];
        foreach ((array) ($this->job['broken'] ?? []) as $to => $kind) {
            $from = array_keys((array) ($this->job['links'][$to] ?? []));
            $out[] = ['to' => (string) $to, 'kind' => (string) $kind, 'from' => array_map('strval', $from)];
        }
        usort($out, static fn (array $a, array $b): int => [count($b['from']), $a['to']] <=> [count($a['from']), $b['to']]);
        return $out;
    }

    /**
     * The copy's address as typed, checked and tidied: a full web address,
     * with no query, fragment or sign-in, and no trailing slash.
     */
    public static function address(string $typed): string
    {
        $part = parse_url(trim($typed));
        $scheme = strtolower((string) ($part['scheme'] ?? ''));
        $host   = strtolower((string) ($part['host'] ?? ''));
        if (!is_array($part) || !in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($part['query']) || isset($part['fragment']) || isset($part['user']) || isset($part['pass'])
            || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            throw new InvalidArgumentException('The address has to be a full web address, such as https://www.example.com.');
        }
        $path = rtrim((string) ($part['path'] ?? ''), '/');
        if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path) !== 1) {
            throw new InvalidArgumentException('The address can end in a folder, such as https://www.example.com/archive, and nothing else.');
        }
        return $scheme . '://' . $host . (isset($part['port']) ? ':' . (int) $part['port'] : '') . $path;
    }

    public function id(): string
    {
        return (string) $this->job['id'];
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** The folder that holds the copy as it will be published. */
    public function siteDir(): string
    {
        return $this->dir . '/site';
    }

    public function done(): bool
    {
        return $this->job['phase'] === 'done';
    }

    /** The words shown in place of a form the copy cannot send. */
    public function instead(): string
    {
        return (string) ($this->job['instead'] ?? '');
    }

    /**
     * Where it stands, for a screen or a terminal.
     *
     * @return array{address: string, php: bool, anywhere: bool, phase: string, pages: int, files: int, queued: int,
     *               problems: list<array{level: string, at: string, what: string}>, archive: ?string,
     *               started: int, finished: ?int}
     */
    public function status(): array
    {
        return [
            'address'  => (string) $this->job['address'],
            'php'      => (bool) $this->job['php'],
            'anywhere' => (bool) ($this->job['anywhere'] ?? false),
            'phase'    => (string) $this->job['phase'],
            'pages'    => (int) $this->job['pages'],
            'files'    => (int) $this->job['files'],
            'queued'   => count($this->job['queue']),
            'problems' => $this->job['problems'],
            'archive'  => $this->job['archive'] !== null ? $this->dir . '/' . $this->job['archive'] : null,
            'started'  => (int) $this->job['started'],
            'finished' => $this->job['finished'] !== null ? (int) $this->job['finished'] : null,
        ];
    }

    /**
     * Go on for about `$seconds`. True once the copy is finished and packed.
     * One step at a time per job: a second caller waits for the first.
     */
    public function step(float $seconds): bool
    {
        $this->lock();
        try {
            // Re-read under the lock: another step may have moved it on.
            $job = json_decode((string) @file_get_contents($this->dir . '/job.json'), true);
            if (is_array($job)) {
                $this->job = $job;
            }
            $until = microtime(true) + max(0.0, $seconds);
            do {
                if ($this->job['queue'] !== []) {
                    [$key, $from] = array_shift($this->job['queue']);
                    $this->one((string) $key, (string) $from);
                } elseif ($this->job['phase'] === 'pages' && !empty($this->job['check'])) {
                    // A CHECK WRITES NOTHING, so it has nothing to finish.
                    $this->job['phase']    = 'done';
                    $this->job['finished'] = time();
                } elseif ($this->job['phase'] === 'pages') {
                    $this->extras();
                    $this->job['phase'] = 'extras';
                } elseif ($this->job['phase'] === 'extras') {
                    $this->pack();
                    $this->job['phase']    = 'done';
                    $this->job['finished'] = time();
                } else {
                    break;
                }
            } while (microtime(true) < $until);
            $this->save();
        } finally {
            $this->unlock();
        }
        return $this->done();
    }

    // ----------------------------------------------------------------
    // What the copy starts from
    // ----------------------------------------------------------------

    /**
     * The addresses the crawl starts from: the front page, the sitemaps and
     * robots.txt, and every published Page, live entry and archive — the
     * sitemap leaves out what the owner marked "not in search results", and
     * those pages are still on the site.
     *
     * @return list<string>
     */
    private function seeds(): array
    {
        $keys = ['/', '/sitemap.xml', '/robots.txt'];
        if (is_file(PUBLIC_PATH . '/favicon.ico')) {
            $keys[] = '/favicon.ico';
        }
        // THE SEARCH PAGE, which a search box anywhere sends its visitor to,
        // with its index beside it (`extras()`).
        if (\Cms\Helpers\Modules::enabled('search')) {
            $keys[] = Url::pagePath('search');
        }
        $pdo = Database::getInstance();
        foreach ($pdo->query('SELECT slug FROM pages WHERE ' . \Cms\Models\Page::live() . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $keys[] = Url::pagePath((string) $slug);
        }
        foreach (Registry::all() as $key => $type) {
            $slug = (string) ($type['public_slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $keys[] = Url::pagePath($slug);
            $stmt = $pdo->prepare('SELECT slug FROM posts WHERE post_type = :t AND ' . Post::live() . ' ORDER BY id');
            $stmt->execute([':t' => (string) $key]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $entry) {
                $keys[] = Url::pagePath($slug . '/' . $entry);
            }
        }
        return $keys;
    }

    // ----------------------------------------------------------------
    // One address
    // ----------------------------------------------------------------

    private function one(string $key, string $from): void
    {
        [$path] = self::split($key);
        switch ($this->kind($path)) {
            case 'file':
                $file = $this->publicFile($path);
                if ($file !== null) {
                    $this->copyFile($file, rawurldecode(ltrim($path, '/')), $key);
                }
                return;
            case 'theme':
                $parts = explode('/', substr($path, strlen('/assets/theme/')), 2);
                $file  = count($parts) === 2 ? Theme::assetFile(rawurldecode($parts[0]), rawurldecode($parts[1])) : null;
                if ($file === null) {
                    $this->problem(self::NOTE, $from !== '' ? $from : $key, 'links to ' . $key . ', which is not in the theme');
                    if (!empty($this->job['check'])) {
                        $this->job['broken'][$key] = 'file';
                    }
                    return;
                }
                $this->copyFile($file, rawurldecode(ltrim($path, '/')), $key);
                return;
            case 'page':
                $this->page($key, $from);
                return;
        }
    }

    /** A page, drawn by the CMS, and what it links to queued. */
    private function page(string $key, string $from): void
    {
        // A REDIRECT RULE ANSWERS FIRST, as it does on the site: its old
        // address becomes a page that sends the visitor on. This one is
        // linked, so even a `/*` rule's address gets its page here.
        $rule = $key === '/' ? null : Redirects::forRequest(rtrim(self::split($key)[0], '/'));
        if ($rule !== null) {
            if (empty($this->job['check'])) {
                $this->stub($key, $this->ruleLocation($rule));
            }
            return;
        }

        [$status, $body, $error] = $this->render($key);
        if ($error !== null) {
            $this->problem(self::ERROR, $key, $error);
        }
        if (!empty($this->job['check']) && ($status === 404 || $status === 0 || $status >= 500)) {
            // A FILE THAT IS NOT THERE reaches the CMS as an address it does
            // not know, and answers not found like a page: its name says
            // which it was meant to be (`uploads/…`, `.png`).
            $ext = strtolower(pathinfo(self::split($key)[0], PATHINFO_EXTENSION));
            $this->job['broken'][$key] = $status !== 404 ? 'error'
                : (str_starts_with(self::split($key)[0], '/uploads/') || ($ext !== '' && !in_array($ext, ['html', 'htm', 'xml', 'php'], true))
                    ? 'file' : 'page');
        }
        if ($status === 404) {
            if ($from !== '') {
                $this->problem(self::NOTE, $from, 'links to ' . $key . ', which is not found on the site either');
            }
            return;
        }
        if ($status !== 200) {
            $this->problem(self::ERROR, $key, 'answered ' . $status . ($status >= 300 && $status < 400
                ? ', a redirect the copy cannot follow (it is not a rule on Tools → Redirects)' : '') . '; not copied');
            return;
        }
        // A PAGE BEHIND A PASSWORD IS LEFT OUT. Its gate posts the password to
        // the page itself, which a static host cannot answer, and a copy has
        // no way to keep a page private: the page's own words are never drawn
        // to a signed-out visitor, so nothing of it reaches the copy.
        if (str_contains($body, 'name="content_password"')) {
            $this->problem(self::NOTE, $key, 'is behind a password, and a copy cannot keep a page private; left out');
            return;
        }

        $this->writePage($key, $body);
    }

    /** A drawn page's bytes, made the copy's, written, and what it links to queued. */
    private function writePage(string $key, string $body, ?string $file = null): void
    {
        [$path] = self::split($key);
        $file ??= self::outFile($key);
        if ($file === null) {
            $this->problem(self::NOTE, $key, 'is an address no file can be named after; not copied');
            return;
        }

        $body = $this->sourceToCopy($body);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!empty($this->job['check'])) {
            if ($ext === '' || $ext === 'html') {
                $this->discoverHtml($body, $key);
            }
            $this->job['pages']++;
            return;
        }
        if ($ext === 'xml') {
            preg_match_all('~<(?:loc|image:loc)>\s*([^<\s]+)\s*</~i', $body, $m);
            foreach ($m[1] as $address) {
                $this->found(html_entity_decode($address, ENT_QUOTES | ENT_XML1, 'UTF-8'), $path, false, $key);
            }
        } elseif ($ext === '' || $ext === 'html') {
            $body = $this->forms($body, $key);
            $this->discoverHtml($body, $key);
        }
        if ($ext === '' || $ext === 'html' || $ext === 'xml') {
            $body = $this->pagedLinks($body);
        }
        // LINKS THAT WORK ANYWHERE, after everything above has read them as
        // the addresses they are. Not on the not-found page: a server shows
        // it at whatever depth the missing address had, where a relative link
        // would point somewhere else each time.
        if ($ext === '' || $ext === 'html') {
            $body = $this->anywhere($body, $file);
        }

        $this->put($file, $body, $key);
        $this->job['pages']++;
    }

    /** `relativeLinks()`, when the copy is to work anywhere; the not-found page as it is. */
    private function anywhere(string $text, string $file, bool $css = false): string
    {
        if (empty($this->job['anywhere']) || $file === '404.html') {
            return $text;
        }
        return self::relativeLinks($text, $file, (string) $this->job['address'], $css);
    }

    /**
     * THE FORMS ON A PAGE. The CMS drew its own for the copy already
     * (`Helpers\Shortcode` in export mode): those posting to the copy's
     * handler are noted, so the handler is written for them, and so are those
     * shown as words instead. A form a theme draws itself still posts to the
     * site's `/forms/{id}/submit`: with PHP it is pointed at the copy's
     * handler, with its fresh-token script; without, it gives its place to
     * the words, as the CMS's own forms do.
     */
    private function forms(string $html, string $key): string
    {
        preg_match_all('~\baction="[^"]*/' . Forms::FOLDER . '/send\.php\?form=(\d+)"~', $html, $m);
        foreach ($m[1] as $id) {
            $this->job['forms'][(int) $id] = true;
        }
        preg_match_all('~class="cms-form cms-form--instead[^"]*" data-form-id="(\d+)"~', $html, $m);
        foreach ($m[1] as $id) {
            $this->job['swapped'][(int) $id] ??= $key;
        }

        if (preg_match('~<form\b[^>]*\baction="[^"]*/forms/\d+/submit"~i', $html) !== 1) {
            return $html;
        }
        $base = rtrim((string) (parse_url((string) $this->job['address'], PHP_URL_PATH) ?? ''), '/');
        if ((bool) $this->job['php']) {
            $html = (string) preg_replace_callback('~(<form\b[^>]*\baction=")[^"]*/forms/(\d+)/submit(")~i', function (array $m) use ($base, $key): string {
                $this->job['forms'][(int) $m[2]] = true;
                $this->problem(self::NOTE, $key, 'draws form ' . $m[2] . ' itself; it now posts to the copy\'s form handler');
                return $m[1] . $base . '/' . Forms::FOLDER . '/send.php?form=' . $m[2] . $m[3];
            }, $html);
            if (!str_contains($html, '/assets/js/form-copy.js')) {
                $script = '<script src="' . $base . '/assets/js/form-copy.js"></script>';
                $html = str_contains($html, '</body>') ? (string) preg_replace('~</body>~i', $script . "\n</body>", $html, 1) : $html . $script;
            }
            return $html;
        }
        return (string) preg_replace_callback('~<form\b[^>]*\baction="[^"]*/forms/(\d+)/submit"[^>]*>.*?</form>~is', function (array $m) use ($key): string {
            $this->job['swapped'][(int) $m[1]] ??= $key;
            return Forms::instead((int) $m[1], (string) $this->job['instead']);
        }, $html);
    }

    /** A file as it is; a stylesheet made the copy's, and what it uses queued. */
    private function copyFile(string $source, string $relative, string $key): void
    {
        if (!empty($this->job['check'])) {
            if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'css') {
                foreach (self::cssUrls((string) file_get_contents($source)) as $url) {
                    $this->found($url, '/' . $relative, true, $key);
                }
            }
            $this->job['files']++;
            return;
        }
        if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'css') {
            $css = $this->sourceToCopy((string) file_get_contents($source));
            foreach (self::cssUrls($css) as $url) {
                $this->found($url, '/' . $relative, true, $key);
            }
            $this->put($relative, $this->anywhere($css, $relative, true), $key);
        } else {
            $target = $this->siteDir() . '/' . $relative;
            $this->mkdirFor($target);
            if (!@copy($source, $target)) {
                $this->problem(self::ERROR, $key, 'could not be copied');
                return;
            }
            $this->job['written'][$relative] = $key;
        }
        $this->job['files']++;
    }

    // ----------------------------------------------------------------
    // Drawing a page — the child process
    // ----------------------------------------------------------------

    /**
     * The page at `$key` as the CMS draws it at the copy's address.
     *
     * @return array{0: int, 1: string, 2: ?string} status, body, and what went
     *         wrong: a fatal, a line in the error log naming this site, or no
     *         answer at all
     */
    private function render(string $key): array
    {
        $body = $this->dir . '/body.tmp';
        @unlink($body);
        $tmp = $this->dir . '/tmp';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }

        $command = [
            self::php(),
            '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_reporting=' . E_ALL,
            '-d', 'error_log=' . $this->dir . '/errors.log',
            '-d', 'session.save_path=' . $tmp, '-d', 'sys_temp_dir=' . $tmp,
            __DIR__ . '/render-page.php', PUBLIC_PATH . '/index.php', $this->dir . '/render.json', $key, $body,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PUBLIC_PATH);
        if (!is_resource($process)) {
            return [0, '', 'could not start PHP to draw it'];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $answer = json_decode((string) strrchr("\n" . trim($out), "\n"), true);
        $logged = $this->newLogLines();
        if (!is_array($answer)) {
            return [0, '', 'gave no answer' . (trim($err . $out) !== '' ? ': ' . self::clip(trim($err . "\n" . $out)) : '')];
        }
        $error = $answer['fatal'] ?? null;
        if ($error === null && $logged !== []) {
            $error = 'logged ' . count($logged) . ' error' . (count($logged) === 1 ? '' : 's') . ': ' . self::clip($logged[0]);
        }
        return [(int) ($answer['status'] ?? 0), (string) @file_get_contents($body), $error !== null ? (string) $error : null];
    }

    /** The lines the job's error log gained since the last look that name this site. */
    private function newLogLines(): array
    {
        $log = $this->dir . '/errors.log';
        clearstatcache(true, $log);
        $size = is_file($log) ? (int) filesize($log) : 0;
        if ($size <= (int) $this->job['log']) {
            return [];
        }
        $fh = fopen($log, 'rb');
        if ($fh === false) {
            return [];
        }
        fseek($fh, (int) $this->job['log']);
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        $this->job['log'] = $size;

        $site = dirname(ROOT_PATH) . '/';
        return array_values(array_filter(
            preg_split('/\R/', $text) ?: [],
            static fn (string $line): bool => trim($line) !== '' && str_contains($line, $site)
        ));
    }

    /**
     * WHY THIS REQUEST CANNOT MAKE A COPY, or null when it can: the copy draws
     * each page in a PHP process of its own, which a host that switches off
     * `proc_open`, or keeps no command-line PHP, does not allow. The admin then
     * says so and gives the command (`admin/bin/export-static.php`) instead of
     * a button that would fail. Asked once a request: it starts PHP to ask.
     */
    public static function cannotRun(): ?string
    {
        static $answer = false;
        if ($answer !== false) {
            return $answer;
        }
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $off, true)) {
            return $answer = 'This server does not let the CMS start PHP, which a copy needs to draw each page.';
        }
        $process = @proc_open([self::php(), '-r', 'echo PHP_SAPI, " ", PHP_VERSION_ID;'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return $answer = 'This server would not start PHP from the admin, which a copy needs to draw each page.';
        }
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        [$sapi, $version] = array_pad(explode(' ', $out), 2, '0');
        if ($sapi !== 'cli' || (int) $version < 80200) {
            return $answer = 'No command-line PHP 8.2 or later was found beside the one serving the admin.';
        }
        return $answer = null;
    }

    /**
     * The command-line PHP: this one when the job runs from the command line;
     * from a web request, the `php` beside the one serving it.
     */
    public static function php(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        foreach ([PHP_BINDIR . '/php', PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'php';
    }

    // ----------------------------------------------------------------
    // Addresses
    // ----------------------------------------------------------------

    /**
     * Whether only a server can answer this path: what it is (SERVER_ONLY's
     * words, or the sign-in page's), or null when the copy can have it.
     */
    public static function serverOnly(string $path): ?string
    {
        foreach (self::SERVER_ONLY as $prefix => $what) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $what;
            }
        }
        if (LoginUrl::enabled() && rtrim($path, '/') === LoginUrl::path()) {
            return 'the sign-in page';
        }
        return null;
    }

    /** What an address on this site is to the copy: a page, a file, a theme's file, or server-only. */
    private function kind(string $path): string
    {
        if (self::serverOnly($path) !== null) {
            return 'server';
        }
        if (str_starts_with($path, '/assets/theme/')) {
            return 'theme';
        }
        if ($this->publicFile($path) !== null) {
            return 'file';
        }
        return 'page';
    }

    /**
     * The file under the web root an address names, when it is one a visitor
     * may be sent: never code, a dotfile or a private upload.
     */
    private function publicFile(string $path): ?string
    {
        $relative = rawurldecode(ltrim($path, '/'));
        if ($relative === '' || preg_match('/[\x00-\x1f\\\\]/', $relative) === 1
            || preg_match('/\.(php\d?|phtml|phar|inc|htaccess|htpasswd|ini|log|sql)$/i', $relative) === 1
            || str_starts_with($relative, 'uploads/private/')) {
            return null;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                return null;
            }
        }
        $file = PUBLIC_PATH . '/' . $relative;
        return is_file($file) ? $file : null;
    }

    /** Queue an address once, in the shape the copy keys it by. */
    private function enqueue(string $key, string $from): void
    {
        [$path, $query] = self::split($key);
        $kind = $this->kind($path);
        if ($kind === 'server') {
            // NOTED, BY WHAT IT IS: a link to the basket on every page is one
            // line in the report, with the first page that has it. The copy's
            // own form handler is not a link to the site.
            $what = self::serverOnly($path);
            if ($from !== '' && $what !== null && $path !== '/' . Forms::FOLDER . '/send.php') {
                $this->job['server'][$what] ??= ['path' => $path, 'from' => $from, 'pages' => []];
                $this->job['server'][$what]['pages'][$from] = true;
            }
            return;
        }
        if ($kind === 'page') {
            $path = Url::pagePath($path);
            parse_str($query, $q);
            $n   = is_string($q['page'] ?? null) && ctype_digit($q['page']) ? (int) $q['page'] : 1;
            $key = $path . ($n > 1 ? '?page=' . $n : '');
        } else {
            $key = $path;
        }
        // WHERE EACH LINK IS, in a check: a broken address is listed with the
        // pages that link to it, not only the first one found.
        if (!empty($this->job['check']) && $from !== '' && count($this->job['links'][$key] ?? []) < 25) {
            $this->job['links'][$key][self::split($from)[0]] = true;
        }
        if (isset($this->job['seen'][$key])) {
            return;
        }
        if (count($this->job['seen']) >= self::MAX_ADDRESSES) {
            if (!isset($this->job['seen']['#full'])) {
                $this->job['seen']['#full'] = true;
                $this->problem(self::ERROR, $from, 'the copy stopped at ' . self::MAX_ADDRESSES . ' addresses');
            }
            return;
        }
        $this->job['seen'][$key] = true;
        $this->job['queue'][] = [$key, $from];
    }

    /** An address found in a page or a stylesheet, queued when it is on this site. */
    private function found(string $value, string $docPath, bool $relative, string $from): void
    {
        $key = self::siteKey($value, $docPath, $relative, (string) $this->job['address']);
        if ($key !== null) {
            $this->enqueue($key, $from);
        }
    }

    /**
     * An address as a key — its path below the copy's own (`$address`), and
     * its query — when it is on this site; null for anywhere else, and for
     * what is not an address. A relative one is read against the document's
     * path only where `$relative` says an address is expected (`href`, `src`,
     * a stylesheet's `url()`), never in an attribute that may hold words.
     */
    public static function siteKey(string $value, string $docPath, bool $relative, string $address): ?string
    {
        $value = trim($value);
        if ($value === '' || $value[0] === '#' || ($value[0] === '?' && !$relative)) {
            return null;
        }
        $copy = parse_url($address) ?: [];
        $base = rtrim((string) ($copy['path'] ?? ''), '/');

        if (str_starts_with($value, '//')) {
            $value = (string) ($copy['scheme'] ?? 'https') . ':' . $value;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) === 1) {
            $part = parse_url($value);
            if (!is_array($part) || !in_array(strtolower((string) ($part['scheme'] ?? '')), ['http', 'https'], true)
                || strtolower((string) ($part['host'] ?? '')) !== strtolower((string) ($copy['host'] ?? ''))
                || ($part['port'] ?? null) !== ($copy['port'] ?? null)) {
                return null;
            }
            $path  = (string) ($part['path'] ?? '/');
            $query = (string) ($part['query'] ?? '');
        } elseif ($value[0] === '/') {
            [$path, $query] = self::split(explode('#', $value, 2)[0]);
        } elseif ($relative && preg_match('#^[A-Za-z0-9._~%!$&\'()*+,;=:@/?-]+$#', $value) === 1) {
            // `?page=2` is the document's own address; `a.css` its folder's.
            $dir = $value[0] === '?'
                ? $base . $docPath
                : substr($base . $docPath, 0, (int) strrpos($base . $docPath, '/') + 1);
            [$path, $query] = self::split(explode('#', $dir . $value, 2)[0]);
        } else {
            return null;
        }

        // Below the copy's own folder, with `.` and `..` settled.
        $path = self::dotless($path);
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) {
                return null;
            }
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');
        return $path . ($query !== '' ? '?' . $query : '');
    }

    /** `/a/./b/../c` → `/a/c`. */
    private static function dotless(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $joined = implode('/', $out);
        return str_starts_with($joined, '/') ? $joined : '/' . $joined;
    }

    /** @return array{0: string, 1: string} path, query */
    private static function split(string $key): array
    {
        $cut = strpos($key, '?');
        return $cut === false ? [$key, ''] : [substr($key, 0, $cut), substr($key, $cut + 1)];
    }

    /**
     * The file a page's key is written to: `/` → `index.html`, `/news` →
     * `news/index.html`, `/news?page=2` → `news/page/2/index.html`, and an
     * address that names a file (`/sitemap.xml`) → that name. Null when a
     * segment cannot be a file's name.
     */
    public static function outFile(string $key): ?string
    {
        [$path, $query] = self::split($key);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            $segment = rawurldecode($segment);
            if ($segment === '.' || $segment === '..' || $segment[0] === '.'
                || preg_match('~[\x00-\x1f/\\\\]~', $segment) === 1) {
                return null;
            }
            $segments[] = $segment;
        }
        parse_str($query, $q);
        $n = is_string($q['page'] ?? null) && ctype_digit($q['page']) ? (int) $q['page'] : 1;
        $last = $segments !== [] ? $segments[count($segments) - 1] : '';
        if ($n <= 1 && str_contains($last, '.')) {
            return implode('/', $segments);
        }
        if ($n > 1) {
            $segments[] = 'page';
            $segments[] = (string) $n;
        }
        return ($segments === [] ? '' : implode('/', $segments) . '/') . 'index.html';
    }

    // ----------------------------------------------------------------
    // Reading a page for what it uses
    // ----------------------------------------------------------------

    /**
     * Every address a page names: in an attribute (`srcset` read as its list),
     * in a stylesheet inside it, and written out whole anywhere else —
     * structured data, a script's settings.
     */
    private function discoverHtml(string $html, string $key): void
    {
        $doc = self::split($key)[0];
        preg_match_all('~\s(href|src|action|poster|srcset|data-[a-z0-9_.:-]+|content)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $html, $m, PREG_SET_ORDER);
        foreach ($m as $a) {
            $name  = strtolower($a[1]);
            $value = html_entity_decode(($a[2] ?? '') !== '' ? $a[2] : ($a[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name === 'srcset' || $name === 'data-srcset') {
                foreach (self::srcset($value) as $url) {
                    $this->found($url, $doc, true, $key);
                }
                continue;
            }
            $this->found($value, $doc, in_array($name, ['href', 'src', 'action', 'poster'], true), $key);
        }
        foreach (self::cssUrls($html) as $url) {
            $this->found(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $doc, true, $key);
        }
        preg_match_all('~https?://[^\s"\'<>()\\\\]+~i', str_replace('\/', '/', $html), $m);
        foreach (array_unique($m[0]) as $url) {
            $this->found(rtrim($url, '.,;:!?'), $doc, false, $key);
        }
    }

    /** The addresses in a `srcset`: "a.webp 1x, b.webp 2x". */
    private static function srcset(string $value): array
    {
        $out = [];
        foreach (preg_split('/,\s+/', trim($value)) ?: [] as $candidate) {
            $url = strtok(trim($candidate), " \t\n");
            if ($url !== false && $url !== '') {
                $out[] = $url;
            }
        }
        return $out;
    }

    /** The addresses in a stylesheet: `url(…)` and `@import "…"`. */
    private static function cssUrls(string $css): array
    {
        preg_match_all('~url\(\s*(["\']?)([^"\')]+)\1\s*\)~i', $css, $a);
        preg_match_all('~@import\s+(["\'])([^"\']+)\1~i', $css, $b);
        return array_values(array_filter(array_merge($a[2], $b[2]),
            static fn (string $u): bool => !str_starts_with(trim($u), 'data:')));
    }

    // ----------------------------------------------------------------
    // Rewriting
    // ----------------------------------------------------------------

    private function sourceToCopy(string $text): string
    {
        return self::rewriteSource($text, (string) $this->job['source'], (string) $this->job['address']);
    }

    private function pagedLinks(string $text): string
    {
        return self::pageLinks($text, (string) $this->job['address'], Url::trailingSlash());
    }

    /**
     * The site's own address (`$source`), where a page still names it — an
     * image an editor pasted with its full address, a link typed whole — made
     * the copy's (`$copy`). The CMS's own links already are: the child drew
     * them at the copy's address.
     */
    public static function rewriteSource(string $text, string $source, string $copy): string
    {
        if ($source === '' || $source === $copy) {
            return $text;
        }
        $part = parse_url($source) ?: [];
        $host = preg_quote(strtolower((string) ($part['host'] ?? '')) . (isset($part['port']) ? ':' . $part['port'] : ''), '~');
        $path = preg_quote(rtrim((string) ($part['path'] ?? ''), '/'), '~');
        if ($host === '') {
            return $text;
        }
        // THE COPY'S OWN ADDRESSES ARE SET ASIDE FIRST. A copy published in a
        // folder of the same site (`https://site/archive`) has the site's
        // address inside its own, and would otherwise be rewritten twice.
        $escaped = str_replace('/', '\/', $copy);
        $marks   = ["\x00copy\x00" => $copy, "\x00json\x00" => $escaped];
        $text    = str_replace([$copy, $escaped], array_keys($marks), $text);
        // Plain, then as JSON writes it (`https:\/\/host`), each only where the
        // host ends: `host.example` is somebody else.
        $text = (string) preg_replace('~(?:https?:)?//' . $host . $path . '(?![A-Za-z0-9.-])~i', str_replace(['\\', '$'], ['\\\\', '\\$'], $copy), $text);
        $jsonPath = str_replace('/', '\\\\/', $path);
        $text = (string) preg_replace('~(?:https?:)?\\\\/\\\\/' . $host . $jsonPath . '(?![A-Za-z0-9.-])~i', str_replace(['\\', '$'], ['\\\\', '\\$'], $escaped), $text);
        return str_replace(array_keys($marks), array_values($marks), $text);
    }

    /**
     * `?page=N` made a path: `/news?page=2` → `/news/page/2/`, the file the
     * copy wrote it to, in the ending Settings → Permalinks chose. Whole
     * addresses on the copy's host, and paths that start an attribute or a
     * `url()`; a page of a list whose address has other questions in it
     * (`?sort=…&page=2`) is not one the copy has.
     */
    public static function pageLinks(string $text, string $copy, bool $trailingSlash): string
    {
        $part  = parse_url($copy) ?: [];
        $base  = rtrim((string) ($part['path'] ?? ''), '/');
        $root  = substr($copy, 0, strlen($copy) - strlen($base));
        $slash = $trailingSlash ? '/' : '';
        $to = static function (array $m) use ($slash): string {
            return $m[1] . rtrim($m[2], '/') . '/page/' . $m[3] . $slash;
        };
        $tail = '\?page=([2-9]|[1-9][0-9]+)(?=["\'\s<>#)]|$)';
        $text = (string) preg_replace_callback('~(' . preg_quote($root . $base, '~') . ')(/[^"\'\s<>?#]*)?' . $tail . '~', static function (array $m) use ($to): string {
            $m[2] = $m[2] !== '' ? $m[2] : '/';
            return $to($m);
        }, $text);
        return (string) preg_replace_callback('~(?<=["\'(\s])(' . preg_quote($base, '~') . ')(/[^"\'\s<>?#]*)' . $tail . '~', $to, $text);
    }

    /**
     * LINKS THAT WORK ANYWHERE: every address on the copy's own site (`$copy`)
     * in a page — or, with `$css`, a stylesheet — made relative to `$file`,
     * the file it is written to, with a page's address made the file that
     * holds it (`/news` → `news/index.html`). A copy whose links are all
     * relative works at any address, in any folder of one, and opened from a
     * folder with no web server, where nothing turns `news/` into its file.
     *
     * WHAT STAYS WHOLE is what names the site to the world rather than to the
     * reader: a canonical, an alternate, a page's prev and next, every
     * `<meta>` (Open Graph's and Twitter's addresses), structured data and the
     * sitemap, which search engines and other sites read as full addresses.
     * So does an address only the site can answer (the basket, the admin);
     * the copy's own form handler is a file of the copy, and is made relative.
     */
    public static function relativeLinks(string $text, string $file, string $copy, bool $css = false): string
    {
        $url = static fn (string $value): string => self::relativeUrl($value, $file, $copy);
        $cssUrls = static function (string $style) use ($url): string {
            $style = (string) preg_replace_callback('~(url\(\s*(["\']?))([^"\')]+)(\2\s*\))~i',
                static fn (array $m): string => $m[1] . $url($m[3]) . $m[4], $style);
            return (string) preg_replace_callback('~(@import\s+(["\']))([^"\']+)(\2)~i',
                static fn (array $m): string => $m[1] . $url($m[3]) . $m[4], $style);
        };
        if ($css) {
            return $cssUrls($text);
        }

        $text = (string) preg_replace_callback('~(<style\b[^>]*>)(.*?)(</style>)~is',
            static fn (array $m): string => $m[1] . $cssUrls($m[2]) . $m[3], $text);

        $keep = ['stylesheet', 'icon', 'preload', 'modulepreload', 'prefetch', 'manifest', 'apple-touch-icon', 'mask-icon'];
        return (string) preg_replace_callback('~<([a-zA-Z][a-zA-Z0-9-]*)(\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?>~',
            static function (array $m) use ($url, $cssUrls, $keep): string {
                $tag   = strtolower($m[1]);
                $attrs = $m[2] ?? '';
                if ($attrs === '' || $tag === 'meta') {
                    return $m[0];
                }
                // A <link> is the reader's only when it fetches something.
                if ($tag === 'link') {
                    $rel = preg_match('~\srel\s*=\s*(["\'])([^"\']*)\1~i', $attrs, $r) === 1
                        ? (preg_split('/\s+/', strtolower(trim($r[2]))) ?: []) : [];
                    if (array_intersect($rel, $keep) === []) {
                        return $m[0];
                    }
                }
                $attrs = (string) preg_replace_callback(
                    '~(\s(href|src|action|poster|srcset|style|data-[a-z0-9_.:-]+)\s*=\s*)(["\'])(.*?)\3~is',
                    static function (array $a) use ($url, $cssUrls): string {
                        $name = strtolower($a[2]);
                        if ($name === 'style') {
                            $value = $cssUrls($a[4]);
                        } elseif ($name === 'srcset' || $name === 'data-srcset') {
                            $value = implode(', ', array_map(static function (string $candidate) use ($url): string {
                                $bits = preg_split('/\s+/', trim($candidate), 2) ?: [''];
                                return $url($bits[0]) . (isset($bits[1]) ? ' ' . $bits[1] : '');
                            }, explode(',', $a[4])));
                        } else {
                            $value = $url($a[4]);
                        }
                        return $a[1] . $a[3] . $value . $a[3];
                    },
                    $attrs
                );
                return '<' . $m[1] . $attrs . '>';
            },
            $text
        );
    }

    /**
     * One address, as `relativeLinks()` makes it: relative to `$file` when it
     * is on the copy's own site and the copy has it, its question and
     * fragment kept; anything else as given.
     */
    public static function relativeUrl(string $value, string $file, string $copy): string
    {
        $part   = parse_url($copy) ?: [];
        $base   = rtrim((string) ($part['path'] ?? ''), '/');
        $origin = substr($copy, 0, strlen($copy) - strlen($base));

        $cut  = strcspn($value, '?#');
        $path = substr($value, 0, $cut);
        $rest = substr($value, $cut);
        if (strcasecmp($path, $origin) === 0 || stripos($path, $origin . '/') === 0) {
            $path = substr($path, strlen($origin));
            $path = $path === '' ? '/' : $path;
        } elseif (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $value;
        }
        if ($base !== '') {
            if ($path !== $base && !str_starts_with($path, $base . '/')) {
                return $value;
            }
            $path = substr($path, strlen($base));
            $path = $path === '' ? '/' : $path;
        }
        if ($path !== '/' . Forms::FOLDER . '/send.php' && self::serverOnly($path) !== null) {
            return $value;
        }

        $target = ltrim($path, '/');
        $last   = (string) substr($target, (int) strrpos('/' . $target, '/'));
        if ($last === '' || !str_contains($last, '.')) {
            $target = ($target === '' ? '' : rtrim($target, '/') . '/') . 'index.html';
        }
        $from = str_contains($file, '/') ? explode('/', substr($file, 0, (int) strrpos($file, '/'))) : [];
        $to   = explode('/', $target);
        $same = 0;
        while ($same < count($from) && $same < count($to) - 1 && $from[$same] === $to[$same]) {
            $same++;
        }
        return str_repeat('../', count($from) - $same) . implode('/', array_slice($to, $same)) . $rest;
    }

    // ----------------------------------------------------------------
    // The rest of the copy
    // ----------------------------------------------------------------

    /**
     * What the crawl does not find: the not-found page, a page for each
     * redirect rule, and the web server's rules for a host that reads them.
     */
    private function extras(): void
    {
        // THE NOT-FOUND PAGE, drawn for an address that cannot exist.
        $missing = '/static-copy-not-found-' . bin2hex(random_bytes(6));
        [$status, $body, $error] = $this->render($missing);
        if ($error !== null) {
            $this->problem(self::ERROR, '404.html', $error);
        }
        if ($status === 404) {
            $this->writePage($missing, $body, '404.html');
        } else {
            $this->problem(self::ERROR, '404.html', 'an address that does not exist answered ' . $status . ', not 404');
        }

        // THE SEARCH INDEX, beside the copy's search page, which searches it in
        // the visitor's browser (`Public\SearchController`, `search-copy.js`).
        // A SCRIPT, NOT A JSON FILE: a page opened from a folder may load a
        // script, and may not fetch a file. Each result is made relative to
        // the search page, which is where it is shown. A result's card may
        // show a picture no other page does: it is queued.
        $search = self::outFile(Url::pagePath('search'));
        if ($search !== null && isset($this->job['written'][$search])) {
            [$status, $body, $error] = $this->render(Url::pagePath('search') . '?index=1');
            $index = $status === 200 ? json_decode($this->sourceToCopy($body), true) : null;
            if ($error !== null || !is_array($index)) {
                $this->problem(self::ERROR, Url::pagePath('search'), 'its index could not be made: '
                    . ($error ?? 'it answered ' . $status));
            } else {
                $items = is_array($index['items'] ?? null) ? $index['items'] : [];
                $this->discoverHtml(implode("\n", array_map('strval', array_column($items, 'h'))), Url::pagePath('search'));
                foreach ($items as $i => $item) {
                    $items[$i]['h'] = $this->anywhere((string) ($item['h'] ?? ''), $search);
                }
                $this->put(dirname($search) . '/index.js', 'window.cmsSearchIndex = '
                    . json_encode(['items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n",
                    Url::pagePath('search') . '?index=1');
            }
        }

        $this->redirects();
        $this->put('.htaccess', $this->htaccess(), '.htaccess');

        // THE COPY'S FORM HANDLER, for the forms it draws (`Export\Forms`).
        $render = json_decode((string) file_get_contents($this->dir . '/render.json'), true);
        $forms  = array_values(array_filter(array_map(
            static fn (int|string $id): ?array => \Cms\Models\Form::find((int) $id),
            array_keys($this->job['forms'])
        )));
        if ((bool) $this->job['php'] && $forms !== [] && is_array($render)) {
            $this->job['files'] += Forms::bundle($this->siteDir(), $render, $forms);
        }
        // WHAT THE PAGES STILL LINK TO THAT ONLY THE SITE CAN ANSWER: a theme's
        // own link to the basket, a private file in an entry's text. Left as
        // they are, for a person to change on the site before the next copy.
        foreach ($this->job['server'] ?? [] as $what => $seen) {
            $n = count($seen['pages']);
            $this->problem(self::NOTE, (string) $seen['from'], 'links to ' . $seen['path'] . ' (' . $what . '), which a copy cannot have'
                . ($n > 1 ? '; so do ' . ($n - 1) . ' other page' . ($n === 2 ? '' : 's') : ''));
        }

        // AND EVERY FORM IT COULD NOT SEND, by name, with why and what stands in its place.
        foreach ($this->job['swapped'] as $id => $page) {
            $form = \Cms\Models\Form::find((int) $id);
            $why  = $form !== null ? (Forms::unsendable($form, (bool) $this->job['php']) ?? 'it posts to the site') : 'it no longer exists';
            $this->problem(self::NOTE, (string) $page, 'form "' . (string) ($form['title'] ?? $id) . '" cannot be sent from the copy ('
                . $why . '); ' . ((string) $this->job['instead'] !== '' ? 'the words given for it are shown instead' : 'nothing is shown in its place'));
        }
    }

    /** @var list<string> the web server's lines for the redirect rules */
    private array $redirectLines = [];

    /**
     * EACH REDIRECT RULE, TWICE. A host that reads `.htaccess` (Apache) is
     * given the rule itself, with its status and its query string carried as
     * the site carries them. Any host is given a page at the rule's old
     * address that sends the visitor on — a static host has no other way to —
     * unless the copy already has a page there. A `/*` rule covers addresses
     * nobody has listed, so it has no pages, and only `.htaccess` keeps it.
     */
    private function redirects(): void
    {
        $copy = (string) $this->job['address'];
        $base = (string) (parse_url($copy, PHP_URL_PATH) ?? '');
        $stubs = 0;
        foreach (Redirects::all() as $rule) {
            $from = (string) $rule['from_path'];
            $type = in_array((int) $rule['type'], [301, 302, 307, 308], true) ? (int) $rule['type'] : 301;
            if (str_ends_with($from, '/*')) {
                $prefix = substr($from, 0, -1);
                $to = $this->sourceToCopy((string) $rule['to_url']);
                $to = str_starts_with($to, '/') ? rtrim($base, '/') . str_replace('*', '$1', $to) : str_replace('*', '$1', $to);
                $this->redirectLines[] = 'RedirectMatch ' . $type . ' "(?i)^' . self::regexPath(rtrim($base, '/') . $prefix) . '(.+)$" "' . self::quoted($to) . '"';
                continue;
            }
            $found = Redirects::forRequest($from);
            if ($found === null) {
                continue;
            }
            $location = $this->ruleLocation($found);
            $this->redirectLines[] = 'RedirectMatch ' . $type . ' "(?i)^' . self::regexPath(rtrim($base, '/') . $from) . '/?$" "' . self::quoted($location) . '"';
            if ($this->stub(Url::pagePath($from), $location)) {
                $stubs++;
            }
        }
        $this->job['redirects'] = $stubs;
    }

    /**
     * Where a rule sends a visitor, at the copy's address: a destination on
     * this site is the copy's page, anything else is left as typed.
     *
     * @param array{to: string, location: string} $rule `Redirects::forRequest()`'s answer
     */
    private function ruleLocation(array $rule): string
    {
        return Redirects::sitePath((string) $rule['to']) !== null
            ? (string) $this->job['address'] . (string) $rule['to']
            : $this->sourceToCopy((string) $rule['location']);
    }

    /**
     * A page at an old address that sends the visitor on — unless the copy
     * already has a page there. True when one was written.
     */
    private function stub(string $key, string $location): bool
    {
        $file = self::outFile($key);
        if ($file === null || isset($this->job['written'][$file])) {
            return false;
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // THE VISITOR IS SENT ON RELATIVELY in a copy that works anywhere; the
        // canonical names the new address whole, as every canonical does.
        $go = empty($this->job['anywhere']) ? $location : self::relativeUrl($location, $file, (string) $this->job['address']);
        $this->put($file, "<!doctype html>\n<html lang=\"" . $h(\Cms\Site\Language::current()) . "\">\n<head>\n<meta charset=\"utf-8\">\n"
            . '<title>Moved</title>' . "\n"
            . '<meta name="robots" content="noindex">' . "\n"
            . '<link rel="canonical" href="' . $h($location) . '">' . "\n"
            . '<meta http-equiv="refresh" content="0; url=' . $h($go) . '">' . "\n"
            . "</head>\n<body>\n"
            . '<p>This page has moved to <a href="' . $h($go) . '">' . $h($location) . "</a>.</p>\n"
            . "</body>\n</html>\n", $key);
        return true;
    }

    /**
     * For a host that reads it: the not-found page, the redirect rules, and,
     * for a site whose addresses end without a slash, `/about` answered from
     * `about/index.html` as it is, rather than with a hop to `/about/` that
     * every canonical in the copy would contradict. Each part only where its
     * module is loaded, so a host without one does not answer 500.
     */
    private function htaccess(): string
    {
        $base  = rtrim((string) (parse_url((string) $this->job['address'], PHP_URL_PATH) ?? ''), '/');
        $lines = ['# The web server\'s rules for this static copy, for a host that reads .htaccess (Apache).', ''];
        $lines[] = 'ErrorDocument 404 ' . $base . '/404.html';
        $lines[] = 'DirectoryIndex index.html';
        $lines[] = 'Options -Indexes';
        if ($this->redirectLines !== []) {
            $lines[] = '';
            $lines[] = '<IfModule mod_alias.c>';
            foreach ($this->redirectLines as $line) {
                $lines[] = '    ' . $line;
            }
            $lines[] = '</IfModule>';
        }
        // NOT FOR A COPY THAT WORKS ANYWHERE: its links are relative to the
        // folder a page is in, and `news/x` answered without its slash puts
        // the page one folder up. The server's own hop to `news/x/` keeps it
        // where its links expect it.
        if (!Url::trailingSlash() && empty($this->job['anywhere'])) {
            $lines[] = '';
            $lines[] = '<IfModule mod_rewrite.c>';
            $lines[] = '    <IfModule mod_dir.c>';
            $lines[] = '        DirectorySlash Off';
            $lines[] = '    </IfModule>';
            $lines[] = '    RewriteEngine On';
            $lines[] = '    RewriteCond %{REQUEST_FILENAME} -d';
            $lines[] = '    RewriteCond %{REQUEST_FILENAME}/index.html -f';
            $lines[] = '    RewriteRule ^(.*[^/])$ $1/index.html [L]';
            $lines[] = '</IfModule>';
        }
        return implode("\n", $lines) . "\n";
    }

    /** A path for a RedirectMatch pattern: its letters as they are, the rest escaped. */
    private static function regexPath(string $path): string
    {
        return (string) preg_replace('#[^A-Za-z0-9/_~-]#', '\\\\$0', $path);
    }

    /** A value inside double quotes in a server rule. */
    private static function quoted(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * THE COPY IN ONE FILE: a zip where PHP has the zip extension, else a
     * .tar.gz, which PHP can always write. The folder stays beside it.
     */
    private function pack(): void
    {
        $host = (string) (parse_url((string) $this->job['address'], PHP_URL_HOST) ?? 'site');
        $name = $host . '-static-' . gmdate('Y-m-d', (int) $this->job['started']);
        $site = $this->siteDir();

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS));
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($this->dir . '/' . $name . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create ' . $name . '.zip.');
            }
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($site) + 1));
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('Could not write ' . $name . '.zip.');
            }
            $this->job['archive'] = $name . '.zip';
            return;
        }

        $tar = new \PharData($this->dir . '/' . $name . '.tar');
        $tar->buildFromDirectory($site);
        $tar->compress(\Phar::GZ);
        unset($tar);
        @unlink($this->dir . '/' . $name . '.tar');
        $this->job['archive'] = $name . '.tar.gz';
    }

    // ----------------------------------------------------------------
    // Bookkeeping
    // ----------------------------------------------------------------

    /** Write a file of the copy, once: a second address that lands on the same file is a problem, not an overwrite. */
    private function put(string $relative, string $bytes, string $key): void
    {
        if (isset($this->job['written'][$relative]) && $this->job['written'][$relative] !== $key) {
            $this->problem(self::ERROR, $key, 'would be written to ' . $relative . ', which ' . $this->job['written'][$relative] . ' already is; not copied');
            return;
        }
        $target = $this->siteDir() . '/' . $relative;
        $this->mkdirFor($target);
        self::writeFile($target, $bytes);
        $this->job['written'][$relative] = $key;
    }

    private function mkdirFor(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }
    }

    private static function writeFile(string $file, string $bytes): void
    {
        if (file_put_contents($file, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write ' . $file . '.');
        }
    }

    private function problem(string $level, string $at, string $what): void
    {
        $this->job['problems'][] = ['level' => $level, 'at' => $at !== '' ? $at : '/', 'what' => $what];
    }

    private static function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
    }

    private function save(): void
    {
        self::writeFile($this->dir . '/job.json.tmp', (string) json_encode($this->job, JSON_UNESCAPED_SLASHES));
        if (!@rename($this->dir . '/job.json.tmp', $this->dir . '/job.json')) {
            throw new RuntimeException('Could not save ' . $this->dir . '/job.json.');
        }
    }

    private function lock(): void
    {
        $fh = fopen($this->dir . '/lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new RuntimeException('Could not lock ' . $this->dir . '.');
        }
        $this->lock = $fh;
    }

    private function unlock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }

    /** The site's own address, from config.php. */
    private static function sourceAddress(): string
    {
        $config = (static fn (string $__f): mixed => require $__f)(ROOT_PATH . '/config/config.php');
        return is_array($config) ? rtrim((string) ($config['base_url'] ?? ''), '/') : '';
    }
}
