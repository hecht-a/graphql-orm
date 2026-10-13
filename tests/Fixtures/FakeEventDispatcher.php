<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

final class FakeEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
