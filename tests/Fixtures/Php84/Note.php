<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Php84;

use GraphqlOrm\Mapping as GraphqlOrm;

/**
 * Properties of PHP 8.4: a readonly identifier, a property written by the entity only, and hooks.
 */
#[GraphqlOrm\Entity(name: 'notes')]
class Note
{
    public int $setHookCalls = 0;

    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public readonly int $id;

    #[GraphqlOrm\Field]
    public private(set) string $title;

    #[GraphqlOrm\Field]
    public string $body {
        set(string $value) {
            ++$this->setHookCalls;
            $this->body = trim($value);
        }
    }

    #[GraphqlOrm\Field]
    public string $code {
        get => strtoupper($this->code);
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }
}
