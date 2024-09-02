<?php

namespace Flyo\Laravel\Middleware;

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Env;

class CachingHeaders
{
    public function __construct(protected Repository $config) {}

    public function handle(Request $request, \Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $isLiveEdit = $this->config->get('flyo.live_edit', false);

        if ($response->isSuccessful() && ! Env::get('APP_DEBUG') && ! $isLiveEdit) {
            $serverCacheTtl = $this->config->get('flyo.server_cache_ttl', 1200);
            $clientCacheTtl = $this->config->get('flyo.client_cache_ttl', 900);
            $response->header('Vercel-CDN-Cache-Control', 'max-age='.$serverCacheTtl);
            $response->header('CDN-Cache-Control', 'max-age='.$serverCacheTtl);
            $response->header('Cache-Control', 'max-age='.$clientCacheTtl);
        }

        return $response;
    }
}
