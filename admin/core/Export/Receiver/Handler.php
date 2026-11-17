<?php

declare(strict_types=1);

namespace Cms\Export\Receiver;

use Cms\Forms\Answers;
use Cms\Forms\Captcha;
use Cms\Forms\FieldTypes;
use Cms\Forms\Guard;
use Cms\Forms\Notify;
use Cms\Forms\Upload;
use Cms\Mail\Mailer;
use Cms\Security\ClientIp;

/**
 * A STATIC COPY'S FORM HANDLER: what `/forms/{id}/submit` does on the site,
 * for a copy of it (`Export\StaticCopy`) on a host that runs PHP and nothing
 * else. It is the copy's `forms/send.php`.
 *
 * ── THE SITE'S OWN RULES ────────────────────────────────────────────────────
 * The CMS's form classes come with the copy as they are: `Forms\Guard` turns
 * away what it turns away on the site (decoys, the time-trap, replays, the
 * rate limit, a known bot's signature, too many links), `Forms\Answers` reads
 * and checks each answer as the site does, `Forms\Captcha` asks its question,
 * and `Forms\Notify` writes the message the site would send. What those
 * classes ask of a site — settings, submissions, forms, a mailer — is answered
 * by `stand-ins.php`, from what the export wrote down and from `Store`.
 *
 * ── WHAT IS NOT THE SAME ────────────────────────────────────────────────────
 * Nothing is stored for an inbox: a message is mailed, with the visitor's
 * files attached, or — when the host's mail program refuses it — kept in
 * `data/unsent/` and the visitor told it did not go. Suspicious posts are kept
 * in `data/spam/`, as the Spam view keeps them, and never mailed; certain spam
 * is discarded. A form that books appointments, or has nobody to send to, is
 * not in the copy at all (`Export\Forms`).
 *
 * ── A PAGE DRAWN LONG AGO ───────────────────────────────────────────────────
 * The copy's pages were drawn when the copy was made, and so was the token
 * each form carries: hours old by the first visit, and the same for every
 * visitor. A GET of this file is a fresh start for one form — a token, and the
 * built-in captcha's question — which `form-copy.js` puts into the form as its
 * page loads, so the time-trap and the replay rule work as they do on the site.
 */
final class Handler
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var array<int, array<string, mixed>> */
    private static array $forms = [];

    /** @return array<string, mixed> `config/config.php` */
    public static function config(): array
    {
        return self::$config;
    }

    /** @return array<int, array<string, mixed>> `config/forms.php`, by form id */
    public static function forms(): array
    {
        return self::$forms;
    }

    /** Answer one request to the copy's `forms/send.php`, whose folder is `$dir`. */
    public static function run(string $dir): void
    {
        $config = @include $dir . '/config/config.php';
        $forms  = @include $dir . '/config/forms.php';
        self::$config = is_array($config) ? $config : [];
        self::$forms  = is_array($forms) ? $forms : [];
        Store::open($dir . '/data');

        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $id   = is_string($_GET['form'] ?? null) && ctype_digit($_GET['form']) ? (int) $_GET['form'] : 0;
        $form = self::$forms[$id] ?? null;

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'GET' || $method === 'HEAD') {
            self::fresh($form);
            return;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }
        self::submit($form);
        Store::purge();
    }

    // ----------------------------------------------------------------
    // A fresh start
    // ----------------------------------------------------------------

    /** A new token for the form, and a new question when it asks one. */
    private static function fresh(?array $form): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($form === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Form not found.']);
            return;
        }
        $answer = ['token' => Guard::token()];
        if (self::has($form, 'captcha')) {
            [$nonce, $question] = Captcha::challenge();
            $answer['captcha'] = ['question' => $question, 'nonce' => $nonce];
        }
        echo json_encode($answer);
    }

    // ----------------------------------------------------------------
    // A submission
    // ----------------------------------------------------------------

    private static function submit(?array $form): void
    {
        $source = self::sourcePage();
        if ($form === null) {
            self::answer(false, 'Form not found.', 404, null, $source);
            return;
        }

        $_POST    = self::validText($_POST);
        $fields   = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $meta     = [
            'ip'          => ClientIp::get(),
            'user_agent'  => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'source_page' => $source,
        ];

        $turn = Guard::screen($_POST, $_SERVER);
        if ($turn !== null) {
            self::turnAway($form, $fields, $turn, $meta);
            return;
        }

        // BEFORE ANY FIELD IS READ, as on the site: a bot learns nothing
        // about the fields from a failed question.
        if (self::has($form, 'captcha') && !self::captchaPasses()) {
            self::answer(false, 'That answer was not right. Please try again.', 422, $form, $source);
            return;
        }

        /** @var list<array{0: string, 1: string}> $files path, name */
        $files   = [];
        $answers = new Answers(
            $_POST,
            $_FILES,
            static function (string $type, mixed $posted, array $field, ?string &$why) use (&$files): ?string {
                $kept = $type === 'file'
                    ? self::keepFile(is_array($posted) ? $posted : [], $field, $why)
                    : self::keepSignature((string) $posted, $field, $why);
                if ($kept === null) {
                    return null;
                }
                $files[] = $kept;
                return $kept[1];
            },
            // A sourced field's choices are the site's content, written down
            // when the copy was made.
            static fn (array $field): array => array_values(array_map('strval', (array) ($field['allowed'] ?? []))),
        );
        $checked = $answers->check($fields);
        if ($checked['errors'] !== []) {
            self::discard($files);
            self::answer(false, implode(' ', $checked['errors']), 422, $form, $source);
            return;
        }

        $turn = Guard::content($checked['data'], $fields, $settings, $checked['raw']);
        if ($turn !== null) {
            self::discard($files);
            self::turnAway($form, $fields, $turn, $meta);
            return;
        }

        // THE TOKEN IS SPENT ONLY ONCE THE MESSAGE HAS GONE. Spent first, a
        // message the host refused would make the visitor's second try a
        // replay — told "thank you" and discarded.
        // A TEST SENT FROM THE FORM'S TEST ADDRESS goes to its test inbox alone,
        // as on the site (`Notify::testInbox()`).
        Mailer::attach($files);
        $sent = Notify::send($form, $checked['data'], $checked['raw'] !== [] ? $checked['raw'] : $checked['data'], $meta,
            Notify::testInbox($form, $checked['data']));
        self::discard($files);
        if (!$sent) {
            Store::keep('unsent', [
                'form'        => (string) ($form['title'] ?? ''),
                'at'          => gmdate('c'),
                'answers'     => $checked['data'],
                'source_page' => $source,
                'ip'          => $meta['ip'],
            ]);
            self::answer(false, 'Your message could not be sent just now. Please try again later.', 500, $form, $source);
            return;
        }
        Store::received(Guard::postedTokenHash($_POST), $meta['ip']);
        // A CONFIRMATION TO THE SENDER, when the form has one, as the site
        // sends it: past the spam checks, and once the message has gone.
        Notify::confirm($form, $checked['data'], Notify::testInbox($form, $checked['data']));

        self::answer(true, (string) ($settings['success_message'] ?? 'Thank you! Your submission has been received.'),
            200, $form, $source, self::conversion($form));
    }

    /**
     * A post the guard turned away, as the site turns it away: certain spam
     * discarded (unless the site keeps all of it), anything else kept in
     * `data/spam/` with its reason — and the sender answered as the guard
     * says, "thank you" to a caught bot and the rule's words to anyone who
     * might be a person. Spam is never mailed.
     */
    private static function turnAway(array $form, array $fields, array $turn, array $meta): void
    {
        if (empty($turn['certain']) || \Cms\Models\Option::get('forms_spam_keep', '0') === '1') {
            $data = [];
            foreach ($fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $type = (string) ($field['type'] ?? 'text');
                if ($name === '' || !FieldTypes::collects($type) || in_array($type, ['file', 'signature'], true) || !isset($_POST[$name])) {
                    continue;
                }
                $value = $_POST[$name];
                $text  = is_array($value)
                    ? implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value))
                    : (string) $value;
                if (trim($text) !== '') {
                    $data[$name] = mb_substr(trim($text), 0, 5000);
                }
            }
            Store::keep('spam', [
                'form'        => (string) ($form['title'] ?? ''),
                'at'          => gmdate('c'),
                'reason'      => (string) $turn['reason'],
                'answers'     => $data,
                'source_page' => $meta['source_page'],
                'ip'          => $meta['ip'],
                'user_agent'  => $meta['user_agent'],
            ]);
            Store::spam(Guard::postedTokenHash($_POST), $meta['ip'], (string) $turn['reason']);
        }

        if (!empty($turn['fake'])) {
            self::answer(true, (string) (($form['settings'] ?? [])['success_message'] ?? 'Thank you! Your submission has been received.'),
                200, $form, $meta['source_page']);
            return;
        }
        self::answer(false, Guard::message((string) $turn['code']), (int) $turn['status'], $form, $meta['source_page']);
    }

    /** The built-in question, answered once: right or wrong, it is spent. */
    private static function captchaPasses(): bool
    {
        $minted = Captcha::open((string) ($_POST['_captcha_nonce'] ?? ''), time());
        $given  = trim((string) ($_POST['_captcha'] ?? ''));
        if ($minted === null || !Store::spend($minted['nonce'])) {
            return false;
        }
        return $given !== '' && ctype_digit($given) && (int) $given === $minted['answer'];
    }

    // ----------------------------------------------------------------
    // A visitor's files — attached, never kept
    // ----------------------------------------------------------------

    /**
     * An uploaded file, by the site's rules (`Forms\Upload`): its type from
     * its bytes, from the same short list, within the field's size. It waits
     * in `data/tmp/` until its message is sent.
     *
     * @return array{0: string, 1: string}|null path, the name it is attached under
     */
    private static function keepFile(array $file, array $field, ?string &$why): ?array
    {
        $why  = null;
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($code !== UPLOAD_ERR_OK) {
            $why = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'That file is too large.'
                : 'That file could not be uploaded.';
            return null;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $why = 'That file could not be uploaded.';
            return null;
        }
        $limit = Upload::limitFor($field);
        if ((int) ($file['size'] ?? 0) > $limit) {
            $why = 'That file is larger than ' . round($limit / 1024 / 1024, 1) . ' MB.';
            return null;
        }
        $mime = self::mime($tmp);
        if (!isset(Upload::allowedFor($field)[$mime])) {
            $why = Upload::notTaken($field);
            return null;
        }
        $path = Store::tmpDir() . '/' . bin2hex(random_bytes(16)) . '.' . Upload::ALLOWED[$mime];
        if (!move_uploaded_file($tmp, $path)) {
            $why = 'That file could not be stored.';
            return null;
        }
        // A photo loses its metadata before it is mailed, as on the site.
        if (!\Cms\Media\Optimizer::scrub($path, $mime)) {
            @unlink($path);
            $why = 'That picture could not be read.';
            return null;
        }
        return [$path, self::fileName((string) ($file['name'] ?? ''), Upload::ALLOWED[$mime])];
    }

    /**
     * A drawn signature: a data URL decoded, never trusted — its bytes are
     * sniffed like any upload's.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function keepSignature(string $dataUrl, array $field, ?string &$why): ?array
    {
        $why = null;
        if (preg_match('~^data:image/png;base64,([A-Za-z0-9+/=]+)$~', trim($dataUrl), $m) !== 1) {
            $why = 'That signature could not be read.';
            return null;
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || $bytes === '') {
            $why = 'That signature could not be read.';
            return null;
        }
        $path = Store::tmpDir() . '/' . bin2hex(random_bytes(16)) . '.png';
        if (file_put_contents($path, $bytes) === false) {
            $why = 'That signature could not be stored.';
            return null;
        }
        if (self::mime($path) !== 'image/png' || strlen($bytes) > Upload::limitFor($field)) {
            @unlink($path);
            $why = 'That signature could not be stored.';
            return null;
        }
        return [$path, 'signature.png'];
    }

    private static function mime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo !== false ? (string) finfo_file($finfo, $path) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        return $mime;
    }

    /** The visitor's own file name, made safe, ending in the type its bytes are. */
    private static function fileName(string $name, string $ext): string
    {
        $name = (string) preg_replace('/[^\pL\pN ._()-]+/u', '', basename(str_replace('\\', '/', $name)));
        $name = trim(mb_substr($name, 0, 100), ' .');
        $base = $name !== '' ? (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) : 'file';
        return ($base !== '' ? $base : 'file') . '.' . $ext;
    }

    /** @param list<array{0: string, 1: string}> $files */
    private static function discard(array $files): void
    {
        foreach ($files as [$path]) {
            @unlink($path);
        }
    }

    // ----------------------------------------------------------------
    // The answer
    // ----------------------------------------------------------------

    /**
     * The answer, in the shape the sender can read: {success, message} for
     * the form's script, which asks for JSON, with a real message's
     * conversion; a short page for a browser that posted the form itself, or
     * the form's own "after sending" address.
     */
    private static function answer(bool $success, string $message, int $status, ?array $form, string $source, ?array $conversion = null): void
    {
        $accept   = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $ajax     = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $base     = rtrim((string) (self::$config['base_path'] ?? ''), '/');
        $settings = is_array(($form ?? [])['settings'] ?? null) ? $form['settings'] : [];
        $own      = trim((string) ($settings['redirect'] ?? ''));
        $own      = self::onSite($own) ? $base . $own : null;
        if ($ajax || str_contains($accept, 'application/json')) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            $answer = ['success' => $success, 'message' => $message];
            // THE FORM'S BOX SAYS A SEND BY SCRIPT GOES TO ITS AFTER-SENDING
            // PAGE TOO, as on the site. The site carries the conversion there
            // in a cookie its next page reads; a copy's pages read nothing, so
            // it is not sent.
            if ($success && $own !== null && (string) ($settings['redirect_scripted'] ?? '') === '1') {
                $answer['redirect'] = $own;
            } elseif ($success && $conversion !== null) {
                $answer['conversion'] = $conversion;
            }
            echo json_encode($answer);
            return;
        }

        if ($success && $own !== null) {
            header('Location: ' . $own, true, 303);
            return;
        }
        http_response_code($success ? 200 : $status);
        header('Content-Type: text/html; charset=utf-8');
        $h    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $back = self::onSite($source) ? $source : $base . '/';
        // THE SITE'S LANGUAGE, as its pages say it (`Site\\Language`), carried in
        // the copy's settings; English when it is not a language tag.
        $lang = (string) \Cms\Models\Option::get('site_language', 'en');
        $lang = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $lang) === 1 ? $lang : 'en';
        echo "<!doctype html>\n<html lang=\"" . $h($lang) . "\">\n<head>\n<meta charset=\"utf-8\">\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex, nofollow">' . "\n"
            . '<title>' . ($success ? 'Sent' : 'Not sent') . ' | ' . $h((string) (self::$config['site_name'] ?? '')) . "</title>\n"
            . "</head>\n<body>\n"
            . '<p>' . $h(html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . "</p>\n"
            . '<p><a href="' . $h($back) . '">Back</a></p>' . "\n"
            . "</body>\n</html>\n";
    }

    /** A real message's conversion, as the site sends it: the form's event, a new id. */
    private static function conversion(array $form): ?array
    {
        $conversion = $form['conversion'] ?? null;
        if (!is_array($conversion) || (string) ($conversion['event'] ?? '') === '') {
            return null;
        }
        $conversion['id'] = bin2hex(random_bytes(8));
        if (array_key_exists('submission', $conversion)) {
            $conversion['submission'] = 0;
        }
        return $conversion;
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private static function has(array $form, string $type): bool
    {
        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (($field['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    /** The page the form was sent from: its posted `source_page`, else the Referer's path. */
    private static function sourcePage(): string
    {
        $posted = trim((string) ($_POST['source_page'] ?? ''));
        if ($posted !== '' && str_starts_with($posted, '/') && !str_starts_with($posted, '//')) {
            return mb_substr($posted, 0, 500);
        }
        $path = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
        return str_starts_with($path, '/') ? mb_substr($path, 0, 500) : '';
    }

    /** A path on this site: one leading slash, and no second one or backslash after it. */
    private static function onSite(string $path): bool
    {
        return preg_match('~^/(?![/\\\\])[^\\\\\s<>"]*$~', $path) === 1;
    }

    /** Posted text with every byte that is not UTF-8 replaced by U+FFFD, lists included. */
    private static function validText(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'validText'], $value);
        }
        if (!is_string($value) || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $was = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $value = mb_scrub($value, 'UTF-8');
        mb_substitute_character($was);
        return $value;
    }
}
