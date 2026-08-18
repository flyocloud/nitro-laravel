<?php

namespace Flyo\Laravel\Tests;

use Flyo\ObjectSerializer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The route map of an entity is delivered as a free form object, the api adds the system key
 * `_empty` to it when no route could be resolved. Both the detail entity and the items of a
 * list (search, sitemap, blocks) have to deserialize into a plain array, otherwise the
 * resolved routes never reach userland: while the openapi spec declared `_empty` as a
 * property of the list item map, the generator turned that map into a model class knowing
 * only `_empty` and every real route key was dropped (flyo/nitro-php 2.2).
 */
class EntityRoutesTest extends TestCase
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function entityClassProvider(): array
    {
        return [
            'detail entity' => ['\Flyo\Model\EntityInterface'],
            'list item' => ['\Flyo\Model\EntityinterfaceInner'],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('entityClassProvider')]
    public function test_resolved_routes_are_readable_as_an_array(string $class): void
    {
        $entity = ObjectSerializer::deserialize(
            json_decode('{"entity_slug":"a-slug","routes":{"detail":"/a-section/a-slug"}}'),
            $class
        );

        $this->assertSame(['detail' => '/a-section/a-slug'], $entity->getRoutes());
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('entityClassProvider')]
    public function test_the_empty_marker_of_an_unresolved_route_map_stays_a_boolean(string $class): void
    {
        $entity = ObjectSerializer::deserialize(
            json_decode('{"entity_slug":"a-slug","routes":{"_empty":true}}'),
            $class
        );

        $this->assertSame(['_empty' => true], $entity->getRoutes());
    }
}
