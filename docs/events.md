# Events

Each query dispatches events through the Symfony event dispatcher, to plug metrics, tracing or alerting without
decorating the GraphQL client. The entities written by `flush()` dispatch [their own](#writing-entities).

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

## Writing entities

[`flush()`](writing.md) dispatches the lifecycle events of the entities it writes, as Doctrine:

| Event              | Dispatched                                                                     |
|--------------------|--------------------------------------------------------------------------------|
| `PrePersistEvent`  | by `persist()` on a new entity                                                 |
| `PreRemoveEvent`   | by `remove()` on an entity a query read                                        |
| `PreFlushEvent`    | when `flush()` starts, before it looks for the entities to write               |
| `PreUpdateEvent`   | before updating an entity, with its `changedProperties`                        |
| `PostPersistEvent` | once the entity is created, its identifier set                                 |
| `PostUpdateEvent`  | once the entity is updated                                                     |
| `PostRemoveEvent`  | once the entity is deleted                                                     |
| `PostFlushEvent`   | once every entity is written, even when there was none                         |

The events of an entity extend `LifecycleEvent`, holding the `entity` and the `manager`; `PreFlushEvent` and
`PostFlushEvent` hold the `manager`. What a listener of `PrePersistEvent`, `PreFlushEvent` or `PreUpdateEvent` sets on
an entity is written:

```php
use GraphqlOrm\Event\PreUpdateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
public function onPreUpdate(PreUpdateEvent $event): void
{
    if ($event->entity instanceof Task && \in_array('done', $event->changedProperties, true)) {
        $event->entity->doneAt = new \DateTimeImmutable();
    }
}
```

A post event of a failed mutation is not dispatched, nor `PostFlushEvent` when `flush()` throws. Calling `flush()`
from a listener of the flush throws a `LogicException`: call it once the flush is done, from a `PostFlushEvent`
listener.

### Lifecycle callbacks

An entity reacts to its own lifecycle with public methods without parameter, marked as Doctrine lifecycle callbacks,
called before the listeners of the same step:

```php
use GraphqlOrm\Attribute\PrePersist;
use GraphqlOrm\Attribute\PreUpdate;

#[GraphqlOrm\Entity(name: 'tasks')]
class Task
{
    #[GraphqlOrm\Field(mappedFrom: 'updated_at')]
    public ?\DateTimeImmutable $updatedAt = null;

    #[PrePersist]
    #[PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

The attributes are `#[PrePersist]`, `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]` and
`#[PostRemove]`, in the `GraphqlOrm\Attribute` namespace as the [hydration hooks](hydration-hooks.md).
