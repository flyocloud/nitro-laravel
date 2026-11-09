<?php

namespace Flyo\Laravel\Controllers;

use Flyo\Api\PagesApi;
use Flyo\Laravel\Components\Head;
use Flyo\Laravel\PageRoutes;
use Flyo\Model\Page;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Loads a CMS page from the api and renders the `cms` view with it.
 *
 * This is the action of the fallback route of the package, see PageRoutes. It expects the path of the
 * page in the request attribute `flyo.page`, which the EnsurePageExists middleware sets. Called
 * without the middleware the path is looked up here, an unknown path ends with a 404 either way.
 */
class PageController
{
    public function __invoke(Request $request, PagesApi $api, ViewFactory $views): View
    {
        $path = $request->attributes->get(PageRoutes::ATTRIBUTE) ?? PageRoutes::find($request);

        if ($path === null) {
            abort(404);
        }

        $page = $api->page($path, App::getLocale());

        App::instance(Page::class, $page);

        Head::metaPage($page);

        return $views->make('cms', ['page' => $page]);
    }
}
