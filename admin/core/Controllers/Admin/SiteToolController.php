<?php

declare(strict_types=1);

namespace Cms\Controllers\Admin;

use Cms\Site\Tools;

/**
 * A SITE'S OWN TOOL IN THE ADMIN'S FRAME (`Site\Tools`, a row with
 * `'frame' => true`), at every address under /admin/tools/{path}. By the time
 * the router reaches it, the front controller has done what it does for every
 * admin address: the door, the form token on a POST, the watch that makes a
 * changing POST a line in Activity log → Changes. The tool runs
 * (`Tools::runFramed()`) and the page it returns is drawn inside the frame,
 * with its sidebar row lit from the address; what it answers for itself, an
 * action's JSON or an asset, is sent as it is.
 */
final class SiteToolController
{
    public function __construct(private readonly string $path)
    {
    }

    public function handle(array $vars = []): void
    {
        $page = Tools::runFramed($this->path);
        if ($page === null) {
            return;
        }
        $flashes   = Tools::takeFlashes();
        $pageTitle = $page['title'];

        ob_start();
        require ROOT_PATH . '/admin/templates/tools/site-tool.php';
        $pageContent = ob_get_clean();
        require ROOT_PATH . '/admin/templates/layout.php';
    }
}
