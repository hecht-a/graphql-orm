<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

enum SchemaValidationMode: string
{
    case Exception = 'exception';
    case Warning = 'warning';
    case Disabled = 'disabled';
}
