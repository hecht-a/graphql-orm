<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Converter;

final readonly class ProductSummary
{
    public function __construct(
        public string $name,
        public ?Money $price,
        public ?Money $discount = null,
    ) {
    }
}
