<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Content\Calendar;
use Cms\Helpers\Template;

/**
 * An events type's calendar (`Content\Calendar`): every live event as one
 * calendar to subscribe to, and one event as a file to add.
 */
class CalendarController
{
    public function __construct(private ?string $contentTypeKey = null)
    {
    }

    /** GET /{type}/calendar.ics */
    public function type(array $vars = []): void
    {
        $key = (string) $this->contentTypeKey;
        if (!Calendar::has($key)) {
            Template::notFound();
            return;
        }
        header('Content-Type: text/calendar; charset=utf-8');
        echo Calendar::forType($key);
    }

    /** GET /{type}/{event}.ics — a download, so a click adds it. */
    public function event(array $vars = []): void
    {
        $key  = (string) $this->contentTypeKey;
        $slug = (string) ($vars['slug'] ?? '');
        $ics  = Calendar::has($key) ? Calendar::forEvent($key, $slug) : null;
        if ($ics === null) {
            Template::notFound();
            return;
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '.ics"');
        echo $ics;
    }
}
