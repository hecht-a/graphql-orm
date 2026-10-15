<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithBothHooks
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public string $name;

    /** @var string[] */
    public array $callOrder = [];

    /**
     * @param array<string, mixed> $data
     */
    #[BeforeHydrate]
    public function before(array $data): void
    {
        $this->callOrder[] = 'before';
    }

    #[AfterHydrate]
    public function after(): void
    {
        $this->callOrder[] = 'after';
    }
}
