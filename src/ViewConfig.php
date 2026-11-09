<?php

namespace Flyo\Laravel;

use Flyo\Model\ConfigResponse;
use Illuminate\Container\Container;
use Illuminate\View\Factory;
use ReflectionClass;

/**
 * The `$config` every view gets.
 *
 * It is shared with View::share(), like until 3.x, but what is shared is a lazy object (PHP 8.4): it
 * is a Flyo\Model\ConfigResponse for every type check, but the config is only loaded from the api
 * when a view reads it, `$config->getContainers()` for example. At most once per request, see
 * ConfigLoader. A view which does not use `$config`, a partial or a Livewire update for example,
 * costs no api request.
 *
 * @internal
 */
class ViewConfig
{
    /**
     * The lazy config which is currently shared with the views.
     */
    private static ?ConfigResponse $shared = null;

    /**
     * Shares a lazy config with every view.
     */
    public static function share(Factory $views): void
    {
        $views->share('config', self::$shared = self::lazy());
    }

    /**
     * Shares a fresh lazy config once a request is handled. A long running worker (octane) keeps the
     * view factory, and the config the previous request loaded, for the next request. A `config`
     * which the application shared itself is left alone.
     */
    public static function renew(Factory $views): void
    {
        if (self::$shared !== null && ($views->getShared()['config'] ?? null) === self::$shared) {
            self::share($views);
        }
    }

    /**
     * A ConfigResponse which loads itself on the first read, from the container of the request.
     */
    public static function lazy(): ConfigResponse
    {
        return (new ReflectionClass(ConfigResponse::class))->newLazyProxy(
            fn () => Container::getInstance()->make(ConfigResponse::class)
        );
    }
}
