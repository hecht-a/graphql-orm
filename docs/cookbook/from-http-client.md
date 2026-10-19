# From HttpClient to the bundle

This page builds one feature twice: a page listing the open tasks of a project with their assignee, and a button
marking a task done. The first version sends hand-written GraphQL with the Symfony HttpClient, the second one maps the
API with the bundle. The API is a Data API Builder server at `https://graphql.example.com/graphql`, exposing `tasks`
(`id`, `title`, `done`, `due_date`, `project_id`, `assignee`) and `users` (`id`, `name`, `email`).

## With the HttpClient

```php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProjectTasksController extends AbstractController
{
    private const string ENDPOINT = 'https://graphql.example.com/graphql';

    public function __construct(private HttpClientInterface $client) {}

    #[Route('/projects/{projectId}/tasks', name: 'project_tasks', methods: ['GET'])]
    public function index(int $projectId): Response
    {
        $data = $this->send('
            query ($projectId: Int) {
              tasks(filter: { project_id: { eq: $projectId }, done: { eq: false } }, orderBy: { due_date: ASC }) {
                items { id title due_date assignee { id name } }
              }
            }', ['projectId' => $projectId]);

        $tasks = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'title' => $row['title'],
            'dueDate' => $row['due_date'] !== null ? new \DateTimeImmutable($row['due_date']) : null,
            'assignee' => $row['assignee'],
        ], $data['tasks']['items']);

        return $this->render('task/index.html.twig', ['tasks' => $tasks, 'projectId' => $projectId]);
    }

    #[Route('/tasks/{id}/done', name: 'task_done', methods: ['POST'])]
    public function done(int $id): Response
    {
        $this->send('mutation ($id: Int!) { updateTask(id: $id, item: { done: true }) { id } }', ['id' => $id]);

        return $this->redirectToRoute('project_tasks', ['projectId' => $this->projectOf($id)]);
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function send(string $query, array $variables): array
    {
        // toArray() throws on a 4xx or 5xx status, not on the GraphQL errors of a 200 response
        $payload = $this->client->request('POST', self::ENDPOINT, [
            'json' => ['query' => $query, 'variables' => $variables],
        ])->toArray();

        if (isset($payload['errors'])) {
            throw new \RuntimeException($payload['errors'][0]['message']);
        }

        return $payload['data'];
    }

    // projectOf(): one more query, to know where to go back
}
```

Everything is by hand: the envelope of the dialect (`items`), the snake_case names, the date conversion, the errors of
a `200` response, and a second query to find the project of a task. The rows are arrays: a typo in a key is a `null`
in the template, not an error.

## With the bundle

```yaml
# config/packages/graphql_orm.yaml
graphql_orm:
    endpoint: '%env(GRAPHQL_ORM_ENDPOINT)%'
    dialect: GraphqlOrm\Dialect\DataApiBuilderDialect
```

```php
use GraphqlOrm\Mapping as GraphqlOrm;

#[GraphqlOrm\Entity(name: 'tasks', repositoryClass: TaskRepository::class)]
class Task
{
    #[GraphqlOrm\Field]
    #[GraphqlOrm\Identifier]
    public ?int $id = null;

    #[GraphqlOrm\Field]
    public string $title = '';

    #[GraphqlOrm\Field]
    public bool $done = false;

    #[GraphqlOrm\Field(mappedFrom: 'due_date')]
    public ?\DateTimeImmutable $dueDate = null;

    #[GraphqlOrm\Field(mappedFrom: 'project_id')]
    public ?int $projectId = null;

    #[GraphqlOrm\Relation(mappedFrom: 'assignee', joinField: 'assignee_id')]
    public ?User $assignee = null;
}
```

`User` is mapped the same way (`id`, `name`, `email`), and `TaskRepository` extends `ServiceGraphqlEntityRepository`
(see [repositories](../repositories.md#define-a-repository)). `graphqlorm:make:entity Task --from-schema` writes both
from the schema (see [commands](../commands.md#from-the-graphql-schema)).

```php
use GraphqlOrm\GraphqlManagerInterface;
use GraphqlOrm\Query\Direction;

final class ProjectTasksController extends AbstractController
{
    public function __construct(private GraphqlManagerInterface $manager) {}

    #[Route('/projects/{projectId}/tasks', name: 'project_tasks', methods: ['GET'])]
    public function index(int $projectId, TaskRepository $taskRepository): Response
    {
        $tasks = $taskRepository->with('assignee')->findBy(
            ['projectId' => $projectId, 'done' => false],
            ['dueDate' => Direction::Asc],
        );

        return $this->render('task/index.html.twig', ['tasks' => $tasks, 'projectId' => $projectId]);
    }

    #[Route('/tasks/{id}/done', name: 'task_done', methods: ['POST'])]
    public function done(Task $task): Response
    {
        $task->done = true;
        $this->manager->flush(); // updateTask(id: 3, item: { done: true }) { id }

        return $this->redirectToRoute('project_tasks', ['projectId' => $task->projectId]);
    }
}
```

- `with('assignee')` loads the relation the page reads with the tasks, whatever `max_depth` (see
  [loading relations](../query-builder.md#loading-relations)).
- The `Task $task` argument is found by its identifier, a 404 when it does not exist (see
  [in a controller](../repositories.md#in-a-controller)).
- `flush()` sends only the field changed, and throws a `FlushException` when the API refuses it (see
  [writing entities](../writing.md#failures)).

The template reads entities rather than arrays:

```twig
{% for task in tasks %}
    <tr>
        <td>{{ task.title }}</td>
        <td>{{ task.dueDate ? task.dueDate|date('Y-m-d') : '' }}</td>
        <td>{{ task.assignee.name ?? 'Unassigned' }}</td>
        <td>
            <form method="post" action="{{ path('task_done', {id: task.id}) }}">
                <button type="submit" class="btn btn-sm btn-outline-success">Done</button>
            </form>
        </td>
    </tr>
{% endfor %}
```

## What comes with it

- **Identity map**: the queries of a request share their entities, and `find()` answers without a query when the entity
  is known (see [shared identity map](../configuration.md#shared-identity-map)).
- **Profiler**: each query with its caller, timing and response, and the N+1 relations and repeated queries pointed out
  (see [profiler](../profiler.md#n-1-and-repeated-queries)).
- **Caches**: the result cache keeps a response between requests, the second-level cache keeps entities (see
  [caching](../caching.md)).
- **Masked values**: the values of the queries are replaced by `***` in the logs and the profiler (see
  [logging](../logging.md#masked-values)).
- **Schema validation**: `graphqlorm:schema:validate` checks the mapping against the schema in CI (see
  [schema validation](../schema-validation.md)).
- **Type conversion**: dates, backed enums and value objects are converted both ways (see
  [supported PHP types](../entity-mapping.md#supported-php-types) and
  [type converters](../entity-mapping.md#type-converters)).
- **Pagination**: `paginate()` and `next()` over server cursors or offsets, Pagerfanta and KnpPaginator (see
  [pagination](../pagination.md)).

## See also

- [When to use it, and when not](when-to-use.md)
- [Entity mapping](../entity-mapping.md)
- [Repositories](../repositories.md)
- [Writing entities](../writing.md)
