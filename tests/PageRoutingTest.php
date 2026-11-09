<?php

namespace Flyo\Laravel\Tests;

use Flyo\Api\SitemapApi;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which url ends up on which CMS page, with the routes as they are registered and with cached routes.
 *
 * Until 3.x every page had a route of its own, the expectations below are the answers of those
 * routes. The fallback route of 4.0 matches every path and leaves the decision to EnsurePageExists,
 * which compares the path the same way Laravel compared it to the routes.
 */
class PageRoutingTest extends HttpTestCase
{
    #[DataProvider('routeCache')]
    public function test_a_page_of_the_config_is_rendered(bool $cached): void
    {
        $this->routes($cached);

        $this->get('/about')
            ->assertOk()
            ->assertSee('page:title of about', false);

        $this->assertSame([['about', 'en']], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_the_page_is_requested_with_its_path_as_the_config_delivers_it(bool $cached): void
    {
        $this->fakeConfig(['/kontakt/']);
        $this->routes($cached);

        $this->get('/kontakt')->assertOk();

        $this->assertSame([['/kontakt/', 'en']], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_the_home_page_is_served_for_the_root_url(bool $cached): void
    {
        $this->routes($cached);

        $this->get('/')->assertOk()->assertSee('page:title of', false);

        $this->assertSame([['', 'en']], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_a_home_page_delivered_as_slash_is_served_for_the_root_url(bool $cached): void
    {
        $this->fakeConfig(['/', 'about']);
        $this->routes($cached);

        $this->get('/')->assertOk();

        $this->assertSame([['/', 'en']], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_the_root_url_is_not_found_without_a_home_page(bool $cached): void
    {
        $this->fakeConfig(['about']);
        $this->routes($cached);

        $this->get('/')->assertNotFound();

        $this->assertSame([], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_nested_pages_trailing_slashes_and_head_requests_are_served(bool $cached): void
    {
        $this->routes($cached);

        $this->get('/about/team')->assertOk()->assertSee('page:title of about/team', false);
        $this->get('/about/')->assertOk()->assertSee('page:title of about', false);
        $this->head('/about')->assertOk();
    }

    #[DataProvider('routeCache')]
    public function test_upper_and_lower_case_count(bool $cached): void
    {
        $this->routes($cached);

        $this->get('/About')->assertNotFound();
        $this->get('/ABOUT/team')->assertNotFound();

        $this->assertSame([], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_an_encoded_path_is_compared_decoded(bool $cached): void
    {
        $this->fakeConfig(['über-uns', 'a b', 'c+d']);
        $this->routes($cached);

        $this->get('/%C3%BCber-uns')->assertOk()->assertSee('page:title of über-uns', false);
        $this->get('/a%20b')->assertOk();
        $this->get('/c+d')->assertOk();
        $this->get('/c%2Bd')->assertOk();

        // a plus is no space in a path, neither for the router of Laravel
        $this->get('/a+b')->assertNotFound();

        $this->assertSame(['über-uns', 'a b', 'c+d', 'c+d'], array_column($this->pagesApi->requests, 0));
    }

    #[DataProvider('routeCache')]
    public function test_an_unknown_url_is_not_found_without_running_the_page_middleware(bool $cached): void
    {
        $this->routes($cached);

        $response = $this->get('/unknown');

        $response->assertNotFound();

        // the page check runs before the web group: no session cookie, no cache headers
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('CDN-Cache-Control'));
        $this->assertSame([], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_a_post_on_a_page_is_not_allowed(bool $cached): void
    {
        $this->routes($cached);

        $this->post('/about')
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET, HEAD');
    }

    #[DataProvider('routeCache')]
    public function test_a_post_on_an_unknown_url_is_not_allowed_either(bool $cached): void
    {
        // a route which matches every path makes Laravel answer every other method with a 405, the
        // same as any application with a catch-all route; until 3.x this was a 404
        $this->routes($cached);

        $this->post('/unknown')->assertStatus(405);
        $this->put('/unknown')->assertStatus(405);
        $this->delete('/unknown')->assertStatus(405);
    }

    #[DataProvider('routeCache')]
    public function test_options_on_an_unknown_url_answers_with_the_allowed_methods(bool $cached): void
    {
        $this->routes($cached);

        $this->call('OPTIONS', '/unknown')
            ->assertOk()
            ->assertHeader('Allow', 'GET,HEAD');
    }

    #[DataProvider('routeCache')]
    public function test_a_route_of_the_application_with_the_path_of_a_page_wins(bool $cached): void
    {
        Route::middleware('web')->get('/about', fn () => 'application about');
        $this->routes($cached);

        $this->get('/about')->assertOk()->assertSee('application about');
        $this->get('/about/team')->assertOk()->assertSee('page:title of about/team', false);

        $this->assertSame([['about/team', 'en']], $this->pagesApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_a_wildcard_route_of_the_application_wins_over_the_pages(bool $cached): void
    {
        Route::middleware('web')->get('/{slug}', fn (string $slug) => "wildcard {$slug}");
        $this->routes($cached);

        $this->get('/about')->assertOk()->assertSee('wildcard about');
        $this->get('/about/team')->assertOk()->assertSee('page:title of about/team', false);
    }

    #[DataProvider('routeCache')]
    public function test_a_constrained_wildcard_route_leaves_the_pages_to_the_package(bool $cached): void
    {
        Route::middleware('web')->get('/{slug}', fn (string $slug) => "news {$slug}")->where('slug', 'news-[0-9]+');
        $this->routes($cached);

        $this->get('/news-42')->assertOk()->assertSee('news news-42');
        $this->get('/about')->assertOk()->assertSee('page:title of about', false);
    }

    #[DataProvider('routeCache')]
    public function test_routes_of_other_packages_win(bool $cached): void
    {
        Route::middleware('web')->get('/livewire/update', fn () => 'livewire');
        $this->fakeConfig(['livewire/update']);
        $this->routes($cached);

        $this->get('/livewire/update')->assertOk()->assertSee('livewire');
    }

    #[DataProvider('routeCache')]
    public function test_the_sitemap_is_served(bool $cached): void
    {
        $api = $this->createStub(SitemapApi::class);
        $api->method('sitemap')->willReturn([]);
        $this->app->instance(SitemapApi::class, $api);
        $this->routes($cached);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        // the sitemap does not need the config
        $this->assertSame([], $this->configApi->requests);
    }

    #[DataProvider('routeCache')]
    public function test_the_route_tells_a_page_apart(bool $cached): void
    {
        Route::aliasMiddleware('remember-page', function ($request, $next) {
            $response = $next($request);
            $response->headers->set('X-Route-Is-Page', $request->routeIs('flyo.page') ? 'yes' : 'no');
            $response->headers->set('X-Page', (string) $request->attributes->get('flyo.page'));

            return $response;
        });
        Route::middleware(['web', 'remember-page'])->get('/tier/{slug}', fn () => 'tier');
        $this->pageRoute()->middleware('remember-page');
        $this->routes($cached);

        $this->get('/about/team')->assertHeader('X-Route-Is-Page', 'yes')->assertHeader('X-Page', 'about/team');
        $this->get('/tier/a-slug')->assertHeader('X-Route-Is-Page', 'no')->assertHeader('X-Page', '');
    }
}
