<?php

namespace Flyo\Laravel;

use Flyo\Api\ConfigApi;
use Flyo\Model\ConfigResponse;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * Loads the ConfigResponse of the Flyo api, at most once per request.
 *
 * The service provider binds the loader and the ConfigResponse as scoped, so the config is only
 * requested when something needs it (the page check of a CMS page, a view which renders `$config`,
 * a class which takes the ConfigResponse in its constructor) and a long running worker (octane)
 * requests it again for every request. The locale is the one of the moment of that first access.
 *
 * A failed request is remembered for the rest of the request as well: once the api did not answer,
 * the error page which renders after it must not wait for the api a second time.
 *
 * @internal
 */
class ConfigLoader
{
    private ?Throwable $failure = null;

    public function __construct(protected ConfigApi $api) {}

    public function load(): ConfigResponse
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            return $this->api->config(App::getLocale());
        } catch (Throwable $e) {
            throw $this->failure = $e;
        }
    }
}
