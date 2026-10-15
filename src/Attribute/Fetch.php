<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

use GraphqlOrm\Mapping\Fetch as MappingFetch;

trigger_deprecation('hecht-a/graphql-orm', '4.9', 'The "%s" enum is deprecated, use "%s" instead.', 'GraphqlOrm\Attribute\Fetch', MappingFetch::class);

class_alias(MappingFetch::class, 'GraphqlOrm\Attribute\Fetch');
