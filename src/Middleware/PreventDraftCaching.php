<?php

namespace Flyo\Laravel\Middleware;

use Closure;
use Flyo\Laravel\DraftMode;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a response rendered from a draft link uncacheable.
 *
 * A draft link is an expiring snapshot of an entity which is still offline in Flyo, see DraftMode.
 * Its content is rewritten with every save of the editor and the link answers with a 404 once it
 * expired, therefore such a response must not be stored anywhere: not by the browser, not by a cdn
 * and not by a server side cache. The api does deliver the expiration timestamp, but relying on it
 * to invalidate a cache entry is fragile, so nothing is cached at all.
 *
 * The package registers this middleware globally, so a draft is protected no matter which route
 * rendered it, also when the route does not use the CachingHeaders middleware.
 */
class PreventDraftCaching
{
    /**
     * Prepends the middleware to the global middleware stack of the http kernel, so it is the
     * outermost one and its no store headers can not be overwritten by a middleware of the
     * application. Until an entity resolved as a draft it does nothing at all.
     */
    public static function register(Container $app): void
    {
        $kernel = $app->bound(HttpKernelContract::class) ? $app->make(HttpKernelContract::class) : null;

        // the global middleware stack belongs to the http kernel of the framework, an application
        // running on a kernel of its own is left alone
        if (! $kernel instanceof HttpKernel) {
            return;
        }

        $kernel->prependMiddleware(self::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // the state belongs to a single response, it has to be dropped in a long running worker
        // (octane) where the static state of the previous request is still around. This middleware
        // is the outermost one, so the reset happens before anything resolved an entity.
        DraftMode::reset();

        /** @var Response $response */
        $response = $next($request);

        if (DraftMode::isDraft()) {
            self::apply($response);
        }

        return $response;
    }

    /**
     * Writes the no store headers of a draft response, dropping the validators a cache could
     * revalidate against.
     */
    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        // the cdn specific headers take precedence over Cache-Control on the edge, they have to
        // say no store as well, see CachingHeaders for the cached counterpart
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Vercel-CDN-Cache-Control', 'no-store');
        $response->headers->set('Surrogate-Control', 'no-store');

        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');

        // a draft is a preview of content which is offline in flyo, it has no business in an index
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
