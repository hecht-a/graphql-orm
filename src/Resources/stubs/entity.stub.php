<?php

namespace {{ namespace }};

use {{ repo_namespace }}\{{ repo_short }};
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;
{{ relation_imports }}

#[GraphqlEntity('{{ root }}', {{ repo_short }}::class)]
class {{ class_name }}
{
{{ properties }}
}