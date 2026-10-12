<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Docblock;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUser;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'authors', repositoryClass: FakeRepository::class)]
class Author
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public ?int $id = null;

    /** @var Book[] */
    #[GraphqlField(mappedFrom: 'books')]
    public array $books = [];

    /** @var list<FakeTask> */
    #[GraphqlField(mappedFrom: 'tasks')]
    public array $tasks = [];

    /** @var string[] */
    #[GraphqlField(mappedFrom: 'tags')]
    public array $tags = [];

    /** @var array<mixed> */
    #[GraphqlField(mappedFrom: 'raw')]
    public array $raw = [];

    // Deliberately untyped: the metadata factory must cope with properties without a type.
    #[GraphqlField(mappedFrom: 'legacy')]
    public $legacy; // @phpstan-ignore missingType.property

    #[GraphqlField(mappedFrom: 'editor', targetEntity: FakeUser::class)]
    public FakeUser|\stdClass|null $editor = null;
}
