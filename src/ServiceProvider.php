<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Laravel\Controllers\SitemapController;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Model\Block;
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
        if (! $this->app->runningInConsole()) {

            $this->loadViewsFrom(__DIR__.'/../resources/views', $configRepository->get('flyo.views_namespace', 'flyo'));
            Blade::componentNamespace('Flyo\\Laravel\\Components', 'flyo');

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

            $response = (new ConfigApi(null, $config))->config(App::getLocale());

            $this->app->singleton(ConfigResponse::class, function () use ($response) {
                return $response;
            });
            $viewFactory->share('config', $response);

            Blade::directive('editable', function ($expression) {
                return "<?php
                    if (app('config')->get('flyo.live_edit', false)) {
                        \$block = {$expression};
                        if (!\$block instanceof ".Block::class.") {
                            \$actualType = is_object(\$block) ? get_class(\$block) : gettype(\$block);
                            throw new \InvalidArgumentException('The argument passed to @editable must be an instance of Flyo\\Model\\Block, but received: ' . \$actualType);
                        }
                        \$uid = \$block->getUid();
                        echo ' onclick=\"openBlockInFlyo(\''.\$uid.'\')\" ';
                    }
                ?>";
            });

            $isLiveEdit = $configRepository->get('flyo.live_edit', false);

            Log::debug('Flyo live edit is '.($isLiveEdit ? 'enabled' : 'disabled'));

            if ($isLiveEdit) {
                Head::script('window.addEventListener("message",event=>{if(event.data?.action===\'pageRefresh\'){window.location.reload(true);}});');
                Head::script('function getActualWindow(){return window===window.top?window:window.parent?window.parent:window;}function openBlockInFlyo(uid){getActualWindow().postMessage({action:\'openEdit\',data:JSON.parse(JSON.stringify({item:{uid:uid}}))},\'https://flyo.cloud\')}');
            }

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
