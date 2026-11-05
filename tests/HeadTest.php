<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Model\Entity;
use Flyo\Model\EntityInterface;
use Flyo\Model\EntityMetric;
use Flyo\Model\Meta;
use Flyo\Model\MetaImage;
use Flyo\Model\Page;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

class HeadTest extends TestCase
{
    /**
     * Renders the component the way a layout does, through the blade compiler.
     */
    private function render(): string
    {
        return (string) $this->blade('<x-flyo::head />');
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

    public function test_a_non_indexable_page_renders_a_robots_noindex_tag(): void
    {
        Head::metaPage(new Page(['is_indexable' => 0]));

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->render());
    }

    public function test_an_indexable_page_does_not_render_a_robots_noindex_tag(): void
    {
        Head::metaPage(new Page(['is_indexable' => 1]));

        $this->assertStringNotContainsString('robots', $this->render());
    }

    public function test_the_noindex_flag_of_a_previous_page_is_not_kept(): void
    {
        Head::metaPage(new Page(['is_indexable' => 0]));
        Head::metaPage(new Page(['is_indexable' => 1]));

        $this->assertStringNotContainsString('robots', $this->render());
    }

    public function test_a_non_indexable_entity_renders_a_robots_noindex_tag(): void
    {
        Head::metaEntity(new Entity([
            'is_indexable' => false,
            'entity' => new EntityInterface([
                'entity_title' => 'A Title',
                'entity_teaser' => 'A Teaser',
                'entity_image' => 'https://example.com/an-image.jpg',
            ]),
        ]));

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $this->render());
    }

    public function test_the_json_ld_of_a_previous_page_is_not_kept(): void
    {
        Head::metaPage(new Page(['jsonld' => (object) ['@type' => 'WebPage']]));
        Head::metaPage(new Page([]));

        $this->assertSame([], Head::$jsonLd);
    }

    public function test_blade_syntax_in_the_meta_values_is_rendered_literally(): void
    {
        Head::metaTitle('Search: {{ 7*7 }}');
        Head::metaDescription('{!! 7*7 !!} @php echo 7*7; @endphp');
        Head::canonical('/{{ 7*7 }}');

        $html = $this->render();

        $this->assertStringContainsString('<title>Search: {{ 7*7 }}</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="{!! 7*7 !!} @php echo 7*7; @endphp">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/{{ 7*7 }}">', $html);
        $this->assertStringNotContainsString('49', $html);
    }

    public function test_blade_syntax_in_the_requested_url_is_rendered_literally(): void
    {
        Route::get('/head/{any}', fn () => Blade::render('<x-flyo::head />'));

        $html = $this->get('/head/{{7*7}}')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:url" content="http://localhost/head/{{7*7}}">', $html);
        $this->assertStringNotContainsString('49', $html);
    }

    public function test_blade_syntax_in_the_meta_values_of_an_entity_is_rendered_literally(): void
    {
        Head::metaEntity(new Entity([
            'entity' => new EntityInterface([
                'entity_title' => '{{ 7*7 }}',
                'entity_teaser' => '@{{ 7*7 }}',
                'entity_image' => 'https://example.com/{{ 7*7 }}.jpg',
            ]),
            'jsonld' => (object) ['name' => '@endverbatim {{ 7*7 }}'],
        ]));

        $html = $this->render();

        $this->assertStringContainsString('<title>{{ 7*7 }}</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="@{{ 7*7 }}">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/{{ 7*7 }}.jpg">', $html);
        $this->assertStringContainsString('<script type="application/ld+json">{"name":"@endverbatim {{ 7*7 }}"}</script>', $html);
        $this->assertStringNotContainsString('49', $html);
    }

    public function test_html_in_the_meta_values_is_escaped(): void
    {
        Head::metaTitle('Tom & "Jerry" </title><script>alert(1)</script>');
        Head::metaDescription('"><script>alert(1)</script>');
        Head::canonical('/a"><script>alert(1)</script>');
        config(['app.name' => 'Tom & Jerry "><script>alert(1)</script>']);

        $html = $this->render();

        $this->assertStringContainsString('<title>Tom &amp; &quot;Jerry&quot; &lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/a&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Tom &amp; Jerry &quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_an_already_escaped_meta_value_is_not_escaped_twice(): void
    {
        Head::metaTitle(e('Tom & Jerry'));

        $this->assertStringContainsString('<title>Tom &amp; Jerry</title>', $this->render());
    }

    public function test_the_json_ld_can_not_close_its_script_tag(): void
    {
        Head::jsonLd(['name' => '</script><script>alert(1)</script><!--']);

        $html = $this->render();

        $this->assertStringContainsString('{"name":"\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E\u003C!--"}', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<!--', $html);
    }

    public function test_the_metric_url_of_an_entity_can_not_break_out_of_its_script(): void
    {
        config(['app.env' => 'production']);

        Head::metaEntity(new Entity([
            'entity' => new EntityInterface([
                'entity_title' => 'A Title',
                'entity_teaser' => 'A Teaser',
                'entity_image' => 'https://example.com/an-image.jpg',
                'entity_metric' => new EntityMetric(['api' => "https://example.com/metric');alert('1</script>"]),
            ]),
        ]));

        $html = $this->render();

        $this->assertStringContainsString('fetch("https://example.com/metric\u0027);alert(\u00271\u003C/script\u003E")', $html);
        $this->assertStringNotContainsString("alert('1", $html);
    }
}
