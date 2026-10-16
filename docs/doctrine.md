# Doctrine entities referencing GraphQL entities

An application often holds its own data in a database, with Doctrine, and reads other data from a GraphQL API: an
invoice stored in the application belongs to a client of the data warehouse. With `doctrine/orm`, a Doctrine entity can
reference a GraphQL entity by its identifier, stored in a column:

```php
use Doctrine\ORM\Mapping as ORM;
use GraphqlOrm\Doctrine\GraphqlReference;

#[ORM\Entity]
class Invoice
{
    #[ORM\Column(nullable: true)]
    private ?string $clientId = null;

    // Not mapped by Doctrine: set by the bundle on load
    #[GraphqlReference(identifier: 'clientId')]
    private ?Client $client = null;

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): void
    {
        $this->client = $client;
        $this->clientId = $client?->id;
    }
}
```

When Doctrine loads an `Invoice`, the bundle sets `$client` with a reference to the GraphQL `Client` (see
[`getReference()`](#references)): no query is sent until a property other than the identifier is read, and the
clients of the invoices read in a same page are loaded together, with the shared identity map (the default) and a
dialect loading lazy relations in batches. An identifier the API no longer knows throws an `EntityNotFoundException` on
first access.

Before the first persist of an entity, the identifier column is filled from the reference set. Doctrine sees no change
of a property it does not map: to change the reference of a stored entity, change its identifier column too, as
`setClient()` above does.

The target is read from the type of the property; give it with `#[GraphqlReference(identifier: 'clientId', targetEntity:
Client::class)]` otherwise. The listener is registered when `doctrine/persistence` is installed (DoctrineBundle tags it).

## References

`getReference()` gives the entity of an identifier without a query, as Doctrine `EntityManager::getReference()`:

```php
$client = $clientRepository->getReference('C-042'); // no query
$client->id;    // 'C-042', no query
$client->name;  // loads the client
```

The entity the shared identity map already knows is given as is. With the shared identity map, the first reference
accessed loads the other references of its class waiting to be, with one query when the dialect loads lazy relations in
batches; they are forgotten between two requests or messages.
