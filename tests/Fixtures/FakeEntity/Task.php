<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

final class Task
{
    public int $id;
    public ?string $title;
    public ?User $user = null;
}
