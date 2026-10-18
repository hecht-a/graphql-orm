<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Embedded;

use GraphqlOrm\Mapping\Embeddable;
use GraphqlOrm\Mapping\Field;

#[Embeddable]
final readonly class Money
{
    public function __construct(
        #[Field]
        public int $amount,
        #[Field]
        public string $currency,
    ) {
    }
}
