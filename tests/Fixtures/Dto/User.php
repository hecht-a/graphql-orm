<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;

#[Entity(name: 'users')]
class User
{
    #[Field]
    #[Identifier]
    public int $id;

    #[Field]
    public string $name;

    #[Relation]
    public ?Company $company = null;

    /** @var list<Task> */
    #[Relation]
    public array $tasks = [];
}
