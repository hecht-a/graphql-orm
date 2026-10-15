<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Docblock;

use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Mapping\Relation;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUser;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'authors', repositoryClass: FakeRepository::class)]
class Author
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public ?int $id = null;

    /** @var Book[] */
    #[Relation(mappedFrom: 'books')]
    public array $books = [];

    /** @var list<FakeTask> */
    #[Relation(mappedFrom: 'tasks')]
    public array $tasks = [];

    /** @var string[] */
    #[Field(mappedFrom: 'tags')]
    public array $tags = [];

    /** @var array<mixed> */
    #[Field(mappedFrom: 'raw')]
    public array $raw = [];

    // Deliberately untyped: the metadata factory must cope with properties without a type.
    #[Field(mappedFrom: 'legacy')]
    public $legacy; // @phpstan-ignore missingType.property

    #[Relation(mappedFrom: 'editor', targetEntity: FakeUser::class)]
    public FakeUser|\stdClass|null $editor = null;
}
