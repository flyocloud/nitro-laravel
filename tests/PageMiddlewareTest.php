<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Laravel\Middleware\EnsurePageExists;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `flyo.page_middleware` runs for a CMS page only. Until 3.x an unknown url had no route at all, so
 * none of these middleware ever ran for it. The page check is the first middleware of the fallback
 * route, which keeps it that way: `auth` does not redirect a guest away from a 404 and `throttle`
 * does not count it.
 */
class PageMiddlewareTest extends HttpTestCase
{
    protected function withAuth($app): void
    {
        $app['config']->set('flyo.page_middleware', ['auth']);
    }

    protected function withThrottle($app): void
    {
        $app['config']->set('flyo.page_middleware', ['throttle:1,1']);
    }

    protected function withAuthThrottleAndCan($app): void
    {
        $app['config']->set('flyo.page_middleware', ['auth', 'throttle:60,1', 'can:view-intranet']);
    }

    protected function withRedirect($app): void
    {
        $app['config']->set('flyo.page_middleware', ['to-team']);
    }

    /**
     * @return array<int, string>
     */
    private function middlewareOfThePageRoute(): array
    {
        // the priority of the middleware comes from the http kernel
        $this->app->make(Kernel::class);

        return array_map(
            fn ($middleware) => explode(':', $middleware)[0],
            $this->app['router']->gatherRouteMiddleware($this->pageRoute()),
        );
    }

    #[DefineEnvironment('withAuthThrottleAndCan')]
    public function test_the_page_check_runs_before_the_web_group_and_every_page_middleware(): void
    {
        $middleware = $this->middlewareOfThePageRoute();

        $this->assertSame(EnsurePageExists::class, $middleware[0]);

        foreach ([StartSession::class, Authenticate::class, ThrottleRequests::class, Authorize::class, CachingHeaders::class] as $later) {
            $this->assertContains($later, $middleware);
            $this->assertGreaterThan(0, array_search($later, $middleware, true), $later);
        }
    }

    #[DefineEnvironment('withAuthThrottleAndCan')]
    public function test_the_page_middleware_follow_the_web_group_as_until_3x(): void
    {
        $middleware = $this->middlewareOfThePageRoute();

        // Laravel moves auth in front of SubstituteBindings and therefore in front of CachingHeaders,
        // the page routes of 3.x were sorted the same way
        $this->assertLessThan(array_search(Authenticate::class, $middleware, true), array_search(StartSession::class, $middleware, true));
        $this->assertLessThan(array_search(CachingHeaders::class, $middleware, true), array_search(Authenticate::class, $middleware, true));
    }

    #[DefineEnvironment('withAuth')]
    #[DataProvider('routeCache')]
    public function test_a_guest_gets_a_404_for_an_unknown_url_not_the_login(bool $cached): void
    {
        Route::get('/login', fn () => 'login')->name('login');
        $this->routes($cached);

        $this->get('/unknown')->assertNotFound();
    }

    #[DefineEnvironment('withAuth')]
    #[DataProvider('routeCache')]
    public function test_a_guest_is_sent_to_the_login_for_a_page(bool $cached): void
    {
        Route::get('/login', fn () => 'login')->name('login');
        $this->routes($cached);

        $this->get('/about')->assertRedirect('/login');

        $this->assertSame([], $this->pagesApi->requests);
    }

    #[DefineEnvironment('withThrottle')]
    #[DataProvider('routeCache')]
    public function test_throttle_does_not_count_unknown_urls(bool $cached): void
    {
        $this->routes($cached);

        $this->get('/unknown')->assertNotFound();
        $this->get('/unknown')->assertNotFound();
        $this->get('/unknown')->assertNotFound();

        $this->get('/about')->assertOk();
        $this->get('/about')->assertStatus(429);
    }

    #[DefineEnvironment('withRedirect')]
    #[DataProvider('routeCache')]
    public function test_a_redirecting_page_middleware_answers_before_the_page_is_loaded(bool $cached): void
    {
        Route::aliasMiddleware('to-team', fn ($request, $next) => $request->path() === 'about' ? redirect('/about/team') : $next($request));
        $this->routes($cached);

        $this->get('/about')->assertRedirect('/about/team');

        $this->assertSame([], $this->pagesApi->requests);
    }

    #[DefineEnvironment('withRedirect')]
    public function test_the_page_middleware_do_not_apply_to_other_routes(): void
    {
        Route::aliasMiddleware('to-team', fn ($request, $next) => redirect('/about/team'));
        Route::middleware('web')->get('/tier/{slug}', fn (string $slug) => "tier {$slug}");

        $this->get('/tier/about')->assertOk()->assertSee('tier about');
    }

    #[DataProvider('routeCache')]
    public function test_a_page_gets_the_cache_headers_a_404_does_not(bool $cached): void
    {
        $this->routes($cached);

        $this->get('/about')
            ->assertOk()
            ->assertHeader('CDN-Cache-Control')
            ->assertHeader('Vercel-CDN-Cache-Control');

        $this->get('/unknown')
            ->assertNotFound()
            ->assertHeaderMissing('CDN-Cache-Control')
            ->assertHeaderMissing('Vercel-CDN-Cache-Control');
    }
}
