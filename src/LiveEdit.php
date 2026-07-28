<?php

namespace Flyo\Laravel;

use Flyo\Laravel\Components\Head;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Registers the nitro js bridge and boots the live edit integration for the current page.
 *
 * This is wired automatically by [[ServiceProvider::boot()]] whenever `flyo.live_edit` is enabled.
 * Blocks which should be clickable inside the flyo preview only have to render the `data-flyo-uid`
 * attribute, which is what the `@editable($block)` blade directive does.
 *
 * The bridge is loaded from the CDN as a UMD build, therefore new bridge releases (e.g. the
 * reworked hover overlay of 1.5.0) are picked up automatically once the CDN cache refreshes.
 */
class LiveEdit
{
    /**
     * The UMD build of the nitro js bridge which is registered when live edit is enabled.
     *
     * Pinned to the major version so patch and minor releases are picked up automatically,
     * can be overwritten with the `flyo.live_edit_bridge_url` config in order to self host
     * the bridge or to pin an exact version.
     */
    public const BRIDGE_URL = 'https://unpkg.com/@flyo/nitro-js-bridge@1/dist/nitro-js-bridge.umd.cjs';

    /**
     * The flag the boot script sets on the window, it also marks the script inside the head scripts.
     */
    private const BOOT_MARKER = '__flyoLiveEditBooted';

    /**
     * Register the boot script if live edit is enabled, honoring the `flyo.live_edit_bridge_url`
     * config. This is what [[ServiceProvider::boot()]] calls.
     */
    public static function boot(ConfigRepository $config): void
    {
        if (! $config->get('flyo.live_edit', false)) {
            return;
        }

        self::register($config->get('flyo.live_edit_bridge_url') ?: self::BRIDGE_URL);
    }

    /**
     * Register the inline boot script which loads the bridge and wires the live edit features.
     *
     * Registering more than once is a no-op, the bridge must be booted exactly once per document.
     */
    public static function register(string $bridgeUrl = self::BRIDGE_URL): void
    {
        if (self::isRegistered()) {
            return;
        }

        Head::script(self::bootJs($bridgeUrl));
    }

    /**
     * Whether the boot script has already been added to the head scripts or not.
     */
    public static function isRegistered(): bool
    {
        foreach (Head::$scripts as $script) {
            if (str_contains($script, self::BOOT_MARKER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The boot script which loads the bridge from the CDN and wires all live edit features:
     *
     * - `reload()`: page refresh messages from the editor and (bridge >= 1.4.0) the editor
     *   connection handshake, which lets the editor tell a working preview apart from a
     *   blocked frame instead of showing a silent white screen.
     * - `scrollTo()`: scroll the preview to the block which is selected in the editor.
     * - `highlightAndClick()`: the hover affordance (highlight ring + pencil button) which
     *   opens a block in the editor, (bridge >= 1.5.0) rendered in a single shared overlay
     *   outside of the page so it can not interfere with the website.
     */
    private static function bootJs(string $bridgeUrl): string
    {
        // slashes stay readable, but everything which could break out of the <script> tag is escaped
        $url = json_encode($bridgeUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JS
(function(){
  var BRIDGE_URL = {$url};

  // The head component could be rendered more than once, boot only the first time.
  if (window.__flyoLiveEditBooted) { return; }
  window.__flyoLiveEditBooted = true;

  // Message listeners of the bridge must be registered exactly once, registering them
  // twice would handle every editor message twice.
  function bootBridge(bridge){
    if (typeof bridge.reload === 'function') { bridge.reload(); }
    if (typeof bridge.scrollTo === 'function') { bridge.scrollTo(); }
  }

  // Registering the same element again is a no-op in the bridge (one entry per element),
  // so this is safe to run even if the dom was already wired.
  function wireBlocks(bridge){
    if (typeof bridge.highlightAndClick !== 'function') { return; }
    var nodes = document.querySelectorAll('[data-flyo-uid]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var uid = el.getAttribute('data-flyo-uid');
      if (uid) { bridge.highlightAndClick(uid, el); }
    }
  }

  function whenReady(callback){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
      callback();
    }
  }

  function onBridgeLoaded(){
    var bridge = window.nitroJsBridge;
    if (!bridge) { return; }
    bootBridge(bridge);
    whenReady(function(){ wireBlocks(bridge); });
  }

  var script = document.createElement('script');
  script.src = BRIDGE_URL;
  script.async = true;
  script.onload = onBridgeLoaded;
  script.onerror = function(){
    if (window.console && console.warn) {
      console.warn('[flyo] live edit is enabled but the nitro js bridge could not be loaded from ' + BRIDGE_URL);
    }
  };
  document.head.appendChild(script);
})();
JS;
    }
}
