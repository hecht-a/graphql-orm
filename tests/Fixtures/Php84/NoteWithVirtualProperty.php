<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Php84;

use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'notes')]
class NoteWithVirtualProperty
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public int $id;

    #[GraphqlOrm\Field]
    public string $title;

    /** Computed from the title, it holds no value of its own. */
    #[GraphqlOrm\Field]
    public string $slug {
        get => strtolower($this->title);
    }
}
