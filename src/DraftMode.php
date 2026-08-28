<?php

namespace Flyo\Laravel;

use Flyo\Model\Entity;

/**
 * The draft link state of the response which is currently rendered.
 *
 * A draft link is a shareable, expiring snapshot of an entity which is still offline in Flyo,
 * requested through the regular entity endpoints (`entityBySlug()`, `entityByUniqueid()`) with a
 * token in place of the slug or the unique id. The api marks such a response with `is_draft`.
 *
 * A draft response must never be cached, neither by the client nor by a cdn or another server side
 * cache: the snapshot is rewritten with every save of the editor and the link stops working at
 * `draft_expires_at`, so a cached copy would keep serving content which is outdated or gone. The
 * PreventDraftCaching middleware turns this state into the response headers, the package registers
 * it as a global middleware so it also covers routes which do not use the CachingHeaders middleware.
 *
 * EntityController flags a draft on its own, a custom controller resolving an entity should do the
 * same before the response leaves the application:
 *
 * ```php
 * $entity = $api->entityBySlug($slugOrDraftToken);
 * Flyo\Laravel\DraftMode::detect($entity);
 * ```
 *
 * `Head::metaEntity()` calls it as well, so an entity page assigning its meta data through the head
 * component is covered without an additional call.
 */
class DraftMode
{
    private static bool $isDraft = false;

    private static ?int $expiresAt = null;

    /**
     * Flags the response as a draft when the entity was delivered through a draft link, returns
     * whether it was. Safe to call more than once, a flagged draft is never unflagged: a single
     * draft entity on the page is enough to make the whole response uncacheable.
     */
    public static function detect(Entity $entity): bool
    {
        if (! $entity->getIsDraft()) {
            return false;
        }

        $expiresAt = $entity->getDraftExpiresAt();

        self::flag($expiresAt === null ? null : (int) $expiresAt);

        return true;
    }

    /**
     * Flags the response as a draft, `$expiresAt` is the unix timestamp at which the draft link
     * stops working, null when the api did not deliver one.
     */
    public static function flag(?int $expiresAt = null): void
    {
        self::$isDraft = true;

        if ($expiresAt !== null) {
            self::$expiresAt = $expiresAt;
        }
    }

    public static function isDraft(): bool
    {
        return self::$isDraft;
    }

    /**
     * The unix timestamp at which the draft link stops working, null when the response is not a
     * draft or the api did not deliver a timestamp.
     */
    public static function expiresAt(): ?int
    {
        return self::$isDraft ? self::$expiresAt : null;
    }

    /**
     * Resets the state, needed in long running processes (octane, queue workers) and while testing,
     * the state belongs to a single response.
     */
    public static function reset(): void
    {
        self::$isDraft = false;
        self::$expiresAt = null;
    }
}
