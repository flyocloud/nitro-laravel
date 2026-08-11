<?php

namespace Flyo\Laravel\Controllers;

use Flyo\Api\SitemapApi;
use Flyo\Model\EntityinterfaceInner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitemapController
{
    public function __construct(protected Response $response, protected Request $request, protected SitemapApi $api) {}

    private function buildUrl(string $path): string
    {
        return rtrim($this->request->root(), '/').'/'.ltrim($path, '/');
    }

    /**
     * The `updated_at` unix timestamp of a sitemap item as `lastmod` value, null when the api did
     * not deliver a timestamp for the item.
     */
    private function lastmod(EntityinterfaceInner $item): ?string
    {
        $updatedAt = (int) $item->getUpdatedAt();

        return $updatedAt > 0 ? gmdate(DATE_W3C, $updatedAt) : null;
    }

    public function render()
    {
        $locations = [];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($this->api->sitemap() as $item) {
            // the api resolves the url path of every page and entity into href, including the
            // locale prefix in multi lingual setups. It is empty when no route could be resolved
            // for the item, which means it is not reachable and must not show up in the sitemap.
            $href = $item->getHref();

            if (empty($href)) {
                continue;
            }

            $loc = $this->buildUrl($href);

            // the same location can be delivered more than once, for example when several entities
            // are mapped to one page
            if (in_array($loc, $locations, true)) {
                continue;
            }

            $locations[] = $loc;

            $xml .= '<url><loc>'.htmlspecialchars($loc, ENT_XML1, 'UTF-8').'</loc>';

            if ($lastmod = $this->lastmod($item)) {
                $xml .= '<lastmod>'.$lastmod.'</lastmod>';
            }

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        return $this->response
            ->setContent($xml)
            ->setStatusCode(200)
            ->header('Content-Type', 'text/xml');
    }
}
