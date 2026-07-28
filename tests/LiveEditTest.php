<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Laravel\LiveEdit;

class LiveEditTest extends TestCase
{
    public function test_register_adds_the_boot_script(): void
    {
        LiveEdit::register();

        $this->assertCount(1, Head::$scripts);
        $this->assertStringContainsString(LiveEdit::BRIDGE_URL, Head::$scripts[0]);
        $this->assertTrue(LiveEdit::isRegistered());
    }

    public function test_register_only_once(): void
    {
        LiveEdit::register();
        LiveEdit::register('https://example.com/bridge.js');

        $this->assertCount(1, Head::$scripts);
        $this->assertStringNotContainsString('https://example.com/bridge.js', Head::$scripts[0]);
    }

    public function test_boot_uses_the_configured_bridge_url(): void
    {
        config(['flyo.live_edit_bridge_url' => 'https://example.com/self-hosted-bridge.js']);

        LiveEdit::boot($this->app['config']);

        $this->assertStringContainsString('var BRIDGE_URL = "https://example.com/self-hosted-bridge.js";', Head::$scripts[0]);
    }

    public function test_boot_falls_back_to_the_cdn_bridge_url(): void
    {
        LiveEdit::boot($this->app['config']);

        $this->assertStringContainsString('var BRIDGE_URL = "'.LiveEdit::BRIDGE_URL.'";', Head::$scripts[0]);
    }

    public function test_boot_registers_nothing_when_live_edit_is_disabled(): void
    {
        config(['flyo.live_edit' => false]);

        LiveEdit::boot($this->app['config']);

        $this->assertSame([], Head::$scripts);
        $this->assertFalse(LiveEdit::isRegistered());
    }

    public function test_the_bridge_url_is_pinned_to_the_major_version(): void
    {
        $this->assertSame(
            'https://unpkg.com/@flyo/nitro-js-bridge@1/dist/nitro-js-bridge.umd.cjs',
            LiveEdit::BRIDGE_URL
        );
    }

    public function test_the_boot_script_wires_every_bridge_feature(): void
    {
        LiveEdit::register();
        $js = Head::$scripts[0];

        // page refresh + editor connection handshake (bridge >= 1.4.0)
        $this->assertStringContainsString('bridge.reload()', $js);
        // scroll the preview to the block selected in the editor
        $this->assertStringContainsString('bridge.scrollTo()', $js);
        // click to edit hover overlay (bridge >= 1.5.0)
        $this->assertStringContainsString('bridge.highlightAndClick(uid, el)', $js);
        $this->assertStringContainsString('[data-flyo-uid]', $js);
    }

    public function test_the_boot_script_boots_only_once_per_document(): void
    {
        LiveEdit::register();
        $js = Head::$scripts[0];

        // registering the message listeners twice would handle every editor message twice
        $this->assertStringContainsString('if (window.__flyoLiveEditBooted) { return; }', $js);
        $this->assertStringContainsString('window.__flyoLiveEditBooted = true;', $js);
    }

    public function test_the_boot_script_waits_for_the_bridge_and_the_dom(): void
    {
        LiveEdit::register();
        $js = Head::$scripts[0];

        // the blocks are wired when the bridge is loaded and the dom is ready, never before
        $this->assertStringContainsString('script.onload = onBridgeLoaded;', $js);
        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded', callback, { once: true });", $js);
        $this->assertStringContainsString('script.onerror', $js);
    }

    public function test_the_boot_script_can_not_break_out_of_the_script_tag(): void
    {
        LiveEdit::register('https://example.com/bridge.js?x=</script><script>alert(1)</script>');

        $this->assertStringNotContainsString('</script>', Head::$scripts[0]);
    }

    public function test_the_boot_script_is_rendered_by_the_head_component(): void
    {
        LiveEdit::register();

        $html = (new Head($this->app['view']))->render();

        $this->assertStringContainsString('<script>', $html);
        $this->assertStringContainsString(LiveEdit::BRIDGE_URL, $html);
    }
}
