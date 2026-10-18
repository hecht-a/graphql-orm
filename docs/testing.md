# Testing

`MockGraphqlServer` stands for the GraphQL server in the tests of your application: it answers the queries with the
responses you queue, in order, and records them. It replaces the HTTP client of the bundle, so the queries still go
through the GraphQL client, its error handling and the profiler.

```yaml
# config/packages/graphql_orm.yaml
when@test:
    graphql_orm:
        http_client: GraphqlOrm\Test\MockGraphqlServer
```

The bundle then registers the server as a public service, fetched by the tests from the container:

```php
use GraphqlOrm\Test\MockGraphqlServer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskServiceTest extends KernelTestCase
{
    #[Test]
    public function itListsTheOpenTasks(): void
    {
        $server = self::getContainer()->get(MockGraphqlServer::class);
        $server->respond(['tasks' => ['items' => [['id' => 1, 'title' => 'Write the docs']]]]);

        $tasks = self::getContainer()->get(TaskService::class)->openTasks();

        self::assertSame('Write the docs', $tasks[0]->title);
        self::assertCount(1, $server->requests());
        self::assertStringContainsString('done: { eq: false }', $server->requests()[0]->query);
    }
}
```

| Method                                 | Queues                                                         |
|----------------------------------------|----------------------------------------------------------------|
| `respond($data)`                       | a response holding this `data`, keyed by root field            |
| `respondWithErrors($errors, $data)`    | GraphQL errors, with the partial data given if any             |
| `respondWithHttpStatus($statusCode)`   | an HTTP error, e.g. `503`                                      |

The methods queuing a response return the server, so that they can be chained. `requests()` returns the requests
received, in order, as `RecordedRequest` objects with their `query` and their `variables`. A query sent once every
queued response is used fails with a `GraphqlOrm\Exception\LogicException` showing it.

The responses are written in the shape of your API: `items` for Data API Builder, `edges`/`node` for Relay... The
introspection queries of the bundle (typed `variables`, `schema_validation`) are answered by the server itself, with an
empty schema: the values are then sent inline, and these queries are neither recorded in `requests()` nor consume the
queued responses.
