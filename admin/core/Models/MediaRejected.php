<?php

declare(strict_types=1);

namespace Cms\Models;

/**
 * A FILE THE LIBRARY WILL NOT TAKE — too big, a type it does not hold, or not
 * readable. Thrown by `Media::admit()`; the message is a sentence for the
 * person who chose the file, so every door can show it as it is.
 *
 * Its own class so a door can tell "the file was refused" (the person can fix
 * that) from "the server could not save it" (they cannot), which the uploader
 * answers with a 400 and a 500 respectively.
 */
final class MediaRejected extends \RuntimeException
{
}
