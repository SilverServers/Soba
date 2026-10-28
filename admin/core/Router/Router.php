<?php

declare(strict_types=1);

namespace Cms\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;

class Router
{
    /** @var array<array{method: string, pattern: string, handler: array}> */
    private array $routes = [];

    /**
     * Register a route.
     *
     * @param string $method   HTTP method (GET, POST, PUT, DELETE, …)
     * @param string $pattern  URL pattern, e.g. '/admin/posts/{id:\d+}'
     * @param array  $handler  [ControllerClass::class, 'methodName']
     */
    public function addRoute(string $method, string $pattern, array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    /**
     * Drop every registered route the callback rejects. The front controller
     * uses it once, after registration, to remove the routes of switched-off
     * modules (`Helpers\Modules::allowsRoute`), so a disabled module's
     * addresses 404 instead of answering. The file that registers them stays
     * whole.
     *
     * @param callable(string $pattern, array $handler): bool $keep
     */
    public function keepOnly(callable $keep): void
    {
        $this->routes = array_values(array_filter(
            $this->routes,
            static fn (array $route): bool => (bool) $keep($route['pattern'], $route['handler'])
        ));
    }

    /**
     * Match the current request URI against registered routes.
     *
     * Returns one of:
     *   [Dispatcher::FOUND,              [class, method], ['var' => 'value', ...]]
     *   [Dispatcher::NOT_FOUND]
     *   [Dispatcher::METHOD_NOT_ALLOWED, ['GET', 'POST', ...]]
     */
    public function dispatch(string $httpMethod, string $uri): array
    {
        $routes = $this->routes; // capture for closure

        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes): void {
            foreach ($routes as $route) {
                // ONE BAD ROUTE COSTS ITSELF, NOT THE SITE.
                // FastRoute throws on a route that repeats another — a Content
                // Type published at `/search`, two types at one address, a
                // taxonomy at a type's address — and it throws while building
                // the table for EVERY request, so one saved form would answer
                // nothing, the admin included. The forms refuse those addresses;
                // this is for what arrives another way (an import, a config
                // file, a row edited by hand). The route that came first keeps
                // the address, and the log names the one that lost.
                try {
                    $r->addRoute($route['method'], $route['pattern'], $route['handler']);
                } catch (\FastRoute\BadRouteException $e) {
                    error_log('CMS router: ' . $route['method'] . ' ' . $route['pattern']
                        . ' skipped — ' . $e->getMessage());
                }
            }
        });

        // Strip query string before matching.
        $uri = strtok($uri, '?') ?: '/';

        // Strip the base path so routes are defined relative to the app root.
        $basePath = defined('BASE_PATH') ? rtrim((string) BASE_PATH, '/') : '';
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath));
        }

        // Normalize: ensure leading slash, strip trailing slash (except root).
        $uri = '/' . ltrim($uri, '/');
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        return $dispatcher->dispatch($httpMethod, $uri);
    }
}
