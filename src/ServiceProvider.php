<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Configuration;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider as SupportServiceProvider;

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
            Blade::componentNamespace('Flyo\\Laravel\\Components', $configRepository->get('flyo.components_namespace', 'flyo'));

            $config = new Configuration;
            $config->setApiKey('token', $configRepository->get('flyo.token'));

            Configuration::setDefaultConfiguration($config);

            $response = (new ConfigApi(null, $config))->config();

            $viewFactory->share('config', $response);

            foreach ($response->getPages() as $page) {
                Route::get($page, function () use ($page, $config, $viewFactory) {
                    $response = (new PagesApi(null, $config))->page($page);

                    return $viewFactory->make('cms', ['page' => $response]);
                });
            }
        }
    }
}
