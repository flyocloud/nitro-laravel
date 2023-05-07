<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Configuration;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as SupportServiceProvider;

class ServiceProvider extends SupportServiceProvider
{
    public function register(): void
    {
        $this->publishes([
            __DIR__ . '/../config/flyo.php' => config_path('flyo.php'),
            __DIR__ . '/../resources/views/cms.blade.php' => resource_path('views/cms.blade.php')
        ]);
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {

            $this->loadViewsFrom(__DIR__.'/../resources/views', 'flyo');
            Blade::componentNamespace('Flyo\\Laravel\\Components', 'flyo');

            $config = new Configuration();
            $config->setApiKey('token', config('flyo.token'));

            Configuration::setDefaultConfiguration($config);

            $response = (new ConfigApi(null, $config))->config();

            View::share('config', $response);

            foreach ($response->getPages() as $page) {
                Route::get($page, function() use ($page, $config) {
                    $response = (new PagesApi(null, $config))->page($page);

                    return view('cms', ['page' => $response]);
                });
            }
        }
    }
}