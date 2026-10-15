<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeRepository;

use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;

/**
 * A repository as an application declares it: a service built with the manager only.
 *
 * @extends GraphqlEntityRepository<FakeProject>
 */
class FakeProjectRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $manager)
    {
        parent::__construct($manager, FakeProject::class);
    }

    /**
     * @return list<FakeProject>
     */
    public function active(): array
    {
        return array_values($this->findBy(['active' => true]));
    }
}
