<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Write;

use GraphqlOrm\Mapping as GraphqlOrm;
use GraphqlOrm\Tests\Fixtures\Dto\Priority;
use GraphqlOrm\Tests\Fixtures\Embedded\Money;

#[GraphqlOrm\Entity(name: 'notes')]
class Note
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $title;

    #[GraphqlOrm\Field]
    public bool $done = false;

    #[GraphqlOrm\Field(mappedFrom: 'due_date')]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlOrm\Field]
    public ?Priority $priority = null;

    #[GraphqlOrm\Relation(joinField: 'author_id')]
    public ?Author $author = null;

    #[GraphqlOrm\Embedded(prefix: 'cost_')]
    public ?Money $cost = null;
}
