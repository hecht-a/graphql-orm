<?php

declare(strict_types=1);

namespace GraphqlOrm\Persistence;

use GraphqlOrm\Attribute\PostPersist;
use GraphqlOrm\Attribute\PostRemove;
use GraphqlOrm\Attribute\PostUpdate;
use GraphqlOrm\Attribute\PrePersist;
use GraphqlOrm\Attribute\PreRemove;
use GraphqlOrm\Attribute\PreUpdate;
use GraphqlOrm\Event\LifecycleEvent;
use GraphqlOrm\Event\PostPersistEvent;
use GraphqlOrm\Event\PostRemoveEvent;
use GraphqlOrm\Event\PostUpdateEvent;
use GraphqlOrm\Event\PrePersistEvent;
use GraphqlOrm\Event\PreRemoveEvent;
use GraphqlOrm\Event\PreUpdateEvent;
use GraphqlOrm\GraphqlManagerInterface;

/**
 * A step of the lifecycle of an entity written: its callback attribute and its event.
 *
 * @internal
 */
enum Lifecycle
{
    case PrePersist;
    case PostPersist;
    case PreUpdate;
    case PostUpdate;
    case PreRemove;
    case PostRemove;

    /**
     * @return class-string
     */
    public function attribute(): string
    {
        return match ($this) {
            self::PrePersist => PrePersist::class,
            self::PostPersist => PostPersist::class,
            self::PreUpdate => PreUpdate::class,
            self::PostUpdate => PostUpdate::class,
            self::PreRemove => PreRemove::class,
            self::PostRemove => PostRemove::class,
        };
    }

    /**
     * @param list<string> $changedProperties the properties an update writes
     */
    public function event(object $entity, GraphqlManagerInterface $manager, array $changedProperties): LifecycleEvent
    {
        return match ($this) {
            self::PrePersist => new PrePersistEvent($entity, $manager),
            self::PostPersist => new PostPersistEvent($entity, $manager),
            self::PreUpdate => new PreUpdateEvent($entity, $manager, $changedProperties),
            self::PostUpdate => new PostUpdateEvent($entity, $manager),
            self::PreRemove => new PreRemoveEvent($entity, $manager),
            self::PostRemove => new PostRemoveEvent($entity, $manager),
        };
    }
}
