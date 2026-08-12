<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Model\Meta;
use Flyo\Model\MetaImage;
use Flyo\Model\Page;

class HeadTest extends TestCase
{
    private function render(): string
    {
        return app(Head::class)->render();
    }

    public function test_the_meta_informations_of_a_page_are_assigned(): void
    {
        Head::metaPage(new Page([
            'meta_json' => new Meta([
                'title' => 'The page title',
                'description' => 'The page description',
            ]),
        ]));

        $this->assertSame('The page title', Head::$metas['title']);
        $this->assertSame('The page description', Head::$metas['description']);

        $html = $this->render();

        $this->assertStringContainsString('<title>The page title</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="The page description">', $html);
    }

    public function test_the_json_ld_of_a_page_is_assigned(): void
    {
        $jsonLd = (object) ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'About'];

        Head::metaPage(new Page(['jsonld' => $jsonLd]));

        $this->assertSame((array) $jsonLd, Head::$jsonLd);

        $html = $this->render();

        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('{"@context":"https:\/\/schema.org","@type":"WebPage","name":"About"}', $html);
    }

    public function test_a_page_without_json_ld_does_not_render_a_ld_json_script(): void
    {
        Head::metaPage(new Page(['meta_json' => new Meta(['title' => 'No schema'])]));

        $this->assertSame([], Head::$jsonLd);
        $this->assertStringNotContainsString('application/ld+json', $this->render());
    }

    public function test_a_page_response_without_meta_informations_is_handled(): void
    {
        Head::metaPage(new Page([]));

        $this->assertSame([], Head::$metas);
        $this->assertSame([], Head::$jsonLd);
    }

    public function test_empty_meta_informations_are_not_assigned(): void
    {
        Head::metaPage(new Page([
            'meta_json' => new Meta(['title' => '', 'description' => '']),
        ]));

        $this->assertSame([], Head::$metas);
    }

    public function test_a_meta_image_without_a_usable_url_is_not_assigned(): void
    {
        // the php sdk types meta_json.image as a model without properties, an api response with an
        // image must not end up as the json encoded model in the head
        Head::metaPage(new Page([
            'meta_json' => new Meta(['title' => 'The page title', 'image' => new MetaImage([])]),
        ]));

        $this->assertArrayNotHasKey('image', Head::$metas);
        $this->assertStringNotContainsString('og:image', $this->render());
    }

    public function test_the_json_ld_of_a_previous_page_is_not_kept(): void
    {
        Head::metaPage(new Page(['jsonld' => (object) ['@type' => 'WebPage']]));
        Head::metaPage(new Page([]));

        $this->assertSame([], Head::$jsonLd);
    }
}
