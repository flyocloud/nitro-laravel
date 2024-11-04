<?php

namespace Flyo\Laravel\Controllers;

use Flyo\Api\SitemapApi;
use Flyo\Configuration;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitemapController
{
    public function __construct(protected Repository $repository, protected Response $response, protected Request $request, protected Configuration $config) {}

    private function buildUrl($path)
    {
        return rtrim($this->request->root(), '/').'/'.ltrim($path, '/');
    }

    public function render()
    {
        $detailRoute = $this->repository->get('flyo.default_route', 'detail');
        $api = new SitemapApi(null, $this->config);
        $routes = [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($api->sitemap() as $item) {

            if ($item->getEntityType() == 'nitro-page') {
                if (in_array($item->getEntitySlug(), $routes)) {
                    continue;
                }
                $routes[] = $item->getEntitySlug();
                $xml .= '<url><loc>'.$this->buildUrl($item->getEntitySlug()).'</loc></url>';
            } elseif (isset($item->getRoutes()[$detailRoute])) {
                $xml .= '<url><loc>'.$this->buildUrl($item->getRoutes()[$detailRoute]).'</loc></url>';
            }
        }

        $xml .= '</urlset>';

        return $this->response
            ->setContent($xml)
            ->setStatusCode(200)
            ->header('Content-Type', 'text/xml');
    }
}
