<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Strict;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Attribute\Identifier;
use GraphqlOrm\Attribute\Relation;

#[GraphqlEntity(name: 'strictTasks')]
class StrictTask
{
    #[GraphqlField(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[Relation(mappedFrom: 'owner')]
    public ?StrictUser $owner = null;

    /** @var StrictUser[] */
    #[Relation(mappedFrom: 'members', targetEntity: StrictUser::class)]
    public array $members = [];
}
