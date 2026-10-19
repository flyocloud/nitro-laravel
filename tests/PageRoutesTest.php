<?php

namespace Flyo\Laravel\Tests;

use Flyo\Configuration;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Laravel\PageRoutes;
use Illuminate\Config\Repository;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * The page routes are closures of the package, flyo.page_middleware is the way for an application to
 * act on a CMS page, and only on a CMS page, before the page is loaded from the api.
 */
class PageRoutesTest extends TestCase
{
    private function boot(array $flyo = []): void
    {
        PageRoutes::boot(['about', 'about/team'], new Configuration, new Repository(['flyo' => $flyo]), $this->app['view']);
    }

    private function route(string $uri): ?Route
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        return null;
    }

    public function test_a_route_is_registered_for_every_page_in_the_web_group(): void
    {
        $this->boot();

        foreach (['about', 'about/team'] as $uri) {
            $this->assertNotNull($this->route($uri));
            $this->assertSame(['web', CachingHeaders::class], $this->route($uri)->middleware());
        }
    }

    public function test_the_page_middleware_follow_the_caching_headers_in_their_order(): void
    {
        $this->boot(['page_middleware' => ['App\\Http\\Middleware\\First', 'can:view,page']]);

        $this->assertSame(
            ['web', CachingHeaders::class, 'App\\Http\\Middleware\\First', 'can:view,page'],
            $this->route('about')->middleware(),
        );
    }

    public function test_a_config_without_the_key_or_a_single_string_is_accepted(): void
    {
        // a published config of a previous version does not have the key
        $this->assertSame([CachingHeaders::class], PageRoutes::middleware(new Repository(['flyo' => []])));
        $this->assertSame([CachingHeaders::class, 'auth'], PageRoutes::middleware(new Repository(['flyo' => ['page_middleware' => 'auth']])));
    }

    public function test_a_redirecting_page_middleware_answers_before_the_page_is_loaded(): void
    {
        // the Configuration carries no token, a request to the api would fail with an exception
        RouteFacade::aliasMiddleware('to-team', fn ($request, $next) => redirect('/about/team'));
        $this->boot(['page_middleware' => ['to-team']]);

        $this->get('/about')->assertRedirect('/about/team');
    }

    public function test_the_page_middleware_do_not_apply_to_other_routes(): void
    {
        RouteFacade::aliasMiddleware('to-team', fn ($request, $next) => redirect('/about/team'));
        $this->boot(['page_middleware' => ['to-team']]);
        RouteFacade::get('/tier/{slug}', fn (string $slug) => "tier {$slug}");

        $this->get('/tier/about')->assertOk()->assertSee('tier about');
    }
}
