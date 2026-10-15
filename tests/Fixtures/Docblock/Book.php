<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Docblock;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'books', repositoryClass: FakeRepository::class)]
class Book
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    #[GraphqlField(mappedFrom: 'title')]
    public ?string $title = null;
}
