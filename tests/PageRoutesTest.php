<?php

namespace Flyo\Laravel\Tests;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Laravel\Controllers\PageController;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Laravel\Middleware\EnsurePageExists;
use Flyo\Laravel\PageRoutes;
use Flyo\Laravel\ServiceProvider;
use Flyo\Laravel\Tests\Fakes\ConfigApiFake;
use Flyo\Model\ConfigResponse;
use Flyo\Model\Nitro;
use Flyo\Model\Page;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Mockery;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The fallback route of the package on the command line, where tests, `route:cache` and queue
 * workers run: the route exists, but neither the config nor `$config` are bound.
 */
class PageRoutesTest extends TestCase
{
    protected function withPageMiddleware($app): void
    {
        $app['config']->set('flyo.page_middleware', ['App\\Http\\Middleware\\First', 'can:view,page']);
    }

    protected function withoutPageFallback($app): void
    {
        $app['config']->set('flyo.page_fallback', false);
    }

    public function test_a_single_fallback_route_serves_the_pages(): void
    {
        $route = $this->pageRoute();

        $this->assertNotNull($route);
        $this->assertTrue($route->isFallback);
        $this->assertSame('{fallbackPlaceholder}', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(PageController::class, $route->getControllerClass());
        $this->assertSame([EnsurePageExists::class, 'web', CachingHeaders::class], $route->middleware());
    }

    #[DefineEnvironment('withPageMiddleware')]
    public function test_the_page_middleware_follow_the_caching_headers_in_their_order(): void
    {
        $this->assertSame(
            [EnsurePageExists::class, 'web', CachingHeaders::class, 'App\\Http\\Middleware\\First', 'can:view,page'],
            $this->pageRoute()->middleware(),
        );
    }

    public function test_a_config_without_the_key_or_a_single_string_is_accepted(): void
    {
        // a published config of a previous version does not have the key
        $this->assertSame([CachingHeaders::class], PageRoutes::middleware(new Repository(['flyo' => []])));
        $this->assertSame([CachingHeaders::class, 'auth'], PageRoutes::middleware(new Repository(['flyo' => ['page_middleware' => 'auth']])));
    }

    #[DefineEnvironment('withoutPageFallback')]
    public function test_the_fallback_route_can_be_turned_off(): void
    {
        $this->assertNull($this->pageRoute());

        $this->get('/about')->assertNotFound();
    }

    public function test_the_config_is_not_bound_on_the_command_line(): void
    {
        // a queue job or an artisan command never loads the config, the same as until 3.x
        $this->assertFalse($this->app->bound(ConfigResponse::class));
        $this->assertArrayNotHasKey('config', View::getShared());
    }

    public function test_an_url_is_not_found_in_a_test_without_asking_the_api(): void
    {
        $api = new ConfigApiFake(new ConfigResponse(['pages' => ['about']]));
        $this->app->instance(ConfigApi::class, $api);

        $this->get('/about')->assertNotFound();
        $this->get('/unknown')->assertNotFound();

        $this->assertSame([], $api->requests);
    }

    public function test_a_page_can_be_rendered_in_a_test_of_the_application(): void
    {
        View::addLocation(__DIR__.'/views');

        // the recipe of the readme
        $config = new ConfigResponse([
            'pages' => ['about'],
            'nitro' => new Nitro(['version' => 1, 'updated_at' => 1755000000]),
        ]);
        $this->app->instance(ConfigResponse::class, $config);
        View::share('config', $config);

        $pages = Mockery::mock(PagesApi::class);
        $pages->shouldReceive('page')->once()->with('about', 'en')->andReturn(new Page(['title' => 'About']));
        $this->app->instance(PagesApi::class, $pages);

        $this->get('/about')->assertOk()->assertSee('page:About', false);
        $this->get('/unknown')->assertNotFound();
    }

    public function test_the_package_fallback_is_not_reported_as_replaced(): void
    {
        $this->assertNull(PageRoutes::replacedFallback($this->app['router']->getRoutes()));
    }

    public function test_a_fallback_of_the_application_replaces_the_one_of_the_package(): void
    {
        $route = Route::fallback(fn () => 'application');

        $this->assertSame($route, PageRoutes::replacedFallback($this->app['router']->getRoutes()));
        $this->assertNull($this->pageRoute());
    }

    public function test_a_fallback_bound_to_a_domain_or_a_prefix_does_not_replace_it(): void
    {
        Route::domain('shop.example.com')->group(fn () => Route::fallback(fn () => 'shop'));
        Route::prefix('admin')->group(fn () => Route::fallback(fn () => 'admin'));

        $this->assertNull(PageRoutes::replacedFallback($this->app['router']->getRoutes()));
    }

    public function test_a_replaced_fallback_is_logged_on_the_command_line(): void
    {
        Route::fallback(fn () => 'application');
        Log::spy();

        // the command line is never interrupted, it would block `php artisan config:clear` as well
        (fn () => $this->ensureFallbackIsInPlace())->call($this->app->getProvider(ServiceProvider::class));

        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, "'page_fallback' => false"));
    }
}
