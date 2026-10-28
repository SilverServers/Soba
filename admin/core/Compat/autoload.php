<?php

declare(strict_types=1);

// Composer's `files` autoload runs this once, before any class loads: the
// CMS's old class names keep resolving. See Cms\Compat\OldNames.
require_once __DIR__ . '/OldNames.php';

\Cms\Compat\OldNames::register();
