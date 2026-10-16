<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Crm\Repository;

use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Repository\ServiceGraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Crm\Contact;

/**
 * @extends ServiceGraphqlEntityRepository<Contact>
 */
final class ContactRepository extends ServiceGraphqlEntityRepository
{
    public function __construct(GraphqlManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }
}
