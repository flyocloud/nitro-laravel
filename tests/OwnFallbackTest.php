<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\PageRoutes;
use Flyo\Laravel\ServiceProvider;
use Flyo\Laravel\Tests\Fakes\AppFallbackProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LogicException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Only one fallback route can exist. An application with a fallback of its own turns the one of the
 * package off and hands the CMS pages over with PageRoutes::dispatch(). One which forgets to does not
 * fail silently.
 */
class OwnFallbackTest extends HttpTestCase
{
    private static bool $withApplicationFallback = false;

    protected function getPackageProviders($app): array
    {
        return self::$withApplicationFallback
            ? [ServiceProvider::class, AppFallbackProvider::class]
            : [ServiceProvider::class];
    }

    protected function withoutPageFallback($app): void
    {
        $app['config']->set('flyo.page_fallback', false);
        $app['config']->set('flyo.page_middleware', ['remember-cookie']);
    }

    private function ownFallback(): void
    {
        Route::aliasMiddleware('remember-cookie', function ($request, $next) {
            $response = $next($request);
            $response->headers->set('X-Visitor', (string) $request->cookie('visitor'));

            return $response;
        });

        Route::middleware('web')->group(function () {
            Route::fallback(function (Request $request) {
                if ($request->path() === 'old-about') {
                    return redirect('/about', 301);
                }

                return PageRoutes::dispatch($request);
            });
        });
    }

    #[DefineEnvironment('withoutPageFallback')]
    #[DataProvider('routeCache')]
    public function test_an_own_fallback_hands_the_pages_over(bool $cached): void
    {
        $this->ownFallback();
        $this->routes($cached);

        $this->get('/old-about')->assertRedirect('/about');
        $this->get('/about')
            ->assertOk()
            ->assertSee('page:title of about', false)
            ->assertHeader('CDN-Cache-Control');
        $this->get('/unknown')->assertNotFound()->assertSee('not found');

        $this->assertSame([['about', 'en']], $this->pagesApi->requests);
    }

    #[DefineEnvironment('withoutPageFallback')]
    public function test_dispatch_skips_the_middleware_which_already_ran_on_the_route(): void
    {
        $runs = 0;
        Route::aliasMiddleware('remember-cookie', function ($request, $next) use (&$runs) {
            $runs++;

            return $next($request);
        });

        // the fallback of the application carries a page middleware itself
        Route::middleware(['web', 'remember-cookie'])->group(function () {
            Route::fallback(fn (Request $request) => PageRoutes::dispatch($request));
        });

        $this->withCookie('visitor', 'returning')->get('/about')->assertOk();

        $this->assertSame(1, $runs);
    }

    #[DefineEnvironment('withoutPageFallback')]
    public function test_dispatch_marks_the_request_as_a_page(): void
    {
        $this->ownFallback();
        Route::aliasMiddleware('remember-cookie', function ($request, $next) {
            $response = $next($request);
            $response->headers->set('X-Page', (string) $request->attributes->get('flyo.page'));

            return $response;
        });

        $this->get('/about/team')->assertHeader('X-Page', 'about/team');
    }

    #[DefineEnvironment('withoutPageFallback')]
    public function test_dispatch_finds_no_page_without_its_middleware(): void
    {
        $this->ownFallback();
        $this->withoutMiddleware();

        $this->get('/about')->assertOk();
        $this->get('/unknown')->assertNotFound();
    }

    public function test_a_replaced_fallback_fails_a_request_outside_of_production(): void
    {
        self::$withApplicationFallback = true;

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage("set 'page_fallback' => false in config/flyo.php and call Flyo\\Laravel\\PageRoutes::dispatch(\$request)");

            $this->refreshApplication();
        } finally {
            self::$withApplicationFallback = false;
        }
    }

    public function test_a_replaced_fallback_is_logged_in_production(): void
    {
        Route::fallback(fn () => 'application');
        $this->app['env'] = 'production';
        Log::spy();

        (fn () => $this->ensureFallbackIsInPlace())->call($this->app->getProvider(ServiceProvider::class));

        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message) => str_contains($message, 'no CMS page is reachable'));
    }

    #[DefineEnvironment('withoutPageFallback')]
    public function test_an_own_fallback_is_fine_with_the_package_fallback_turned_off(): void
    {
        self::$withApplicationFallback = true;

        try {
            $this->refreshApplication();
        } finally {
            self::$withApplicationFallback = false;
        }

        $this->get('/anything')->assertOk()->assertSee('application fallback');
    }
}
