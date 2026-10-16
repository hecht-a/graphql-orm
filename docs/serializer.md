# Serializer

With `symfony/serializer` installed, the bundle registers `GraphqlEntityNormalizer`. It writes the entities whose fields
are not all loaded **without loading them**:

- a relation loaded on first access (`Fetch::Lazy`, or `lazy_loading`) and not read yet is written as its identifier;
- a partial entity, hydrated from a [selection of its fields](query-builder.md#field-selection), is written with its
  selected fields only, the others left out even when they have a default value: `"done": false` would contradict the
  API for a task that is done.

```php
$tasks = $taskRepository->findAll(); // Task::$assignee mapped with Fetch::Lazy

return $this->json($tasks);
// [{"id": 1, "title": "Write the docs", "assignee": {"id": 7}}, ...]: no query for the assignees
```

Without it, the `ObjectNormalizer` reads every property, and so loads each lazy relation with a query of its own (an N+1
problem hidden in a JSON response), or fails on a field the query did not select.

The entities held in full are left to the other normalizers, `ObjectNormalizer` included, with their groups, name
converters and context as usual. The properties are written under their PHP names. Select the relations you want
written in full (raise `max_depth`, or select their fields with the query builder).

The [profiler](profiler.md) counts, in its "Serializer" tab, the relations written as identifiers and the partial
entities written, and warns about the lazy loads sent while the serializer was writing an entity: the N+1 problem above,
when another normalizer reads the relations.
