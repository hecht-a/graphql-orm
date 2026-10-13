<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Strict;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(name: 'strictTasks')]
class StrictTask
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'owner')]
    public ?StrictUser $owner = null;

    /** @var StrictUser[] */
    #[GraphqlField(mappedFrom: 'members', targetEntity: StrictUser::class)]
    public array $members = [];
}
