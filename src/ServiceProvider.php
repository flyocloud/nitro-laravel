<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Api\SitemapApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Laravel\Controllers\SitemapController;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Model\ConfigResponse;
use Flyo\Model\Page;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as SupportServiceProvider;
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

        if (! $this->app->runningInConsole()) {
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

            $response = (new ConfigApi(null, $config))->config(App::getLocale());

            $this->app->singleton(ConfigResponse::class, function () use ($response) {
                return $response;
            });
            $viewFactory->share('config', $response);

            $isLiveEdit = $configRepository->get('flyo.live_edit', false);
            Log::debug('Flyo live edit is '.($isLiveEdit ? 'enabled' : 'disabled'));

            // Loads the nitro js bridge and wires page refresh, scroll to block, the editor
            // connection handshake and the click-to-edit hover overlay, see LiveEdit.
            LiveEdit::boot($configRepository);

            Route::get('/sitemap.xml', [SitemapController::class, 'render'])->middleware(CachingHeaders::class);

            Route::middleware('web')->group(function () use ($response, $config, $viewFactory) {
                foreach ($response->getPages() as $page) {
                    Route::get($page, function () use ($page, $config, $viewFactory) {
                        $pageResponse = (new PagesApi(null, $config))->page($page, App::getLocale());

                        $this->app->singleton(Page::class, function () use ($pageResponse) {
                            return $pageResponse;
                        });

                        Head::metaTitle($pageResponse->getMetaJson()->getTitle());
                        Head::metaDescription($pageResponse->getMetaJson()->getDescription());
                        Head::metaImage($pageResponse->getMetaJson()->getImage());

                        return $viewFactory->make('cms', ['page' => $pageResponse]);
                    })->middleware(CachingHeaders::class);
                }
            });
        }
    }
}
