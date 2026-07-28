# Upgrade

## 2.1 → 2.2

**No breaking changes.** `composer update flyo/nitro-laravel` is enough, no code changes are required in a project.

### What's new

1. **Blocks can be marked as editable in raw php templates.** In blade the `@editable($block)` directive stays the way to do it, everywhere else (plain php views, a controller, markup built as a string) use `Flyo\Laravel\Editable`:

   ```blade
   {{-- blade --}}
   <div @editable($block)>...</div>
   ```

   ```php
   // raw php
   <section <?= Flyo\Laravel\Editable::attr($block); ?>>...</section>
   ```

   Both render the same `data-flyo-uid` marker, escaped, and nothing at all when live edit is disabled. `Editable::uid($block)` returns the raw uid and `Editable::isEnabled()` the live edit state. The javascript which makes the marker interactive is loaded by the `<x-flyo::head />` component, so the layout has to include it.

2. **`@editable` can be used in templates compiled from the console.** `php artisan view:cache` failed on templates using the directive before.

3. **The bridge url is configurable.** New optional config key, use it to self host the nitro js bridge or to pin an exact version:

   ```php
   'live_edit_bridge_url' => env('FLYO_LIVE_EDIT_BRIDGE_URL', 'https://unpkg.com/@flyo/nitro-js-bridge@1.5.0/dist/nitro-js-bridge.umd.cjs'),
   ```

   An already published `config/flyo.php` does **not** have to be updated, the CDN url pinned to the major version is used when the key is absent.

4. **Editor messages are no longer handled twice.** Depending on the load order, page refresh and scroll-to-block could be registered twice, so a single message from the editor was handled twice. Page refresh and scroll-to-block also work now when the click-to-edit overlay is unavailable.

### Behavior notes

- **The nitro js bridge 1.5.0 is picked up automatically**, because the url is pinned to the major version on the CDN. Its live edit hover affordance was rebuilt: hovering an editable block fades in a highlight ring plus the pencil button, drawn in a single element outside of your markup, so it can not touch your css or your layout. The most visible difference for an editor: **the pencil appears after roughly 0.6s of hovering** instead of instantly, which stops it flickering while the mouse crosses the page. It also can not be styled from your site's css anymore.
- **The rendered marker lost its surrounding spaces.** `@editable($block)` used to echo `' data-flyo-uid="uid" '`, it now echoes `data-flyo-uid="uid"`. Templates like `<div @editable($block) class="…">` are unaffected, only html snapshot tests could notice.
- **The `Flyo-Live-Edit` debug response header** sends the string `'1'`/`'0'` instead of the integer `1`/`0`. Identical over the wire.
