<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration\PostGraphile\Entity;

use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'allNotes', type: 'Note')]
class RelayNote
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $title;

    #[GraphqlOrm\Field]
    public bool $done = false;

    #[GraphqlOrm\Relation(mappedFrom: 'userByUserId', joinField: 'userId')]
    public ?RelayUser $author = null;
}
