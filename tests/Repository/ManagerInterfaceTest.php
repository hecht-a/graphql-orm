<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\GraphqlManagerInterface;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An application depends on GraphqlManagerInterface, and simulates it in its tests.
 */
final class ManagerInterfaceTest extends TestCase
{
    #[Test]
    public function itCanBeSimulatedByTheTestsOfAnApplication(): void
    {
        $repository = $this->createStub(GraphqlEntityRepository::class);
        $manager = $this->createStub(GraphqlManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);

        self::assertSame($repository, $manager->getRepository(FakeProject::class));
    }
}
