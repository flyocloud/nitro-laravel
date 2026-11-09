<?php

namespace Flyo\Laravel\Tests;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Laravel\Tests\Fakes\ConfigApiFake;
use Flyo\Laravel\Tests\Fakes\PagesApiFake;
use Flyo\Model\ConfigResponse;
use Flyo\Model\Nitro;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Boots the service provider the way it boots for an http request, not for the command line.
 *
 * The application of a test does not run on the command line, so it takes the request branch of the
 * service provider: the Configuration, the lazy ConfigResponse and the lazy `$config` of the views are
 * bound. The api is faked, every config and page request is recorded instead of sent.
 */
abstract class HttpTestCase extends TestCase
{
    protected ConfigApiFake $configApi;

    protected PagesApiFake $pagesApi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeApi();
    }

    /**
     * Binds the fakes of the config and the pages endpoint, again after the application of a test
     * was created anew.
     */
    protected function fakeApi(): void
    {
        $this->fakeConfig(['', 'about', 'about/team', 'über-uns']);
        $this->pagesApi = new PagesApiFake;
        $this->app->instance(PagesApi::class, $this->pagesApi);
    }

    /**
     * Laravel caches whether it runs on the command line on the application, it is set before the
     * providers boot. An environment variable (APP_RUNNING_IN_CONSOLE) would do the same, but would
     * also reach the processes a test starts, like the `route:cache` run of RouteCacheTest.
     */
    protected function resolveApplication()
    {
        $app = parent::resolveApplication();

        (fn () => $this->isRunningInConsole = false)->call($app);

        return $app;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // live edit and APP_DEBUG turn the cache headers off
        $app['config']->set('flyo.live_edit', false);
        $app['config']->set('view.paths', [__DIR__.'/views']);
    }

    /**
     * Lets the config endpoint answer with the given pages, or fail with the given exception.
     *
     * @param  array<int, string>|Throwable  $pages
     */
    protected function fakeConfig(array|Throwable $pages): ConfigApiFake
    {
        $this->configApi = new ConfigApiFake($pages instanceof Throwable ? $pages : new ConfigResponse([
            'pages' => $pages,
            'nitro' => new Nitro(['version' => 7, 'updated_at' => 1755000000]),
        ]));
        $this->app->instance(ConfigApi::class, $this->configApi);

        return $this->configApi;
    }

    /**
     * Replaces the routes with their compiled form, the way `php artisan route:cache` writes them and
     * the application loads them again: every route prepared for serialization, the collection
     * compiled, exported and read back.
     */
    protected function cacheRoutes(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $routes = $router->getRoutes();

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }

        $file = tempnam(sys_get_temp_dir(), 'flyo-routes-');
        file_put_contents($file, '<?php return '.var_export($routes->compile(), true).';');

        try {
            $router->setCompiledRoutes(require $file);
        } finally {
            unlink($file);
        }
    }

    /**
     * Run a test once with the routes as they are registered and once with cached routes.
     *
     * @return array<string, array{0: bool}>
     */
    public static function routeCache(): array
    {
        return [
            'registered routes' => [false],
            'cached routes' => [true],
        ];
    }

    protected function routes(bool $cached): void
    {
        if ($cached) {
            $this->cacheRoutes();
        }
    }
}
