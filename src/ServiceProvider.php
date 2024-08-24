<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Model\Block;
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
            Blade::componentNamespace('Flyo\\Laravel\\Components', 'flyo');

            $config = new Configuration;
            $config->setApiKey('token', $configRepository->get('flyo.token'));

            Configuration::setDefaultConfiguration($config);

            $response = (new ConfigApi(null, $config))->config();
            $viewFactory->share('config', $response);

            $isLiveEdit = $configRepository->get('flyo.live_edit', false);

            Blade::directive('editable', function ($expression) use ($isLiveEdit) {

                // problem with caching!!!
                if (! $isLiveEdit) {
                    return '';
                }

                return "<?php
                    \$block = {$expression};
                    if (!\$block instanceof ".Block::class.") {
                        throw new \InvalidArgumentException('The argument passed to @editable must be an instance of Flyo\\Model\\Block.');
                    }
                    \$uid = \$block->getUid();
                    echo ' onclick=\"openBlockInFlyo(\''.\$uid.'\')\" ';
                ?>";
            });

            if ($isLiveEdit) {
                Head::$scripts[] = 'window.addEventListener("message", (event) => { if (event.data?.action === \'pageRefresh\') { window.location.reload(true); }});';

                Head::$scripts[] = <<<'EOT'
                function getActualWindow() {
    if (window === window.top) {
        return window;
    } else if (window.parent) {
        return window.parent;
    }
    return window;
  }

function openBlockInFlyo(uid) {
    getActualWindow().postMessage({
        action: 'openEdit',
        data: JSON.parse(JSON.stringify({item: {uid: uid}}))
    },'https://flyo.cloud')
}
EOT;
            }

            foreach ($response->getPages() as $page) {
                Route::get($page, function () use ($page, $config, $viewFactory) {
                    $response = (new PagesApi(null, $config))->page($page);

                    return $viewFactory->make('cms', ['page' => $response]);
                });
            }

        }
    }
}
