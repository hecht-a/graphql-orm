<?php

namespace {{ namespace }};

use {{ entity_namespace }}\{{ entity_short }};
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Repository\GraphqlEntityRepository;

/**
 * @extends GraphqlEntityRepository<{{ entity_short }}>
 * @method {{ entity_short }}[] findAll()
 * @method {{ entity_short }}[] findBy(array $criteria)
 */
class {{ repo_class_name }} extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
{
    parent::__construct($graphQLManager, {{ entity_short }}::class);
    }
}