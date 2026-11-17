<?php

declare(strict_types=1);

/**
 * A static copy's form handler's classes, by name: the CMS's below `Cms/`, the
 * mail library's below `PHPMailer/`. The stand-ins (`stand-ins.php`) are
 * defined before this is registered, so their names never reach it.
 */
spl_autoload_register(static function (string $class): void {
    foreach (['Cms\\' => __DIR__ . '/Cms/', 'PHPMailer\\PHPMailer\\' => __DIR__ . '/PHPMailer/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
