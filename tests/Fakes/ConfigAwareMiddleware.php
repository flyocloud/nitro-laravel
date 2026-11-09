<?php

namespace Flyo\Laravel\Tests\Fakes;

use Closure;
use Flyo\Model\ConfigResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page middleware which takes the config in its constructor, the way the readme suggests it.
 */
class ConfigAwareMiddleware
{
    public function __construct(protected ConfigResponse $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Pages', (string) count($this->config->getPages() ?? []));

        return $response;
    }
}
