<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Mapping\Entity;
use GraphqlOrm\Mapping\Field;
use GraphqlOrm\Mapping\Identifier;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[Entity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithBeforeHydrate
{
    #[Field(mappedFrom: 'id')]
    #[Identifier]
    public int $id;

    #[Field(mappedFrom: 'name')]
    public string $name;

    public string $rawTypename = '';

    public int $beforeHydrateCallCount = 0;

    /** @var array<string, mixed> */
    public array $receivedData = [];

    /**
     * @param array<string, mixed> $data
     */
    #[BeforeHydrate]
    public function onBeforeHydrate(array $data): void
    {
        $typename = $data['__typename'] ?? null;
        $this->rawTypename = \is_string($typename) ? $typename : 'unknown';
        $this->receivedData = $data;
        ++$this->beforeHydrateCallCount;
    }
}
