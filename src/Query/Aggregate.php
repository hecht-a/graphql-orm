<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

/**
 * An aggregate function, named as Data API Builder and Hasura name it.
 */
enum Aggregate: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
}
