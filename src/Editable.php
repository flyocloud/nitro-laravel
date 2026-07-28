<?php

namespace Flyo\Laravel;

use Flyo\Model\Block;
use InvalidArgumentException;

/**
 * Renders the `data-flyo-uid` marker which makes a block clickable inside the flyo live edit preview.
 *
 * In blade templates use the `@editable($block)` directive, which is a shortcut for [[attr()]]:
 *
 * ```blade
 * <div @editable($block)>...</div>
 * ```
 *
 * In raw php templates (or anywhere the directive is not available, e.g. when building markup in a
 * controller or a string) use the static helper:
 *
 * ```php
 * <section <?= Flyo\Laravel\Editable::attr($block); ?>>...</section>
 * ```
 *
 * The nitro js bridge itself is registered by [[ServiceProvider::boot()]] whenever live edit is
 * enabled, see [[LiveEdit]]. It is loaded by the `<x-flyo::head />` component, therefore the layout
 * must include that component in order to make the markers interactive.
 */
class Editable
{
    /**
     * The attribute the nitro js bridge is looking for.
     */
    public const ATTRIBUTE = 'data-flyo-uid';

    /**
     * Whether live edit is enabled or not, if disabled no marker is rendered at all.
     */
    public static function isEnabled(): bool
    {
        return (bool) app('config')->get('flyo.live_edit', false);
    }

    /**
     * The escaped `data-flyo-uid="..."` attribute for the given block, or an empty string when live
     * edit is disabled (which is the case in production).
     *
     * @param  mixed  $block  the flyo {@see Block} which should be editable.
     */
    public static function attr(mixed $block): string
    {
        if (! self::isEnabled()) {
            return '';
        }

        return self::ATTRIBUTE.'="'.htmlspecialchars(self::uid($block), ENT_QUOTES, 'UTF-8').'"';
    }

    /**
     * The uid of the given block, independent of whether live edit is enabled or not.
     *
     * @param  mixed  $block  the flyo {@see Block} which should be editable.
     *
     * @throws InvalidArgumentException if the given value is not a flyo block.
     */
    public static function uid(mixed $block): string
    {
        if (! self::isBlock($block)) {
            $actualType = is_object($block) ? get_class($block) : gettype($block);

            throw new InvalidArgumentException('The argument passed to @editable must be a Flyo Block object. Received: '.$actualType);
        }

        return (string) $block->getUid();
    }

    /**
     * Whether the given value can be treated as a flyo block or not.
     */
    private static function isBlock(mixed $block): bool
    {
        // Try instanceof first (preferred method)
        if ($block instanceof Block) {
            return true;
        }

        // Fallback: check exact class name (handles class loading issues)
        if (is_object($block) && get_class($block) === Block::class) {
            return true;
        }

        // Final fallback: duck typing (has required method)
        return is_object($block) && method_exists($block, 'getUid');
    }
}
