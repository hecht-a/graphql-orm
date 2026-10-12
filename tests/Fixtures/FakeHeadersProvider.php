<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Client\HeadersProviderInterface;

final class FakeHeadersProvider implements HeadersProviderInterface
{
    public int $callCount = 0;

    public function getHeaders(): array
    {
        ++$this->callCount;

        return ['Authorization' => 'Bearer token-' . $this->callCount];
    }
}
