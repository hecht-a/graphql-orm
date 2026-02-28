<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithBeforeHydrate
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
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
        $this->rawTypename = $data['__typename'] ?? 'unknown';
        $this->receivedData = $data;
        ++$this->beforeHydrateCallCount;
    }
}
