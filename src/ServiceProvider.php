<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Api\PagesApi;
use Flyo\Configuration;
use Flyo\Laravel\Components\Head;
use Flyo\Laravel\Controllers\SitemapController;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Laravel\Middleware\CspFrameAncestors;
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

            /**
             * @editable($block)
             * Renders attributes for live-edit highlight wiring.
             */
            Blade::directive('editable', function ($expression) {
                return "<?php
                    if (app('config')->get('flyo.live_edit', false)) {
                        \$block = {$expression};
                        \$isValidBlock = false;
                        
                        // Try instanceof first (preferred method)
                        if (\$block instanceof ".Block::class.") {
                            \$isValidBlock = true;
                        }
                        // Fallback: check exact class name (handles class loading issues)
                        elseif (is_object(\$block) && get_class(\$block) === 'Flyo\\Model\\Block') {
                            \$isValidBlock = true;
                        }
                        // Final fallback: duck typing (has required method)
                        elseif (is_object(\$block) && method_exists(\$block, 'getUid')) {
                            \$isValidBlock = true;
                        }
                        
                        if (!\$isValidBlock) {
                            \$actualType = is_object(\$block) ? get_class(\$block) : gettype(\$block);
                            throw new \InvalidArgumentException('The argument passed to @editable must be a Flyo Block object. Received: ' . \$actualType);
                        }
                        
                        \$uid = \$block->getUid();
                        echo ' data-flyo-uid=\"' . htmlspecialchars(\$uid, ENT_QUOTES, 'UTF-8') . '\" ';
                    }
                ?>";
            });

            $isLiveEdit = $configRepository->get('flyo.live_edit', false);
            Log::debug('Flyo live edit is '.($isLiveEdit ? 'enabled' : 'disabled'));

            if ($isLiveEdit) {
                // Keep page-refresh support

                // Load Nitro JS Bridge once and wire highlights once (no observers/polling needed)
                Head::script(<<<'JS'
(function(){
  // Wire function: attach highlightAndClick to all markers
  function wire(){
    if (!window.nitroJsBridge || typeof window.nitroJsBridge.highlightAndClick !== 'function') return;

    if (window.nitroJsBridge.reload) { window.nitroJsBridge.reload(); }
    var nodes = document.querySelectorAll('[data-flyo-uid]');
    for (var i=0; i<nodes.length; i++){
      var el = nodes[i];
      var uid = el.getAttribute('data-flyo-uid');
      if (uid) { window.nitroJsBridge.highlightAndClick(uid, el); }
    }
  }

  // Inject the bridge (unpkg). Run wire() on load; also run once on DOM ready (no-op if bridge not ready yet).
  function loadBridgeAndWire(){
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/@flyo/nitro-js-bridge@1/dist/nitro-js-bridge.umd.cjs';
    s.async = true;
    s.onload = wire;
    document.head.appendChild(s);

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', wire, { once: true });
    } else {
      wire();
    }
  }

  loadBridgeAndWire();
})();
JS);
            }

            Route::get('/sitemap.xml', [SitemapController::class, 'render'])->middleware([CachingHeaders::class]);

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
                    })->middleware([CachingHeaders::class, CspFrameAncestors::class]);
                }
            });
        }
    }
}
