<?php

declare(strict_types=1);

namespace Cms\Export;

use Cms\Database\Database;
use Cms\Forms\Embed;
use Cms\Forms\FieldTypes;
use Cms\Forms\Sources;
use Cms\Helpers\Url;
use PDO;
use RuntimeException;

/**
 * A STATIC COPY'S FORMS (`Export\StaticCopy`).
 *
 * ── ON A HOST THAT RUNS PHP ─────────────────────────────────────────────────
 * The copy carries a form handler of its own in `forms/` (`Export\Receiver`):
 * the CMS's form classes as they are, and what they need to know written down
 * — each form the copy draws, the settings those classes read, and a secret of
 * the copy's own. A form posts to `forms/send.php?form={id}`, and
 * `form-copy.js` gives it a fresh token from there as its page loads. A
 * message is checked as the site checks it and mailed as the site mails it.
 *
 * ── WHAT CANNOT BE SENT FROM A COPY ─────────────────────────────────────────
 * Everything, on a host without PHP; a form that books appointments, which
 * needs the site's record of what is taken; and a form that names nobody to
 * send to, as a copy has no inbox to keep its answers in. Such a form is not
 * drawn: its place shows the words the person making the copy gave for it
 * ("Email us at …"), or nothing when they gave none, and the copy's report
 * names each one. A form drawn and not sendable would be a control that does
 * nothing.
 *
 * ── NOTHING SECRET LEAVES ───────────────────────────────────────────────────
 * The copy's secret is its own, not the site's `app_secret`. No password goes
 * with it: the handler sends through the host's own mail program, and the
 * built-in captcha stands in for a service whose secret key would have to go.
 */
final class Forms
{
    /**
     * The CMS's own files the copy's handler runs as they are, below
     * `admin/core/` — and below `forms/lib/Cms/` in the copy.
     */
    public const CARRIED = [
        'Forms/Answers.php', 'Forms/Captcha.php', 'Forms/Embed.php', 'Forms/FieldTypes.php', 'Forms/Guard.php',
        'Forms/Logic.php', 'Forms/Notify.php', 'Forms/Preview.php', 'Forms/Upload.php',
        'Helpers/Dates.php', 'Http/Cache.php', 'Media/Optimizer.php', 'Security/ClientIp.php',
        // How Guard and ClientIp read the copy's own config/config.php.
        'Site/ConfigFile.php',
        'Export/Receiver/Handler.php', 'Export/Receiver/Store.php',
    ];

    /** The mail library's files the handler uses, below `admin/vendor/phpmailer/phpmailer/`. */
    private const MAILER = ['src/PHPMailer.php', 'src/SMTP.php', 'src/Exception.php', 'LICENSE'];

    /**
     * The settings the carried classes read, written into the copy: the forms'
     * own (`forms_…`), the clock's, the site's language, and the sender's
     * name and address. Never a password or a key.
     */
    private const OPTIONS = ['timezone', 'date_format', 'time_format', 'site_name', 'site_language', 'smtp_from_email', 'smtp_from_name'];

    /** Where the handler lives in the copy. `forms` is a reserved first segment: no page can be there. */
    public const FOLDER = 'forms';

    /**
     * Why the copy cannot send this form, or null when it can.
     *
     * @param array<string, mixed> $form `Form::find()`'s row
     */
    public static function unsendable(array $form, bool $php): ?string
    {
        if (!$php) {
            return 'the copy is for a host without PHP';
        }
        foreach (Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []) as $field) {
            if (($field['type'] ?? '') === 'appointment') {
                return 'it books appointments, which only the site can keep track of';
            }
        }
        $recipients = array_filter(array_map('trim', explode(',', (string) (($form['settings'] ?? [])['recipients'] ?? ''))),
            static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false);
        if ($recipients === []) {
            return 'it names nobody to send its answers to, and a copy has no inbox to keep them in';
        }
        return null;
    }

    /** Where a form in the copy posts, and asks for its fresh token. */
    public static function action(int $formId): string
    {
        return Url::path(self::FOLDER . '/send.php') . '?form=' . $formId;
    }

    /**
     * What stands in a form's place when the copy cannot send it: the words
     * given for it, an address in them a link, or nothing.
     */
    public static function instead(int $formId, string $words): string
    {
        $words = trim($words);
        if ($words === '') {
            return '<div class="cms-form cms-form--instead" data-form-id="' . $formId . '"></div>';
        }
        return '<div class="cms-form cms-form--instead" data-form-id="' . $formId . '">'
             . '<p class="cms-form__instead">' . self::linked($words) . '</p></div>';
    }

    /**
     * The words a form gives its place to when the copy is opened from a
     * folder, where nothing can send it, drawn beside the form and hidden:
     * `form-copy.js` shows them, and hides the form, when it finds itself
     * there. Nothing when no words were given.
     */
    public static function offline(int $formId, string $words): string
    {
        $words = trim($words);
        return $words === '' ? '' : '<div class="cms-form__offline" data-form-id="' . $formId . '" hidden>'
            . '<p class="cms-form__instead">' . self::linked($words) . '</p></div>';
    }

    /** The words, escaped, an email address in them a link. */
    private static function linked(string $words): string
    {
        return nl2br((string) preg_replace_callback(
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            static fn (array $m): string => '<a href="mailto:' . $m[0] . '">' . $m[0] . '</a>',
            htmlspecialchars($words, ENT_QUOTES, 'UTF-8')
        ), false);
    }

    /** What a visitor without JavaScript is told: the form's token comes by script. */
    public static function noscript(string $words): string
    {
        $words = trim($words);
        return '<noscript><p class="cms-form__instead">Sending this form needs JavaScript.'
             . ($words !== '' ? ' ' . htmlspecialchars($words, ENT_QUOTES, 'UTF-8') : '') . '</p></noscript>';
    }

    // ----------------------------------------------------------------
    // The handler, written into a copy
    // ----------------------------------------------------------------

    /**
     * The copy's form handler, written to `<site>/forms/` for the forms drawn
     * in it.
     *
     * @param array<string, mixed>             $render the job's `render.json`: address, secret
     * @param list<array<string, mixed>>       $forms  each as `Form::find()` gives it
     * @return int how many files it wrote
     */
    public static function bundle(string $site, array $render, array $forms): int
    {
        $dir   = $site . '/' . self::FOLDER;
        $core  = ROOT_PATH . '/core';
        $vendor = ROOT_PATH . '/vendor/phpmailer/phpmailer';

        foreach (self::CARRIED as $file) {
            self::copy($core . '/' . $file, $dir . '/lib/Cms/' . $file);
        }
        self::copy($core . '/Export/Receiver/stand-ins.php', $dir . '/lib/stand-ins.php');
        self::copy($core . '/Export/Receiver/autoload.php', $dir . '/lib/autoload.php');
        self::copy($core . '/Export/Receiver/send.php', $dir . '/send.php');
        foreach (self::MAILER as $file) {
            self::copy($vendor . '/' . $file, $dir . '/lib/PHPMailer/' . basename($file));
        }

        $address = rtrim((string) $render['address'], '/');
        $config  = [
            'app_secret' => (string) $render['secret'],
            'address'    => $address,
            'base_path'  => rtrim((string) (parse_url($address, PHP_URL_PATH) ?? ''), '/'),
            'site_name'  => self::siteName($address),
            'options'    => self::options(),
            'smtp'       => self::smtp(),
        ];
        self::write($dir . '/config/config.php', "<?php\n\n"
            . "// THIS COPY'S FORM HANDLER: what the copy was made with. The secret is the\n"
            . "// copy's own. Messages go through this host's own mail program; to send them\n"
            . "// through an SMTP server instead, give `smtp` a host and its password.\n\n"
            . 'return ' . var_export($config, true) . ";\n");

        $written = [];
        foreach ($forms as $form) {
            $written[(int) $form['id']] = self::written($form);
        }
        self::write($dir . '/config/forms.php', "<?php\n\n// The forms this copy draws, as the site had them when it was made.\n\n"
            . 'return ' . var_export($written, true) . ";\n");

        // ONLY send.php IS SERVED, on a host that reads .htaccess. Everywhere
        // else the settings and the forms are PHP that prints nothing, and a
        // kept message is PHP that exits (`Receiver\Store`).
        self::write($dir . '/.htaccess', implode("\n", [
            '# Only send.php is served from this folder.',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '    <Files "send.php">',
            '        Require all granted',
            '    </Files>',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order deny,allow',
            '    Deny from all',
            '    <Files "send.php">',
            '        Allow from all',
            '    </Files>',
            '</IfModule>',
        ]) . "\n");
        foreach (['', '/data'] as $sub) {
            self::write($dir . $sub . '/index.html', '');
        }

        return iterator_count(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        ));
    }

    /**
     * A form as the copy's handler reads it: its fields expanded (an embedded
     * form's are in its own list), a sourced field carrying the choices the
     * site had, its settings, and the conversion a real message sends.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private static function written(array $form): array
    {
        $fields = [];
        foreach (Embed::expand(is_array($form['fields'] ?? null) ? $form['fields'] : []) as $field) {
            if (FieldTypes::isSourced((string) ($field['type'] ?? ''))) {
                $field['allowed'] = Sources::choicesFor($field);
            }
            $fields[] = $field;
        }
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        // AN AFTER-SENDING PAGE GIVEN AS THE SITE'S FULL ADDRESS is a page of
        // the copy, as a path; another site's address is left out, as the site
        // never sends a visitor off it after a send.
        $redirect = trim((string) ($settings['redirect'] ?? ''));
        if ($redirect !== '' && !str_starts_with($redirect, '/')) {
            $source = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
            $settings['redirect'] = $source !== '' && str_starts_with($redirect, $source . '/')
                ? substr($redirect, strlen($source))
                : '';
        }
        $conversion = \Cms\Site\Google::conversionFor($form);
        return [
            'id'         => (int) $form['id'],
            'title'      => (string) ($form['title'] ?? ''),
            'fields'     => $fields,
            'settings'   => $settings,
            'conversion' => is_array($conversion) ? array_diff_key($conversion, ['id' => true]) : null,
        ];
    }

    /** The settings the carried classes read, from the site's. */
    private static function options(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT option_key, option_value FROM options WHERE option_key LIKE ? OR option_key IN ('
            . implode(',', array_fill(0, count(self::OPTIONS), '?')) . ')'
        );
        $stmt->execute(array_merge(['forms\_%'], self::OPTIONS));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['option_key']] = $row['option_value'] === null ? null : (string) $row['option_value'];
        }
        // The built-in question, whatever the site uses: a service would need
        // its secret key in the copy.
        $out['captcha_provider'] = 'builtin';
        ksort($out);
        return $out;
    }

    /** The site's SMTP server, for somebody to finish by hand — never its password. */
    private static function smtp(): array
    {
        $get = static fn (string $k, string $d = ''): string => (string) (\Cms\Models\Option::get($k, $d) ?? $d);
        return [
            'host'       => $get('smtp_host'),
            'port'       => (int) $get('smtp_port', '587'),
            'username'   => $get('smtp_username'),
            'password'   => '',
            'encryption' => $get('smtp_encryption', 'tls'),
        ];
    }

    /** The site's title, or the copy's own host name — the CMS's fallback, at the copy's address. */
    private static function siteName(string $address): string
    {
        $name = trim((string) (\Cms\Models\Option::get('site_name', '') ?? ''));
        return $name !== '' ? $name : (string) parse_url($address, PHP_URL_HOST);
    }

    private static function copy(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }
        if (!is_file($from) || !@copy($from, $to)) {
            throw new RuntimeException('Could not copy ' . $from . ' into the copy\'s form handler.');
        }
    }

    private static function write(string $file, string $bytes): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '.');
        }
        if (file_put_contents($file, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write ' . $file . '.');
        }
    }
}
