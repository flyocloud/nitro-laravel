# Upgrade

## 2.1 → 2.2

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are required in a project. Everything below is either new or an internal rework.

### What's new

1. **The live edit boot was rewritten and moved into `Flyo\Laravel\LiveEdit`.** The inline javascript which used to live in `ServiceProvider::boot()` is gone. The new class owns the bridge url (`LiveEdit::BRIDGE_URL`) and the boot script, mirroring `Flyo\Yii\LiveEdit` of the yii2 integration so both stay in sync. Behavior fixes it brings:
   - `reload()` and `scrollTo()` are no longer skipped when `highlightAndClick()` is missing from the bridge, they are wired as soon as the bridge is loaded.
   - The boot can no longer run twice (`window.__flyoLiveEditBooted`). Previously the script could boot once on the script's `onload` and a second time on `DOMContentLoaded`, which registered the `pageRefresh`, `scrollTo` and handshake message listeners twice, so every editor message was handled twice.
   - Blocks are wired when the bridge is loaded **and** the dom is ready, never on a half-parsed document.
   - A failing bridge download now logs a console warning instead of silently doing nothing.

2. **`Flyo\Laravel\Editable` for raw php templates.** The `@editable($block)` blade directive is a shortcut for `Editable::attr($block)`, which can now also be used outside of blade, e.g. in plain php views, in a controller or when building markup as a string:

   ```php
   <section <?= Flyo\Laravel\Editable::attr($block); ?>>...</section>
   ```

   `Editable::attr()` returns the escaped `data-flyo-uid="…"` attribute, or an empty string when live edit is disabled. `Editable::uid($block)` returns the raw uid and `Editable::isEnabled()` the live edit state. The nitro js bridge is still booted by the service provider and loaded by the `<x-flyo::head />` component, so the layout has to include that component — `Editable::attr()` only renders the marker.

3. **`@editable` is registered in console contexts as well.** It used to be registered inside the `runningInConsole()` guard, so `php artisan view:cache` (and anything else compiling blade templates from the console) failed on templates using the directive. This mirrors the earlier fix which registers the flyo blade components in console contexts.

4. **The bridge url is configurable.** New optional config key, use it to self host the bridge or to pin an exact version:

   ```php
   'live_edit_bridge_url' => env('FLYO_LIVE_EDIT_BRIDGE_URL', 'https://unpkg.com/@flyo/nitro-js-bridge@1.5.0/dist/nitro-js-bridge.umd.cjs'),
   ```

   An already published `config/flyo.php` does **not** have to be updated, the CDN url pinned to the major version is used when the key is absent.

5. **Tests and CI.** The package now ships a phpunit suite (`composer phpunit`, based on `orchestra/testbench`) and a github action running pint, phpstan and phpunit on php 8.3, 8.4 and 8.5. The composer constraint still allows laravel 11, but the CI only covers laravel 12: every laravel 11 release is flagged by composer's security audit by now, so composer refuses to install it unless the advisories are ignored explicitly.

### Behavior notes

- **The nitro js bridge 1.5.0 is picked up automatically**, because the url is pinned to the major version on the CDN. Its live edit hover affordance was rebuilt: hovering an editable block now fades in a highlight ring plus the pencil button, both drawn in a single `<flyo-edit-overlay>` element mounted on `<html>`. It never touches your markup, your css or your layout, and it can not be styled from your site's css anymore (the old pencil was a plain `<button>` in `<body>`). The most visible difference for an editor: **the pencil appears after roughly 0.6s of hovering** instead of instantly, which stops it flickering while the mouse crosses the page.
- **The rendered marker lost its surrounding spaces.** `@editable($block)` used to echo `' data-flyo-uid="uid" '`, it now echoes `data-flyo-uid="uid"`. The templates in the readme (`<div @editable($block) class="…">`) are unaffected, only html snapshot tests could notice.
- **The `Flyo-Live-Edit` debug response header** sends the string `'1'`/`'0'` instead of the integer `1`/`0`. Identical over the wire.
