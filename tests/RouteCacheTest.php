<?php

namespace Flyo\Laravel\Tests;

use Illuminate\Routing\CompiledRouteCollection;

/**
 * `php artisan route:cache` and `php artisan optimize` were not usable until 3.x: the page routes
 * only existed in an http request, a route cache did not contain them and replaced them at runtime.
 *
 * The cache here is written by a real `route:cache` run, in a process of its own which boots the
 * package on the command line from testbench.yaml, without FLYO_TOKEN and without network. The
 * application of the test then loads it like a deployed application does.
 */
class RouteCacheTest extends HttpTestCase
{
    public function test_the_route_cache_serves_the_pages_the_sitemap_and_the_routes_of_the_application(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('web')->get('/kontakt', fn () => 'kontakt of the application');
PHP);

        // the application was created anew, it loaded the route cache while booting
        $this->fakeApi();

        $routes = $this->app['router']->getRoutes();

        $this->assertTrue($this->app->routesAreCached());
        $this->assertInstanceOf(CompiledRouteCollection::class, $routes);
        $this->assertTrue($routes->hasNamedRoute('flyo.page'));
        $this->assertTrue($routes->hasNamedRoute('flyo.sitemap'));

        $this->get('/kontakt')->assertOk()->assertSee('kontakt of the application');
        $this->get('/about')->assertOk()->assertSee('page:title of about', false);
        $this->get('/about/team/')->assertOk()->assertSee('page:title of about/team', false);
        $this->get('/unknown')->assertNotFound();

        $this->assertSame([['about', 'en'], ['about/team', 'en']], $this->pagesApi->requests);
    }
}
