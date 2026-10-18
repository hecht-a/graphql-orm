<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures\Dto;

use GraphqlOrm\Mapping\MapFrom;

final readonly class UserCard
{
    public function __construct(
        public string $name,
        #[MapFrom('company.name')]
        public string $companyName = 'No company',
    ) {
    }
}
