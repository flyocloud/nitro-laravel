<?php

namespace Flyo\Laravel\Tests;

use Flyo\Laravel\Components\Head;
use Flyo\Laravel\Controllers\EntityController;
use Flyo\Laravel\DraftMode;
use Flyo\Model\Entity;
use Flyo\Model\EntityInterface;

/**
 * An entity requested through a draft link is delivered with `is_draft` and `draft_expires_at`
 * (flyo/nitro-php 3.0), the package turns that into the request scoped draft state which keeps the
 * response out of every cache.
 */
class DraftModeTest extends TestCase
{
    private function entity(array $data): Entity
    {
        return new Entity($data + [
            'entity' => new EntityInterface([
                'entity_title' => 'A Title',
                'entity_teaser' => 'A Teaser',
                'entity_image' => 'https://example.com/an-image.jpg',
            ]),
        ]);
    }

    public function test_a_regular_entity_is_not_a_draft(): void
    {
        $this->assertFalse(DraftMode::detect($this->entity(['is_draft' => false])));

        $this->assertFalse(DraftMode::isDraft());
        $this->assertNull(DraftMode::expiresAt());
    }

    public function test_an_entity_without_the_draft_flag_is_not_a_draft(): void
    {
        // the field is optional in the sdk model, a response missing it is a regular one
        $this->assertFalse(DraftMode::detect($this->entity([])));

        $this->assertFalse(DraftMode::isDraft());
    }

    public function test_a_draft_entity_flags_the_response_and_keeps_the_expiration(): void
    {
        $this->assertTrue(DraftMode::detect($this->entity([
            'is_draft' => true,
            'draft_expires_at' => 1755000000.0,
        ])));

        $this->assertTrue(DraftMode::isDraft());
        $this->assertSame(1755000000, DraftMode::expiresAt());
    }

    public function test_a_draft_entity_without_an_expiration_is_still_a_draft(): void
    {
        $this->assertTrue(DraftMode::detect($this->entity(['is_draft' => true])));

        $this->assertTrue(DraftMode::isDraft());
        $this->assertNull(DraftMode::expiresAt());
    }

    public function test_a_flagged_draft_is_not_unflagged_by_a_regular_entity(): void
    {
        DraftMode::detect($this->entity(['is_draft' => true, 'draft_expires_at' => 1755000000.0]));
        DraftMode::detect($this->entity(['is_draft' => false]));

        $this->assertTrue(DraftMode::isDraft());
        $this->assertSame(1755000000, DraftMode::expiresAt());
    }

    public function test_the_state_is_resettable(): void
    {
        DraftMode::flag(1755000000);
        DraftMode::reset();

        $this->assertFalse(DraftMode::isDraft());
        $this->assertNull(DraftMode::expiresAt());
    }

    public function test_assigning_the_meta_data_of_a_draft_entity_flags_the_response(): void
    {
        // an entity page which only calls the head component is protected as well
        Head::metaEntity($this->entity(['is_draft' => true, 'draft_expires_at' => 1755000000.0]));

        $this->assertTrue(DraftMode::isDraft());
        $this->assertSame(1755000000, DraftMode::expiresAt());
    }

    public function test_assigning_the_meta_data_of_a_regular_entity_does_not_flag_the_response(): void
    {
        Head::metaEntity($this->entity([]));

        $this->assertFalse(DraftMode::isDraft());
    }

    public function test_the_entity_controller_flags_a_draft_and_hands_it_to_the_view(): void
    {
        $entity = $this->entity(['is_draft' => true, 'draft_expires_at' => 1755000000.0]);

        // the draft token takes the place of the slug, no entity type id applies to it
        $view = app(EntityController::class)
            ->resolve(fn ($api, $param) => $entity)
            ->render('a-draft-token', 'flyo::page');

        $this->assertTrue(DraftMode::isDraft());
        $this->assertTrue($view->getData()['isDraft']);
        $this->assertSame(1755000000, $view->getData()['draftExpiresAt']);
    }

    public function test_the_entity_controller_does_not_flag_a_regular_entity(): void
    {
        $view = app(EntityController::class)
            ->resolve(fn ($api, $param) => $this->entity(['is_draft' => false]))
            ->render('a-slug', 'flyo::page');

        $this->assertFalse(DraftMode::isDraft());
        $this->assertFalse($view->getData()['isDraft']);
        $this->assertNull($view->getData()['draftExpiresAt']);
    }
}
