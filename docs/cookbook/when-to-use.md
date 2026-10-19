# When to use it, and when not

This page compares the bundle with a plain GraphQL client sending hand-written queries, an HTTP client and a query
string per call. Neither is better in general: the bundle pays off when an application reads and writes the same
entities again and again, a plain client when the queries are few, specific, or shaped by the API rather than by your
objects.

## A good fit

- **Entities read and written by a Symfony application**: the types of the API map to PHP classes, read with
  repositories and the [query builder](../query-builder.md), written with `persist()` and `flush()`
  ([writing entities](../writing.md)), used in [forms](../forms.md), controller arguments and the
  [serializer](../serializer.md).
- **Repeated access patterns**: the same lists, filters and relations across many pages. A repository method or a
  `findBy()` replaces a query string copied from one controller to the next, and the
  [dialect](../dialects.md) writes the envelope, the filters and the pagination of the API.
- **Watching what the pages cost**: the [profiler](../profiler.md) gives each query with its caller, and points out the
  N+1 relations, the repeated queries and the large responses.
- **Caching and sharing entities**: the [shared identity map](../configuration.md#shared-identity-map), the result cache
  and the second-level cache ([caching](../caching.md)) save queries without code of your own.
- **A mapping checked in CI**: `graphqlorm:schema:validate` reports a renamed field or a type changed by the API before
  a page fails (see [schema validation](../schema-validation.md)).

## A poor fit, and the limits

- **A one-off, very specific query**: a report joining fields that no entity maps, or an argument the dialect does not
  write. Write the query yourself: `graphqlorm:query` tries it from the console (see
  [send a raw query](../commands.md#send-a-raw-query)), and `setGraphQL()` sends it from the query builder (see
  [raw GraphQL query](../query-builder.md#raw-graphql-query)).
- **An API without a stable schema**: the mapping follows the schema, and a mapped field that disappears fails the
  queries selecting it. The [schema validation](../schema-validation.md) tells it, it does not prevent it.
- **Subscriptions**: not supported. The bundle sends queries and mutations over HTTP.
- **Unions and interfaces**: not supported. An entity maps one GraphQL object type, and `graphqlorm:make:entity
  --from-schema` skips the interface and union fields (see [commands](../commands.md#from-the-graphql-schema)).
- **A relation between two APIs**: refused with a `LogicException`, an API cannot select the fields of another. Map the
  identifier of the other entity and load it from its own manager (see
  [several APIs](../multiple-apis.md#the-manager-of-an-entity)).
- **An API with neither filters nor mutation conventions**: with `DefaultDialect`, the criteria are sent as plain
  arguments, and ordering, pagination, counting and writing throw a `LogicException` (see [dialects](../dialects.md)).

## Escape hatches

Using the bundle does not mean every query goes through an entity:

- **A raw query** with `setGraphQL()` on the query builder: the response is still hydrated as the entity of the
  repository (see [raw GraphQL query](../query-builder.md#raw-graphql-query)).
- **Arrays** with `getArrayResult()`: the rows as the API sent them, without entities, for an export or a JSON response
  (see [array results](../query-builder.md#array-results)).
- **DTOs** with `hydrateAs()`: read-only objects built through their constructor, selecting only their fields (see
  [DTO results](../query-builder.md#dto-results)).
- **The query of a find method** with `createFindByQuery()`: read its GraphQL with `getGraphQL()`, or cache it (see
  [built-in methods](../repositories.md#built-in-methods)).

A query of your own, kept out of the bundle, goes through the HTTP client of Symfony as before: both live side by side.

## See also

- [From HttpClient to the bundle](from-http-client.md)
- [Dialects](../dialects.md)
- [Console commands](../commands.md)
