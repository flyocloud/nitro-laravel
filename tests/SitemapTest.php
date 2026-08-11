<?php

namespace Flyo\Laravel\Tests;

use Flyo\Api\SitemapApi;
use Flyo\Laravel\Controllers\SitemapController;
use Flyo\Model\EntityinterfaceInner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SitemapTest extends TestCase
{
    /**
     * @param  array<int, EntityinterfaceInner>  $items
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
            new EntityinterfaceInner(['entity_type' => 'nitro-page', 'entity_slug' => 'about', 'href' => '/about']),
            new EntityinterfaceInner(['entity_type' => 'news', 'entity_slug' => 'a-news', 'href' => '/de/news/a-news']),
        ]);

        $this->assertStringContainsString('<url><loc>https://example.com/about</loc></url>', $xml);
        $this->assertStringContainsString('<url><loc>https://example.com/de/news/a-news</loc></url>', $xml);
    }

    public function test_the_updated_at_timestamp_is_rendered_as_lastmod(): void
    {
        $xml = $this->render([
            new EntityinterfaceInner(['href' => '/about', 'updated_at' => 1755000000]),
        ]);

        $this->assertStringContainsString('<loc>https://example.com/about</loc><lastmod>'.gmdate(DATE_W3C, 1755000000).'</lastmod>', $xml);
    }

    public function test_an_item_without_updated_at_is_rendered_without_lastmod(): void
    {
        $xml = $this->render([
            new EntityinterfaceInner(['href' => '/about']),
            new EntityinterfaceInner(['href' => '/contact', 'updated_at' => 0]),
        ]);

        $this->assertStringNotContainsString('lastmod', $xml);
    }

    public function test_items_without_a_resolved_href_are_skipped(): void
    {
        $xml = $this->render([
            new EntityinterfaceInner(['entity_slug' => 'not-routed']),
            new EntityinterfaceInner(['href' => '', 'entity_slug' => 'also-not-routed']),
        ]);

        $this->assertStringNotContainsString('<url>', $xml);
        $this->assertStringNotContainsString('not-routed', $xml);
    }

    public function test_the_same_location_is_only_listed_once(): void
    {
        $xml = $this->render([
            new EntityinterfaceInner(['href' => '/about', 'updated_at' => 1755000000]),
            new EntityinterfaceInner(['href' => '/about', 'updated_at' => 1755000001]),
        ]);

        $this->assertSame(1, substr_count($xml, '<loc>https://example.com/about</loc>'));
    }

    public function test_the_response_is_a_valid_xml_urlset(): void
    {
        $xml = $this->render([
            new EntityinterfaceInner(['href' => '/foo?a=1&b=2', 'updated_at' => 1755000000]),
        ]);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertStringEndsWith('</urlset>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/foo?a=1&amp;b=2</loc>', $xml);
        $this->assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($xml));
    }
}
