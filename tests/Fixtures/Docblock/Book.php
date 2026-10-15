<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Docblock;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'books', repositoryClass: FakeRepository::class)]
class Book
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[Field(mappedFrom: 'title')]
    public ?string $title = null;
}
