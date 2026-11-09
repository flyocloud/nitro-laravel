<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Api\SitemapApi;
use Flyo\Configuration;
use Flyo\Laravel\Console\TypesCommand;
use Flyo\Laravel\Controllers\SitemapController;
use Flyo\Laravel\Middleware\PreventDraftCaching;
use Flyo\Model\ConfigResponse;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as SupportServiceProvider;
use Illuminate\View\View;
use LogicException;
use RuntimeException;

class ServiceProvider extends SupportServiceProvider
{
    public function register(): void
    {
        $this->publishes([
            __DIR__.'/../config/flyo.php' => $this->app->configPath('flyo.php'),
            __DIR__.'/../resources/views/cms.blade.php' => $this->app->resourcePath('views/cms.blade.php'),
        ]);
    }

    public function boot(ViewFactory $viewFactory, ConfigRepository $configRepository): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', $configRepository->get('flyo.views_namespace', 'flyo'));
        Blade::componentNamespace('Flyo\\Laravel\\Components', 'flyo');

        /**
         * @editable($block)
         * Renders the `data-flyo-uid` marker for live-edit highlight wiring, see Editable.
         *
         * Registered in console contexts as well, otherwise `artisan view:cache` would not be able
         * to compile templates using the directive.
         */
        Blade::directive('editable', function ($expression) {
            return '<?php echo '.Editable::class."::attr({$expression}); ?>";
        });

        // The type generator writes files into the application, so it is only registered on the
        // command line, see TypesCommand.
        if ($this->app->runningInConsole()) {
            $this->commands([
                TypesCommand::class,
            ]);
        }

        if (! $this->app->runningInConsole()) {
            $this->bootRequest($viewFactory, $configRepository);
        }

        // The routes do not depend on the config of the api, they are registered on the command line
        // as well, so `php artisan route:cache` contains them. A route cache replaces every route
        // registered at runtime, so there is nothing to register once the routes are cached.
        if (! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            SitemapController::boot($configRepository);

            // a single fallback route serves every page of the Flyo config, see PageRoutes
            if ($configRepository->get('flyo.page_fallback', true)) {
                PageRoutes::fallback();

                // the routes of the application are loaded after the packages booted
                $this->app->booted(fn () => $this->ensureFallbackIsInPlace());
            }
        }
    }

    /**
     * Prepares the api access of an http request. No request is sent to the api here, the config is
     * loaded when something needs it, see ConfigLoader.
     */
    protected function bootRequest(ViewFactory $viewFactory, ConfigRepository $configRepository): void
    {
        $locales = $configRepository->get('flyo.locales', []);
        if (! empty($locales) && count($locales) > 1) {
            $request = request();
            $locale = $request->segment(1);
            if ($locale && in_array($locale, $locales)) {
                App::setLocale($locale);
            }
        }

        $token = $configRepository->get('flyo.token');
        if (empty($token)) {
            throw new RuntimeException('The Flyo token is not set. Please set the FLYO_TOKEN environment variable or add it to the config/flyo.php file.');
        }

        $config = new Configuration;
        $config->setApiKey('token', $token);

        Configuration::setDefaultConfiguration($config);

        $this->app->singleton(Configuration::class, function () use ($config) {
            return $config;
        });

        $this->app->singleton(SitemapApi::class, function () use ($config) {
            return new SitemapApi(null, $config);
        });

        $this->app->bind(ConfigApi::class, function () use ($config) {
            return new ConfigApi(null, $config);
        });

        $this->app->bind(PagesApi::class, function () use ($config) {
            return new PagesApi(null, $config);
        });

        // Loaded on the first access, at most once per request. Scoped bindings are dropped after
        // every request of a long running worker (octane), so the config is never out of date there.
        $this->app->scoped(ConfigLoader::class);
        $this->app->scoped(ConfigResponse::class, function ($app) {
            return $app->make(ConfigLoader::class)->load();
        });

        // `$config` in the views. A composer instead of View::share(), so the config is only loaded
        // when a view is rendered. Data which is handed to the view, or shared by the application,
        // under the same name wins.
        $viewFactory->composer($configRepository->get('flyo.config_views', '*') ?? [], function (View $view) {
            if (array_key_exists('config', $view->getData()) || array_key_exists('config', $view->getFactory()->getShared())) {
                return;
            }

            $view->with('config', $this->app->make(ConfigResponse::class));
        });

        $isLiveEdit = $configRepository->get('flyo.live_edit', false);
        Log::debug('Flyo live edit is '.($isLiveEdit ? 'enabled' : 'disabled'));

        // Loads the nitro js bridge and wires page refresh, scroll to block, the editor
        // connection handshake and the click-to-edit hover overlay, see LiveEdit.
        LiveEdit::boot($configRepository);

        // A response rendered from a draft link must never be cached, wherever the route which
        // rendered it lives, therefore the middleware is registered globally, see DraftMode.
        PreventDraftCaching::register($this->app);
    }

    /**
     * A `Route::fallback()` of the application takes the slot of the fallback route of the package
     * and every CMS page ends up in it, without an error. Outside of production an http request
     * fails with an explanation, otherwise it is logged. The command line is never interrupted, an
     * exception there would also block `php artisan config:clear`.
     */
    protected function ensureFallbackIsInPlace(): void
    {
        $route = PageRoutes::replacedFallback($this->app->make('router')->getRoutes());

        if ($route === null) {
            return;
        }

        $message = 'flyo/nitro-laravel: the Route::fallback() of the application ('.$route->getActionName().') replaced the fallback route which serves the CMS pages, '
            .'so no CMS page is reachable. Either remove it (a custom 404 page belongs into resources/views/errors/404.blade.php), '
            ."or set 'page_fallback' => false in config/flyo.php and call Flyo\\Laravel\\PageRoutes::dispatch(\$request) from it. See UPGRADE.md.";

        if (! $this->app->runningInConsole() && ! $this->app->isProduction()) {
            throw new LogicException($message);
        }

        Log::error($message);
    }
}
