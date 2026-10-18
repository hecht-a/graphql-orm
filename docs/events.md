# Events

Each query dispatches events through the Symfony event dispatcher, to plug metrics, tracing or alerting without
decorating the GraphQL client.

| Event                | Dispatched                                       | Trace content                                        |
|----------------------|--------------------------------------------------|------------------------------------------------------|
| `QueryStartedEvent`  | before the query is sent                         | id, query, variables, AST                            |
| `QueryExecutedEvent` | once the response is hydrated                    | + endpoint, duration, response size, hydration stats |
| `QueryFailedEvent`   | when sending or hydrating fails, before rethrow  | as available at the failure, + `$exception`          |

All of them extend `QueryEvent`, which holds the `trace` (a `GraphqlQueryTrace`). They are dispatched under their own
class name: a listener of `QueryEvent` itself is never called, so register one method for each event, or one method
typed `QueryEvent` several times, e.g. `#[AsEventListener(event: QueryExecutedEvent::class)]` and
`#[AsEventListener(event: QueryFailedEvent::class)]`. The `trace->id` is shared by the events of a same query, e.g. to
match the start and the end of a tracing span.

```php
use GraphqlOrm\Event\QueryExecutedEvent;
use GraphqlOrm\Event\QueryFailedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class GraphqlMetricsListener
{
    public function __construct(private MetricsClient $metrics) // your own metrics client
    {
    }

    #[AsEventListener]
    public function onExecuted(QueryExecutedEvent $event): void
    {
        $this->metrics->timing('graphql.query', $event->trace->duration, ['endpoint' => $event->trace->endpoint]);
    }

    #[AsEventListener]
    public function onFailed(QueryFailedEvent $event): void
    {
        $this->metrics->increment('graphql.failure', ['exception' => $event->exception::class]);
    }
}
```

The events are read-only: the query cannot be changed from a listener. Query values are masked in the trace, as in the
logs, unless `redact_query_values` is disabled (see [logging](logging.md)), so that a listener exporting it does not
leak personal data.
