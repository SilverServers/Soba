<?php

declare(strict_types=1);

namespace Cms\Html;

use DOMElement;

/**
 * A KIT SIGN-UP FORM. A writer copies the form's code from Kit (Embed →
 * JavaScript): one script tag, at the address of that form on the account's
 * own name at Kit, which puts Kit's form where it stands. A visitor signs up
 * with Kit, as on any other site. The script is the embed: nothing comes
 * before or after it.
 */
final class Kit implements EmbedWidget
{
    /**
     * The form's script: https, the account's name at kit.com (or at ck.page,
     * Kit's address before kit.com), and the form's own id, which the tag
     * names again in `data-uid`.
     */
    private const SCRIPT = '#^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.(?:kit\.com|ck\.page)/([0-9a-f]{10})/index\.js$#';

    public static function isWidget(DOMElement $el): bool
    {
        return strtolower($el->nodeName) === 'script'
            && preg_match(self::SCRIPT, trim($el->getAttribute('src')), $m) === 1
            && trim($el->getAttribute('data-uid')) === $m[1];
    }

    public static function keepsOnWidget(string $name, string $value): bool
    {
        return match ($name) {
            'src'      => preg_match(self::SCRIPT, $value) === 1,
            'data-uid' => preg_match('/^[0-9a-f]{10}$/', $value) === 1,
            'async'    => in_array(strtolower($value), ['', 'async'], true),
            default    => false,
        };
    }

    public static function scripts(): array
    {
        return [];
    }
}
