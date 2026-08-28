<?php

namespace Flyo\Laravel\Tests;

use Flyo\Api\SitemapApi;
use Flyo\Laravel\Controllers\SitemapController;
use Flyo\Model\SitemapinterfaceInner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The sitemap endpoint delivers its own model since flyo/nitro-php 3.0, it carries the resolved
 * url, the timestamp of the last content change and the unique id of the entity, nothing of the
 * presentation data an entity of the search or the entities endpoint has.
 */
class SitemapTest extends TestCase
{
    /**
     * @param  array<int, SitemapinterfaceInner>  $items
     */
    private function render(array $items): string
    {
        $api = $this->createStub(SitemapApi::class);
        $api->method('sitemap')->willReturn($items);

        $controller = new SitemapController(new Response, Request::create('https://example.com/sitemap.xml'), $api);

        return (string) $controller->render()->getContent();
    }

    public function test_the_href_of_an_item_is_used_as_absolute_loc(): void
    {
        $xml = $this->render([
            new SitemapinterfaceInner(['entity_type' => 'nitro-page', 'entity_slug' => 'about', 'href' => '/about']),
            new SitemapinterfaceInner(['entity_type' => 'news', 'entity_slug' => 'a-news', 'href' => '/de/news/a-news']),
        ]);

        $this->assertStringContainsString('<url><loc>https://example.com/about</loc></url>', $xml);
        $this->assertStringContainsString('<url><loc>https://example.com/de/news/a-news</loc></url>', $xml);
    }

    public function test_the_updated_at_timestamp_is_rendered_as_lastmod(): void
    {
        $xml = $this->render([
            new SitemapinterfaceInner(['href' => '/about', 'updated_at' => 1755000000]),
        ]);

        $this->assertStringContainsString('<loc>https://example.com/about</loc><lastmod>'.gmdate(DATE_W3C, 1755000000).'</lastmod>', $xml);
    }

    public function test_an_item_without_updated_at_is_rendered_without_lastmod(): void
    {
        $xml = $this->render([
            new SitemapinterfaceInner(['href' => '/about']),
            new SitemapinterfaceInner(['href' => '/contact', 'updated_at' => 0]),
        ]);

        $this->assertStringNotContainsString('lastmod', $xml);
    }

    public function test_items_without_a_resolved_href_are_skipped(): void
    {
        $xml = $this->render([
            new SitemapinterfaceInner(['entity_slug' => 'not-routed']),
            new SitemapinterfaceInner(['href' => '', 'entity_slug' => 'also-not-routed']),
        ]);

        $this->assertStringNotContainsString('<url>', $xml);
        $this->assertStringNotContainsString('not-routed', $xml);
    }

    public function test_the_same_location_is_only_listed_once(): void
    {
        $xml = $this->render([
            new SitemapinterfaceInner(['href' => '/about', 'updated_at' => 1755000000]),
            new SitemapinterfaceInner(['href' => '/about', 'updated_at' => 1755000001]),
        ]);

        $this->assertSame(1, substr_count($xml, '<loc>https://example.com/about</loc>'));
    }

    public function test_the_response_is_a_valid_xml_urlset(): void
    {
        $xml = $this->render([
            new SitemapinterfaceInner(['href' => '/foo?a=1&b=2', 'updated_at' => 1755000000]),
        ]);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertStringEndsWith('</urlset>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/foo?a=1&amp;b=2</loc>', $xml);
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));
    }
}
