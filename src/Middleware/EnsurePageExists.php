<?php

namespace Flyo\Laravel\Middleware;

use Closure;
use Flyo\Laravel\PageRoutes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through to the CMS page only when its path is one of the pages of the Flyo config,
 * every other request ends with a 404.
 *
 * It is the first middleware of the fallback route, listed in front of the `web` group (see
 * PageRoutes::fallback()). Laravel sorts the middleware of a route by its priority list, but only
 * moves the middleware which are on that list, and this one is not. It therefore stays in front of
 * everything: an unknown url answers with a 404 before a session is started, before `auth` could
 * redirect a guest to the login and before `throttle` counts the request, the same as when every
 * page had a route of its own.
 *
 * The path of the page, as the config delivers it, is stored in the request attribute `flyo.page`.
 */
class EnsurePageExists
{
    public function handle(Request $request, Closure $next): Response
    {
        $page = PageRoutes::find($request);

        if ($page === null) {
            abort(404);
        }

        $request->attributes->set(PageRoutes::ATTRIBUTE, $page);

        return $next($request);
    }
}
