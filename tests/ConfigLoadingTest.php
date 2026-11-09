<?php

namespace Flyo\Laravel\Tests;

use Flyo\Api\ConfigApi;
use Flyo\Laravel\ConfigLoader;
use Flyo\Laravel\Controllers\EntityController;
use Flyo\Laravel\ServiceProvider;
use Flyo\Laravel\Tests\Fakes\ConfigApiFake;
use Flyo\Laravel\Tests\Fakes\ConfigAwareMiddleware;
use Flyo\Model\ConfigResponse;
use Flyo\Model\Entity;
use Flyo\Model\EntityInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use ReflectionClass;
use RuntimeException;

/**
 * The config of the api is loaded when something needs it, at most once per request. Until 3.x the
 * service provider requested it while booting, for every single request.
 */
class ConfigLoadingTest extends HttpTestCase
{
    protected function withConfigAwarePageMiddleware($app): void
    {
        $app['config']->set('flyo.page_middleware', [ConfigAwareMiddleware::class]);
    }

    public function test_booting_requests_nothing(): void
    {
        $this->assertSame([], $this->configApi->requests);
        $this->assertFalse($this->app->resolved(ConfigResponse::class));
    }

    public function test_a_route_without_a_view_requests_no_config(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/api/items', fn () => ['items' => []]);
            Route::get('/image/{id}', fn () => response()->streamDownload(fn () => print ('binary'), 'image.jpg'));
            Route::get('/go', fn () => redirect('/about'));
            Route::post('/comments', fn () => redirect('/thanks'));
            Route::get('/up', fn () => 'ok');
        });

        $this->getJson('/api/items')->assertOk();
        $this->get('/image/1')->assertOk();
        $this->get('/go')->assertRedirect('/about');
        // the csrf check is not skipped, the application does not run on the command line here
        $this->withSession(['_token' => 'a-token'])->post('/comments', ['_token' => 'a-token'])->assertRedirect('/thanks');
        $this->get('/up')->assertOk();

        $this->assertSame([], $this->configApi->requests);
    }

    #[DefineEnvironment('withConfigAwarePageMiddleware')]
    public function test_a_page_requests_the_config_exactly_once(): void
    {
        // the page check, a page middleware which takes the config in its constructor, the layout
        // component, an included partial and the debug info component all read the config
        $this->get('/about')
            ->assertOk()
            ->assertHeader('X-Pages', '4')
            ->assertSee('nav:,about,about/team,über-uns', false)
            ->assertSee('page:title of about', false)
            ->assertSee('footer:4', false)
            ->assertSee('version:7', false);

        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_an_entity_page_requests_the_config_while_its_layout_renders(): void
    {
        Route::middleware('web')->get('/tier/{slug}', function (string $slug) {
            // nothing needed the config so far
            $this->assertSame([], $this->configApi->requests);

            return app(EntityController::class)
                ->resolve(fn ($api, $param) => new Entity(['entity' => new EntityInterface([
                    'entity_title' => $param,
                    'entity_teaser' => 'teaser',
                    'entity_image' => 'image.jpg',
                ])]))
                ->render($slug, 'entity');
        });

        $this->get('/tier/a-slug')->assertOk()->assertSee('entity')->assertSee('nav:', false);

        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_an_unknown_url_requests_the_config_once_for_the_page_check_and_the_error_page(): void
    {
        $this->get('/unknown')->assertNotFound()->assertSee('not found')->assertSee('nav:,about', false);

        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_the_config_is_shared_with_the_views_as_a_lazy_object(): void
    {
        $config = View::getShared()['config'];

        $this->assertInstanceOf(ConfigResponse::class, $config);
        $this->assertTrue((new ReflectionClass(ConfigResponse::class))->isUninitializedLazyObject($config));
        $this->assertSame([], $this->configApi->requests);

        // the first read loads it
        $this->assertSame(['', 'about', 'about/team', 'über-uns'], $config->getPages());
        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_a_view_which_does_not_read_the_config_requests_none(): void
    {
        // a partial, a mail or a Livewire update which does not use $config
        Route::middleware('web')->get('/partial', fn () => view('partials.plain'));

        $this->get('/partial')->assertOk()->assertSee('plain');

        $this->assertSame([], $this->configApi->requests);
    }

    public function test_handing_the_config_on_does_not_load_it(): void
    {
        Route::middleware('web')->get('/forwarded', fn () => view('forwarded'));

        $this->get('/forwarded')->assertOk()->assertSee('plain');

        $this->assertSame([], $this->configApi->requests);
    }

    public function test_a_view_which_reads_the_config_loads_it_once(): void
    {
        // the layout, an included partial and the debug info component all read it
        Route::middleware('web')->get('/entity', fn () => view('entity'));

        $this->get('/entity')->assertOk()->assertSee('nav:,about', false)->assertSee('footer:4', false);

        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_a_config_handed_to_the_view_wins(): void
    {
        Route::middleware('web')->get('/handed', fn () => view('handed', ['config' => 'mine']));

        $this->get('/handed')->assertOk()->assertSee('handed:mine');

        $this->assertSame([], $this->configApi->requests);
    }

    public function test_a_config_shared_by_the_application_wins(): void
    {
        View::share('config', 'shared');
        Route::middleware('web')->get('/handed', fn () => view('handed'));

        $this->get('/handed')->assertOk()->assertSee('handed:shared');
        $this->get('/handed')->assertOk()->assertSee('handed:shared');

        $this->assertSame([], $this->configApi->requests);
    }

    public function test_a_view_rendered_to_a_string_gets_the_config_as_well(): void
    {
        // a mail rendered in a request, for example
        Route::middleware('web')->get('/mail', fn () => ['html' => view('partials.footer')->render()]);

        $this->getJson('/mail')->assertOk()->assertJsonPath('html', "<footer>footer:4</footer>\n");

        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_every_access_within_a_request_gets_the_same_config(): void
    {
        $this->assertSame($this->app->make(ConfigResponse::class), $this->app->make(ConfigResponse::class));
        $this->assertSame(['en'], $this->configApi->requests);
    }

    public function test_a_long_running_worker_loads_the_config_for_every_request_in_its_locale(): void
    {
        $this->get('/about')->assertOk();

        // what octane does between two requests
        $this->app->forgetScopedInstances();
        App::setLocale('fr');

        $this->get('/about')->assertOk();

        $this->assertSame(['en', 'fr'], $this->configApi->requests);
        $this->assertSame([['about', 'en'], ['about', 'fr']], $this->pagesApi->requests);
    }

    public function test_a_long_running_worker_shares_a_fresh_config_with_the_views_of_every_request(): void
    {
        Route::middleware('web')->get('/entity', fn () => view('entity'));

        $this->get('/entity')->assertOk();

        // what octane does between two requests, the view factory and its shared data stay
        $this->app->forgetScopedInstances();
        App::setLocale('fr');

        $this->get('/entity')->assertOk();

        $this->assertSame(['en', 'fr'], $this->configApi->requests);
    }

    public function test_the_config_is_loaded_in_the_locale_of_its_first_access(): void
    {
        Route::aliasMiddleware('german', function ($request, $next) {
            App::setLocale('de');

            return $next($request);
        });
        Route::middleware(['web', 'german'])->get('/tier', fn () => view('entity'));

        $this->get('/tier')->assertOk();

        $this->assertSame(['de'], $this->configApi->requests);
    }

    public function test_a_failing_api_only_breaks_the_requests_which_need_the_config(): void
    {
        $this->fakeConfig(new RuntimeException('the api is down'));
        Route::middleware('web')->get('/up', fn () => 'ok');

        $this->get('/up')->assertOk();
        $this->assertSame([], $this->configApi->requests);

        // the error page renders the layout as well, the api is not asked a second time
        $this->get('/about')->assertStatus(500);
        $this->assertCount(1, $this->configApi->requests);
    }

    public function test_a_failed_config_request_is_not_repeated_within_the_request(): void
    {
        $api = new ConfigApiFake($exception = new RuntimeException('the api is down'));
        $loader = new ConfigLoader($api);

        foreach ([1, 2] as $attempt) {
            try {
                $loader->load();
                $this->fail('the loader has to fail');
            } catch (RuntimeException $e) {
                $this->assertSame($exception, $e);
            }
        }

        $this->assertCount(1, $api->requests);
    }

    public function test_a_missing_token_fails_the_boot(): void
    {
        $this->app['config']->set('flyo.token', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Flyo token is not set.');

        $this->app->call([new ServiceProvider($this->app), 'boot']);
    }

    public function test_the_api_classes_use_the_configured_token(): void
    {
        $this->app->forgetInstance(ConfigApi::class);

        $this->assertSame('test-token', $this->app->make(ConfigApi::class)->getConfig()->getApiKey('token'));
    }
}
