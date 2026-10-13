<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\FakeRepository;

use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\User;

/**
 * @extends GraphqlEntityRepository<User>
 */
class UserRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, User::class);
    }
}
