<?php

namespace Flyo\Laravel\Tests;

use Flyo\Model\Block;

class BlockTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['APP_DEBUG'], $_ENV['APP_DEBUG']);

        parent::tearDown();
    }

    public function test_the_missing_view_message_renders_the_component_name_literally(): void
    {
        $_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = 'true';

        $html = (string) $this->blade('<x-flyo::block :block="$block" />', [
            'block' => new Block(['component' => '{{ 7*7 }}<b>']),
        ]);

        $this->assertStringContainsString('Component view not found: resources/views/flyo/{{ 7*7 }}&lt;b&gt;.blade.php', $html);
        $this->assertStringNotContainsString('49', $html);
    }

    public function test_a_missing_view_renders_nothing_without_debug_mode(): void
    {
        $html = (string) $this->blade('<x-flyo::block :block="$block" />', [
            'block' => new Block(['component' => 'missing']),
        ]);

        $this->assertSame('', trim($html));
    }
}
