<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\DraftMode;
use Flyo\Laravel\Middleware\CachingHeaders;
use Flyo\Laravel\Middleware\PreventDraftCaching;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A response rendered from a draft link must not be stored by anything: the snapshot changes with
 * every save of the editor and the link answers with a 404 once it expired, so a cached copy would
 * serve content which is outdated or gone. The expiration timestamp of the draft is deliberately
 * not used as a cache ttl.
 */
class DraftCachingTest extends TestCase
{
    private function config(array $flyo = []): Repository
    {
        return new Repository(['flyo' => $flyo + [
            'live_edit' => false,
            'server_cache_ttl' => 900,
            'server_cache_stale_while_revalidate_ttl' => 450,
            'client_cache_ttl' => 1200,
        ]]);
    }

    private function cachingHeaders(array $flyo = []): Response
    {
        $middleware = new CachingHeaders($this->config($flyo));

        return $middleware->handle(Request::create('/a-draft-token'), fn () => new Response('a draft'));
    }

    public function test_the_caching_middleware_caches_a_regular_response(): void
    {
        $response = $this->cachingHeaders();

        // the stale window lets the edge answer from its expired copy while a single background
        // request refreshes it, instead of sending every waiting visitor to the origin
        $this->assertSame('max-age=900, stale-while-revalidate=450', $response->headers->get('CDN-Cache-Control'));
        $this->assertSame('max-age=900, stale-while-revalidate=450', $response->headers->get('Vercel-CDN-Cache-Control'));
        $this->assertStringContainsString('max-age=1200', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_stale_window_can_be_turned_off(): void
    {
        $response = $this->cachingHeaders(['server_cache_stale_while_revalidate_ttl' => 0]);

        $this->assertSame('max-age=900', $response->headers->get('CDN-Cache-Control'));
        $this->assertSame('max-age=900', $response->headers->get('Vercel-CDN-Cache-Control'));
    }

    public function test_a_non_numeric_or_negative_stale_window_is_treated_as_off(): void
    {
        $this->assertSame('max-age=900', $this->cachingHeaders(['server_cache_stale_while_revalidate_ttl' => null])->headers->get('CDN-Cache-Control'));
        $this->assertSame('max-age=900', $this->cachingHeaders(['server_cache_stale_while_revalidate_ttl' => -10])->headers->get('CDN-Cache-Control'));
    }

    public function test_a_disabled_server_cache_never_gets_a_stale_window(): void
    {
        // no-store has no business carrying a revalidation window
        $response = $this->cachingHeaders(['server_cache_ttl' => 0]);

        $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'));
        $this->assertSame('no-store', $response->headers->get('Vercel-CDN-Cache-Control'));
    }

    public function test_the_cdn_cache_control_value_is_built_from_the_two_ttls(): void
    {
        $this->assertSame('max-age=1800, stale-while-revalidate=900', CachingHeaders::cdnCacheControl(1800, 900));
        $this->assertSame('max-age=1800', CachingHeaders::cdnCacheControl(1800));
        $this->assertSame('max-age=1800', CachingHeaders::cdnCacheControl(1800, 0));
        $this->assertSame('no-store', CachingHeaders::cdnCacheControl(0, 900));
    }

    public function test_the_caching_middleware_does_not_cache_a_draft_response(): void
    {
        DraftMode::flag(1755000000);

        $response = $this->cachingHeaders();

        $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'));
        $this->assertSame('no-store', $response->headers->get('Vercel-CDN-Cache-Control'));
        $this->assertSame('no-store', $response->headers->get('Surrogate-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age=1200', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_draft_response_is_not_cached_even_when_a_ttl_is_configured_and_live_edit_is_off(): void
    {
        DraftMode::flag();

        $response = $this->cachingHeaders(['server_cache_ttl' => 3600, 'client_cache_ttl' => 3600]);

        $this->assertStringNotContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'));
        $this->assertStringNotContainsString('stale-while-revalidate', (string) $response->headers->get('CDN-Cache-Control'));
    }

    public function test_the_middleware_registers_itself_globally(): void
    {
        PreventDraftCaching::register($this->app);

        $kernel = $this->app->make(HttpKernelContract::class);

        $this->assertTrue($kernel->hasMiddleware(PreventDraftCaching::class));
        $this->assertSame(PreventDraftCaching::class, $kernel->getGlobalMiddleware()[0]);
    }

    public function test_the_global_middleware_drops_the_draft_state_of_a_previous_request(): void
    {
        // in a long running worker the static state of the previous response is still around
        DraftMode::flag(1755000000);

        $middleware = new PreventDraftCaching;

        $response = $middleware->handle(Request::create('/about'), function () {
            $this->assertFalse(DraftMode::isDraft());

            return new Response('a page');
        });

        $this->assertFalse(DraftMode::isDraft());
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_the_global_middleware_leaves_a_regular_response_untouched(): void
    {
        $middleware = new PreventDraftCaching;

        $response = $middleware->handle(Request::create('/about'), function () {
            $response = new Response('a page');
            $response->headers->set('CDN-Cache-Control', 'max-age=900');

            return $response;
        });

        $this->assertSame('max-age=900', $response->headers->get('CDN-Cache-Control'));
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    public function test_the_global_middleware_overwrites_the_cache_headers_of_a_draft_response(): void
    {
        $middleware = new PreventDraftCaching;

        // the route resolved a draft entity and a caching middleware of the application ran inside
        // of it and wanted the response cached
        $response = $middleware->handle(Request::create('/a-draft-token'), function () {
            DraftMode::flag(1755000000);

            $response = new Response('a draft');
            $response->headers->set('Cache-Control', 'max-age=1200');
            $response->headers->set('CDN-Cache-Control', 'max-age=900');
            $response->setEtag('a-draft');
            $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s').' GMT');

            return $response;
        });

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString('max-age=1200', $cacheControl);
        $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'));
        $this->assertSame('no-store', $response->headers->get('Vercel-CDN-Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));

        // nothing a cache could revalidate against stays on a draft response
        $this->assertNull($response->headers->get('ETag'));
        $this->assertNull($response->headers->get('Last-Modified'));

        // a draft is a preview of content which is offline in flyo
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }
}
