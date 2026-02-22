<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

final class User
{
    public int $id;
    public string $name;
    /** @var Task[] */
    public array $tasks = [];
}
