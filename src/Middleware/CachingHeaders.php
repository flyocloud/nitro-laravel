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

        $isDebug = Env::get('APP_DEBUG');

        if ($isDebug) {
            $response->headers->set('Flyo-Live-Edit', $isLiveEdit ? 1 : 0);
        }

        if ($response->isSuccessful() && ! $isDebug && ! $isLiveEdit) {
            $serverCacheTtl = $this->config->get('flyo.server_cache_ttl', 1200);
            $serverCacheTtl = is_numeric($serverCacheTtl) ? (int) $serverCacheTtl : 0;
            if ($serverCacheTtl > 0) {
                $response->header('Vercel-CDN-Cache-Control', 'max-age='.$serverCacheTtl);
                $response->header('CDN-Cache-Control', 'max-age='.$serverCacheTtl);
            }

            $clientCacheTtl = $this->config->get('flyo.client_cache_ttl', 900);
            $clientCacheTtl = is_numeric($clientCacheTtl) ? (int) $clientCacheTtl : 0;
            if ($clientCacheTtl > 0) {
                $response->header('Cache-Control', 'max-age='.$clientCacheTtl);
            }
        }

        return $response;
    }
}
