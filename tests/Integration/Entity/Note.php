<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\Entity;

use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'notes', type: 'Note')]
class Note
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $title;

    #[GraphqlOrm\Field]
    public bool $done = false;

    #[GraphqlOrm\Relation(joinField: 'user_id')]
    public ?User $author = null;
}
