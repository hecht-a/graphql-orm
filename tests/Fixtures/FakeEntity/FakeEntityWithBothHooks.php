<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeEntity;

use GraphqlOrm\Attribute\AfterHydrate;
use GraphqlOrm\Attribute\BeforeHydrate;
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;

#[GraphqlEntity(name: 'products', repositoryClass: FakeRepository::class)]
class FakeEntityWithBothHooks
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
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
