<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Write;

use GraphqlOrm\Attribute\PostPersist;
use GraphqlOrm\Attribute\PostRemove;
use GraphqlOrm\Attribute\PostUpdate;
use GraphqlOrm\Attribute\PrePersist;
use GraphqlOrm\Attribute\PreRemove;
use GraphqlOrm\Attribute\PreUpdate;
use GraphqlOrm\Mapping as GraphqlOrm;

/**
 * Stamps itself from its lifecycle callbacks, and records them.
 */
#[GraphqlOrm\Entity(name: 'notes', type: 'Note')]
#[GraphqlOrm\Cache]
class StampedNote
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $title;

    #[GraphqlOrm\Field]
    public ?string $stamp = null;

    /** @var list<string> */
    public array $callbacks = [];

    #[PrePersist]
    public function created(): void
    {
        $this->stamp = 'created';
        $this->callbacks[] = 'prePersist';
    }

    #[PreUpdate]
    public function updated(): void
    {
        $this->stamp = 'updated';
        $this->callbacks[] = 'preUpdate';
    }

    #[PreRemove]
    public function removing(): void
    {
        $this->callbacks[] = 'preRemove';
    }

    #[PostPersist]
    public function persisted(): void
    {
        $this->callbacks[] = 'postPersist';
    }

    #[PostUpdate]
    public function written(): void
    {
        $this->callbacks[] = 'postUpdate';
    }

    #[PostRemove]
    public function removed(): void
    {
        $this->callbacks[] = 'postRemove';
    }
}
