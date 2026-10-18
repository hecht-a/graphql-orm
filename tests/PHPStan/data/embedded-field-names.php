<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan\data;

use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Embedded\Order;

/**
 * @param GraphqlEntityRepository<Order> $orders
 */
function embeddedFieldNames(GraphqlEntityRepository $orders): void
{
    $orders->createQueryBuilder()->select('total.amount', 'price.currency', 'price')->orderBy('total.amount', Direction::Asc);
    $orders->findBy(['total.currency' => 'EUR'], ['total.amount' => Direction::Desc]);
    $orders->createQueryBuilder()->select('total.amont');
}
