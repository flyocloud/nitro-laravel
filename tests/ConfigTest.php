<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\LiveEdit;
use Flyo\Laravel\ServiceProvider;
use Illuminate\Support\ServiceProvider as SupportServiceProvider;

class ConfigTest extends TestCase
{
    public function test_the_published_config_contains_the_live_edit_keys(): void
    {
        $config = require __DIR__.'/../config/flyo.php';

        $this->assertArrayHasKey('live_edit', $config);
        $this->assertArrayHasKey('live_edit_bridge_url', $config);
        $this->assertSame(LiveEdit::BRIDGE_URL, $config['live_edit_bridge_url']);
    }

    public function test_the_config_and_the_cms_view_are_publishable(): void
    {
        $paths = SupportServiceProvider::pathsToPublish(ServiceProvider::class);

        $this->assertContains(realpath(__DIR__.'/../config/flyo.php'), array_map('realpath', array_keys($paths)));
        $this->assertContains(realpath(__DIR__.'/../resources/views/cms.blade.php'), array_map('realpath', array_keys($paths)));
    }
}
