<?php

namespace Flyo\Laravel;

use Flyo\Api\PagesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Model\Page;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

/**
 * Registers a route for every page of the Flyo config, which loads the page from the api and
 * renders the `cms` view with it.
 *
 * The routes sit in the `web` group and carry the CachingHeaders middleware, followed by the
 * middleware of `flyo.page_middleware`. That option is the way for an application to act on a CMS
 * page, and only on a CMS page, before the page is loaded: the routes are closures of the package,
 * so there is no controller to extend, and a middleware of the `web` group would also run for every
 * hand written route of the application, which it would then have to tell apart from the pages.
 * Since such a middleware runs before the route, a redirect it returns costs no api request.
 */
class PageRoutes
{
    /**
     * @param  iterable<string>  $pages  the paths of the pages, as ConfigResponse::getPages() delivers them
     */
    public static function boot(iterable $pages, Configuration $api, ConfigRepository $config, ViewFactory $views): void
    {
        $middleware = self::middleware($config);

        Route::middleware('web')->group(function () use ($pages, $api, $views, $middleware) {
            foreach ($pages as $page) {
                Route::get($page, function () use ($page, $api, $views) {
                    $pageResponse = (new PagesApi(null, $api))->page($page, App::getLocale());

                    App::instance(Page::class, $pageResponse);

                    Head::metaPage($pageResponse);

                    return $views->make('cms', ['page' => $pageResponse]);
                })->middleware($middleware);
            }
        });
    }

    /**
     * The middleware of a page route: CachingHeaders first, then the ones of `flyo.page_middleware`,
     * in their order. A published config of a previous version does not have the key.
     *
     * @return array<int, string>
     */
    public static function middleware(ConfigRepository $config): array
    {
        return [CachingHeaders::class, ...array_values((array) $config->get('flyo.page_middleware', []))];
    }
}
