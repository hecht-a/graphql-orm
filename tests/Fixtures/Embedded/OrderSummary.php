<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Embedded;

use GraphqlOrm\Mapping\MapFrom;

final readonly class OrderSummary
{
    public function __construct(
        public int $id,
        public ?Money $total,
        #[MapFrom('price.amount')]
        public ?int $priceAmount = null,
    ) {
    }
}
