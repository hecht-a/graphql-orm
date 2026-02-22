# GraphqlOrm
**GraphqlOrm** is a lightweight **GraphQL ORM for PHP**, inspired by concepts from **Doctrine ORM**.

It allows you to map PHP objects to a GraphQL API using attributes and provides repositories, query builders, automatic hydration, and relation handling.

---

## Features
- Attribute-based entity mapping
- Automatic GraphQL query generation
- Repository pattern
- Query Builder API
- Automatic relation resolution
- Nested hydration (relations)
- Identifier management
- Type casting and hydration
- Exception-driven error handling
- Cycle-safe relation loading
- Fully testable architecture

---

## Installation
Install using Composer:

```bash
composer require hecht-a/graphql-orm
```

## Configuration
Configure the GraphQL endpoint and entity mapping.

Example :
```yaml
graphql_orm:
    endpoint: 'http://localhost:4000'
    max_depth: 3

    mapping:
        entity:
            dir: '%kernel.project_dir%/src/GraphQL/Entity'
            namespace: App\GraphQL\Entity
        repository:
            dir: '%kernel.project_dir%/src/GraphQL/Repository'
            namespace: App\GraphQL\Repository
```
Options:

- `endpoint` : GraphQL API endpoint.
- `max_depth` : Maximum nested relation loading depth.
- `mapping.entity` : Entity generation directory and namespace.
- `mapping.repository` : Repository generation directory and namespace.

---

## Entity Generator
GraphqlOrm provides a console wizard to generate entities and repositories.

Create a new entity :

```bash
php bin/console graphqlorm:make:entity Task
````

The command generates :
- Entity class
- Repository class
- Identifier property
- Scalar properties
- Relations

The generator asks interactively :
- GraphQL root name
- Identifier field
- Scalar properties
- Relations

Example wizard :
```
Add a property ? yes

Property name:
id

Field name in GraphQL schema :
id

Identifier ? yes

Type:
int

Nullable ? no

Add a property ? yes

Property name:
title

Field name in GraphQL schema :
title

Type:
string

Nullable ? no

Add a property ? yes

Property name:
user

Field name in GraphQL schema :
user

Type:
relation
```

Relation wizard :
```
Target entity :
> Us<TAB>
User

Relation type :
object
```
Existing entities are automatically suggested using autocomplete.

Generated example :
```php
#[GraphqlEntity('tasks', TaskRepository::class)]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    private int $id;

    #[GraphqlField(mappedFrom: 'title')]
    private string $title;

    #[GraphqlField(mappedFrom: 'user')]
    private ?User $user = null;
}
```
Repository :
```php
/**
 * @extends GraphqlEntityRepository<Task>
 * @method Task[] findAll()
 * @method Task[] findBy(array $criteria)
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, Task::class);
    }
}
```
--- 

## Quick Start
### Define an Entity
```php
use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\GraphqlField;

#[GraphqlEntity(
    name: 'tasks',
    repositoryClass: TaskRepository::class
)]
class Task
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'title')]
    public string $title;

    #[GraphqlField(mappedFrom: 'user')]
    public User $user;
}
```
Define Related Entity
```php
#[GraphqlEntity(
    name: 'users',
    repositoryClass: UserRepository::class
)]
class User
{
    #[GraphqlField(mappedFrom: 'id', identifier: true)]
    public int $id;

    #[GraphqlField(mappedFrom: 'name')]
    public string $name;
}
```
### Define a Repository
```php
/**
 * @extends GraphqlEntityRepository<Task>
 * @method Task[] findAll()
 * @method Task[] findBy(array $criteria)
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, Task::class);
    }
}
```

```php
/**
 * @extends GraphqlEntityRepository<User>
 * @method User[] findAll()
 * @method User[] findBy(array $criteria)
 */
class UserRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, User::class);
    }
}
```

### Repository Usage
Repositories provide a simple entry point to query entities.

Example in a controller :
```php
use App\GraphQL\Repository\TaskRepository;
use App\GraphQL\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(TaskRepository $todoRepository, UserRepository $userRepository): Response
    {
        $todos = $todoRepository->findAll();
        $users = $userRepository->findAll();

        return new Response();
    }
} 
```

### Query Builder
The Query Builder allows advanced queries.
```php
/**
 * @extends GraphqlEntityRepository<Task>
 * @method Task[] findAll()
 * @method Task[] findBy(array $criteria)
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, Task::class);
    }
    
    public function findFiltered()
    {
        $qb = $this
            ->createQueryBuilder()
            ->select('title', 'user.name')
            ->where('id', 10);
            
        return $qb->getQuery()->getResult();
    }
}
```

### Custom GraphQL Query
You may bypass the builder entirely:
```php
/**
 * @extends GraphqlEntityRepository<Task>
 * @method Task[] findAll()
 * @method Task[] findBy(array $criteria)
 */
class TaskRepository extends GraphqlEntityRepository
{
    public function __construct(GraphqlManager $graphQLManager)
    {
        parent::__construct($graphQLManager, Task::class);
    }
    
    public function findFiltered()
    {
        $qb = $this
            ->createQueryBuilder()
            ->setGraphQL('
                query {
                    tasks {
                        id
                        title
                    }
                }
            ')
            ->getQuery();
            
        return $qb->getQuery()->getResult();
    }
}
```

### Relations
Relations are automatically detected:
- Via targetEntity
- Or via typed properties referencing another GraphQL entity.

Example:
```php
#[GraphqlField(mappedFrom: 'user', targetEntity: User::class)]
public mixed $user;
```

```php
#[GraphqlField(mappedFrom: 'user')]
public User $user;
```
