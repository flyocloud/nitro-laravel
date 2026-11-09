<?php

namespace Flyo\Laravel\Tests\Fakes;

use Flyo\Api\ConfigApi;
use Flyo\Model\ConfigResponse;
use Throwable;

/**
 * Stands in for the config endpoint, records the locale of every request instead of sending one.
 */
class ConfigApiFake extends ConfigApi
{
    /**
     * @var array<int, string|null> the locale of every config request, in their order
     */
    public array $requests = [];

    public function __construct(private ConfigResponse|Throwable $response) {}

    public function config($lang = null, string $contentType = self::contentTypes['config'][0])
    {
        $this->requests[] = $lang;

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}
