<?php

namespace Flyo\Laravel;

use Flyo\Laravel\Controllers\PageController;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Laravel\Middleware\EnsurePageExists;
use Flyo\Model\ConfigResponse;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the CMS pages of the Flyo config through a single fallback route.
 *
 * Laravel tries the fallback route after every other route, so a CMS page is only served for a url
 * no other route of the application or of a package matches. The pages are not known when the routes
 * are registered, the EnsurePageExists middleware compares the path of the request with the pages of
 * the config, which is loaded at that moment, and answers with a 404 for every other path. Since the
 * route does not depend on the config, it is registered on the command line as well and can be
 * cached with `php artisan route:cache`.
 *
 * The middleware of the route, in their order:
 *
 * 1. EnsurePageExists, the page check, so nothing else runs for an unknown url
 * 2. the `web` group
 * 3. CachingHeaders
 * 4. the middleware of `flyo.page_middleware`
 *
 * They run before the page is loaded from the api, so a page middleware which returns a redirect or
 * an abort costs no api request. Hand written routes, like the entity detail pages, do not get them.
 */
class PageRoutes
{
    /**
     * The name of the fallback route, `request()->routeIs('flyo.page')` tells a CMS page apart.
     */
    public const NAME = 'flyo.page';

    /**
     * The request attribute which holds the path of the current CMS page, as the config delivers it.
     */
    public const ATTRIBUTE = 'flyo.page';

    /**
     * Registers the fallback route which serves the CMS pages. The ServiceProvider calls it unless
     * `flyo.page_fallback` is false.
     *
     * @internal
     */
    public static function fallback(): Route
    {
        $config = Container::getInstance()->make('config');

        // the page check comes before the web group: Laravel only reorders the middleware of its
        // priority list, so a middleware which is not on it keeps the first place
        return RouteFacade::fallback(PageController::class)
            ->middleware([EnsurePageExists::class, 'web', ...self::middleware($config)])
            ->name(self::NAME);
    }

    /**
     * The path of the CMS page the request points to, as the Flyo config delivers it, or null when it
     * is not a page. Loads the config, which happens at most once per request.
     *
     * The comparison follows the router of Laravel, which matched a route per page before: the path
     * is url decoded, slashes at the start and the end do not count, upper and lower case do.
     */
    public static function find(Request $request): ?string
    {
        $path = rawurldecode(rtrim($request->getPathInfo(), '/') ?: '/');

        $config = Container::getInstance()->make(ConfigResponse::class);

        foreach ($config->getPages() ?? [] as $page) {
            if ('/'.trim((string) $page, '/') === $path) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Hands a request over to the CMS page handling, for an application which registers a fallback
     * route of its own (with `flyo.page_fallback` set to false):
     *
     * ```php
     * Route::fallback(function (Request $request) {
     *     if ($target = LegacyRedirects::for($request->path())) {
     *         return redirect($target, 301);
     *     }
     *
     *     return PageRoutes::dispatch($request);
     * });
     * ```
     *
     * It runs the same steps as the fallback route of the package: the page check (a 404 for an
     * unknown path), CachingHeaders and the middleware of `flyo.page_middleware`, then the page is
     * loaded and the `cms` view rendered. The `web` group is left to the route of the application,
     * and a page middleware which already ran on that route does not run a second time.
     */
    public static function dispatch(Request $request): Response
    {
        $app = Container::getInstance();

        /** @var Router $router */
        $router = $app->make('router');

        return (new Pipeline($app))
            ->send($request)
            ->through(self::dispatchMiddleware($app, $router, $request))
            ->then(fn (Request $request) => $router->prepareResponse(
                $request,
                $app->call([$app->make(PageController::class), '__invoke'], ['request' => $request]),
            ));
    }

    /**
     * The middleware of a CMS page after the page check: CachingHeaders first, then the ones of
     * `flyo.page_middleware`, in their order. A published config of a previous version does not have
     * the key.
     *
     * @return array<int, string>
     */
    public static function middleware(ConfigRepository $config): array
    {
        return [CachingHeaders::class, ...array_values((array) $config->get('flyo.page_middleware', []))];
    }

    /**
     * The fallback route which replaced the one of the package, null while the package route is in
     * place. A second `Route::fallback()` takes over the very same slot in the route collection, so a
     * fallback of the application silently swallows every CMS page.
     *
     * @internal
     */
    public static function replacedFallback(RouteCollectionInterface $routes): ?Route
    {
        $route = $routes->getRoutesByMethod()['GET']['{fallbackPlaceholder}'] ?? null;

        return $route instanceof Route && $route->getName() !== self::NAME ? $route : null;
    }

    /**
     * The middleware dispatch() runs: the page check and the page middleware, without the ones which
     * already ran on the current route. Nothing when the middleware are disabled in a test.
     *
     * @return array<int, mixed>
     */
    private static function dispatchMiddleware(Container $app, Router $router, Request $request): array
    {
        if ($app->bound('middleware.disable') && $app->make('middleware.disable') === true) {
            return [];
        }

        $route = $request->route();
        $route = $route instanceof Route ? $route : null;

        $alreadyRun = $route ? $router->gatherRouteMiddleware($route) : [];

        $middleware = $router->resolveMiddleware(
            [EnsurePageExists::class, ...self::middleware($app->make('config'))],
            $route ? $route->excludedMiddleware() : [],
        );

        return array_values(array_filter($middleware, fn ($name) => ! in_array($name, $alreadyRun, true)));
    }
}
