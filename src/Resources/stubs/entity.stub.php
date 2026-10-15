<?php

namespace {{ namespace }};

use {{ repo_namespace }}\{{ repo_short }};
{{ attribute_imports }}
{{ relation_imports }}

#[GraphqlEntity('{{ root }}', {{ repo_short }}::class)]
class {{ class_name }}
{
{{ properties }}
}