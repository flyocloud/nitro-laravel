<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Editable;
use Flyo\Model\Block;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use stdClass;

class EditableTest extends TestCase
{
    public function test_attr_renders_the_marker_when_live_edit_is_enabled(): void
    {
        $this->assertSame('data-flyo-uid="block-uid"', Editable::attr($this->block()));
    }

    public function test_attr_renders_nothing_when_live_edit_is_disabled(): void
    {
        config(['flyo.live_edit' => false]);

        $this->assertFalse(Editable::isEnabled());
        $this->assertSame('', Editable::attr($this->block()));
    }

    public function test_attr_escapes_the_uid(): void
    {
        $this->assertSame(
            'data-flyo-uid="a&quot; onmouseover=&quot;alert(1)"',
            Editable::attr($this->block('a" onmouseover="alert(1)'))
        );
    }

    public function test_uid_is_returned_independent_of_the_live_edit_setting(): void
    {
        config(['flyo.live_edit' => false]);

        $this->assertSame('block-uid', Editable::uid($this->block()));
    }

    public function test_duck_typed_blocks_are_accepted(): void
    {
        $block = new class
        {
            public function getUid(): string
            {
                return 'duck-uid';
            }
        };

        $this->assertSame('data-flyo-uid="duck-uid"', Editable::attr($block));
    }

    /**
     * @param  mixed  $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBlockProvider')]
    public function test_invalid_blocks_throw($value, string $expectedType): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The argument passed to @editable must be a Flyo Block object. Received: '.$expectedType);

        Editable::attr($value);
    }

    public static function invalidBlockProvider(): array
    {
        return [
            'string' => ['not-a-block', 'string'],
            'null' => [null, 'NULL'],
            'array' => [[], 'array'],
            'object' => [new stdClass, stdClass::class],
        ];
    }

    public function test_blade_directive_renders_the_marker(): void
    {
        $this->assertSame(
            '<div data-flyo-uid="block-uid" class="block"></div>',
            Blade::render('<div @editable($block) class="block"></div>', ['block' => $this->block()])
        );
    }

    public function test_blade_directive_renders_nothing_when_live_edit_is_disabled(): void
    {
        config(['flyo.live_edit' => false]);

        $this->assertSame(
            '<div  class="block"></div>',
            Blade::render('<div @editable($block) class="block"></div>', ['block' => $this->block()])
        );
    }

    /**
     * The directive must also be registered in console contexts, otherwise `artisan view:cache`
     * would fail to compile templates using it. The whole test suite runs in console.
     */
    public function test_blade_directive_is_registered_in_console_contexts(): void
    {
        $this->assertTrue($this->app->runningInConsole());
        $this->assertArrayHasKey('editable', Blade::getCustomDirectives());
    }

    private function block(string $uid = 'block-uid'): Block
    {
        return new Block(['uid' => $uid]);
    }
}
