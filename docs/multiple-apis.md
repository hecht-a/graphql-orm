# Several APIs

An application may read several GraphQL APIs: its own, a CRM, a billing service... Each one gets a **manager**, as the
entity managers of Doctrine: its endpoint, dialect, headers, caches and mapping, and its own repositories.

## Configuration

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    # The options of the root are those of every manager, unless it sets them
    max_depth: 2
    headers:
        X-App: shop

    default_manager: main          # the first manager when unset
    managers:
        main:
            endpoint: '%env(MAIN_GRAPHQL_ENDPOINT)%'
            dialect: GraphqlOrm\Dialect\DataApiBuilderDialect
            mapping:
                entity:
                    dir: '%kernel.project_dir%/src/GraphQL/Main/Entity'
                    namespace: App\GraphQL\Main\Entity
                repository:
                    dir: '%kernel.project_dir%/src/GraphQL/Main/Repository'
                    namespace: App\GraphQL\Main\Repository
        crm:
            endpoint: '%env(CRM_GRAPHQL_ENDPOINT)%'
            dialect: GraphqlOrm\Dialect\HasuraDialect
            headers_provider: App\Crm\TokenProvider
            headers:
                X-Tenant: shop         # added to those of the root
            second_level_cache:
                enabled: true
                pool: cache.redis
            mapping:
                entity:
                    dir: '%kernel.project_dir%/src/GraphQL/Crm/Entity'
                    namespace: App\GraphQL\Crm\Entity
                repository:
                    dir: '%kernel.project_dir%/src/GraphQL/Crm/Repository'
                    namespace: App\GraphQL\Crm\Repository
```

Every option of [the configuration](configuration.md) can be set for a manager: those it does not set are taken from the
root, nested options key by key (above, the CRM keeps the `default_lifetime` of the root second-level cache). Without
`managers`, the root is the configuration of a single manager named `default`: an application reading one API changes
nothing.

## The manager of an entity

An entity belongs to the manager:

1. its `#[Entity]` names, e.g. `#[Entity(name: 'contacts', manager: 'crm')]`, for an entity outside the namespace of its
   manager;
2. otherwise, whose `mapping.entity.namespace` holds it (the most precise when they nest);
3. otherwise, the default manager.

A relation between entities of two APIs is refused with a `LogicException`: an API cannot select the fields of another.
Map the identifier of the other entity, and load it from its own manager.

## Getting a manager

`GraphqlManagerRegistry`, as the `ManagerRegistry` of Doctrine, gives a manager by its name or by the class of an entity:

```php
use GraphqlOrm\Registry\GraphqlManagerRegistry;

public function __construct(private GraphqlManagerRegistry $registry) {}

$contacts = $this->registry->getRepository(Contact::class)->findAll(); // through the manager "crm"
$crm = $this->registry->getManager('crm');
$this->registry->getManagerForClass(Contact::class)->getName();          // "crm"
$this->registry->getManagerNames();                                     // ["main", "crm"]
```

A manager is also autowired by the name of its argument, and the default one without name:

```php
public function __construct(
    private GraphqlManagerInterface $manager,      // the default manager
    private GraphqlManagerInterface $crmManager,   // graphql_orm.managers.crm
    private GraphqlCache $crmCache,                // its caches, to evict what changed
) {}
```

The services of a manager are named `graphql_orm.<manager>.<service>`, e.g. `graphql_orm.crm.manager` and
`graphql_orm.crm.client`.

## Repositories

A repository extending `ServiceGraphqlEntityRepository` takes the manager of its entity from the registry, as the
`ServiceEntityRepository` of Doctrine (`graphqlorm:make:entity` generates them so):

```php
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Repository\ServiceGraphqlEntityRepository;

/**
 * @extends ServiceGraphqlEntityRepository<Contact>
 */
class ContactRepository extends ServiceGraphqlEntityRepository
{
    public function __construct(GraphqlManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }
}
```

A repository taking `GraphqlManager $manager` gets the default manager, and throws a `LogicException` when built for an
entity of another API: give it the manager of its API by name (`GraphqlManager $crmManager`), or extend
`ServiceGraphqlEntityRepository`.

## Everything else follows the entity

The controller arguments ([`#[MapGraphqlEntity]`](repositories.md)), the [form fields and their autocomplete
search](forms.md), the [serializer](serializer.md), the [references of Doctrine entities](doctrine.md) and
`graphqlorm:find` use the manager of the entity they handle. Each API keeps its own introspection (types of the
variables, countable fields), [schema validation](schema-validation.md), identity map and [caches](caching.md): two
APIs sharing a pool keep their results apart.

The commands take the manager of the API to work with:

```bash
php bin/console graphqlorm:make:entity Contact --from-schema --manager=crm   # the mapping and the schema of the CRM
php bin/console graphqlorm:schema:validate                                   # every API
php bin/console graphqlorm:schema:validate --manager=crm                     # the CRM only
```

The [profiler](profiler.md) lists the APIs in the toolbar and tells the API of each query.
