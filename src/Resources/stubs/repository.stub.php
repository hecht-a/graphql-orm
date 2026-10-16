<?php

namespace {{ namespace }};

use {{ entity_namespace }}\{{ entity_short }};
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Repository\ServiceGraphqlEntityRepository;

/**
 * @extends ServiceGraphqlEntityRepository<{{ entity_short }}>
 */
class {{ repo_class_name }} extends ServiceGraphqlEntityRepository
{
    public function __construct(GraphqlManagerRegistry $registry)
    {
        parent::__construct($registry, {{ entity_short }}::class);
    }
}
