<?php

namespace Flyo\Laravel\Middleware;

use Flyo\Laravel\DraftMode;
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
            $response->headers->set('Flyo-Live-Edit', $isLiveEdit ? '1' : '0');
            $response->headers->set('Flyo-Draft', DraftMode::isDraft() ? '1' : '0');
        }

        // a response rendered from a draft link is never cached, not on the client and not on a
        // cdn, no matter what the ttl config says, see DraftMode
        if (DraftMode::isDraft()) {
            PreventDraftCaching::apply($response);

            return $response;
        }

        // turned off, the response keeps whatever cache headers the application gave it
        if (! $this->config->get('flyo.cache_headers', true)) {
            return $response;
        }

        if ($response->isSuccessful() && ! $isDebug && ! $isLiveEdit) {
            $cdnCacheControl = self::cdnCacheControl(
                $this->seconds('flyo.server_cache_ttl', 1200),
                $this->seconds('flyo.server_cache_stale_while_revalidate_ttl', 450),
            );

            $response->header('Vercel-CDN-Cache-Control', $cdnCacheControl);
            $response->header('CDN-Cache-Control', $cdnCacheControl);

            $clientCacheTtl = $this->seconds('flyo.client_cache_ttl', 900);
            if ($clientCacheTtl > 0) {
                $response->header('Cache-Control', 'max-age='.$clientCacheTtl);
            }
        }

        return $response;
    }

    /**
     * Builds the value of the cdn specific cache headers.
     *
     * With a stale window the edge keeps answering from its expired copy for that many seconds and
     * refreshes itself with a single background request, instead of sending every visitor waiting
     * on the url at the moment of the expiry through to the origin. A visitor can therefore be
     * served a page which is up to $ttl + $staleWhileRevalidateTtl seconds old, but only until that
     * background refresh finished. Pass 0 as the window to get plain `max-age=<ttl>` back.
     */
    public static function cdnCacheControl(int $ttl, int $staleWhileRevalidateTtl = 0): string
    {
        // this disables caching on Vercel and other CDNs but if client caching is enabled it will still apply
        if ($ttl <= 0) {
            return 'no-store';
        }

        $value = 'max-age='.$ttl;

        if ($staleWhileRevalidateTtl > 0) {
            $value .= ', stale-while-revalidate='.$staleWhileRevalidateTtl;
        }

        return $value;
    }

    /**
     * Reads a ttl config value, a non numeric or negative one counts as disabled.
     */
    protected function seconds(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
