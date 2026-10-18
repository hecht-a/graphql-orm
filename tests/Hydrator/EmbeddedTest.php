<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Embedded\CachedOrder;
use GraphqlOrm\Tests\Fixtures\Embedded\Money;
use GraphqlOrm\Tests\Fixtures\Embedded\Order;
use GraphqlOrm\Tests\Fixtures\Embedded\OrderSummary;
use GraphqlOrm\Tests\Fixtures\Embedded\OrderWithNotEmbeddable;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A value object spread over several fields, #[GraphqlOrm\Embedded]: a GraphQL object, or prefixed fields of the entity.
 */
final class EmbeddedTest extends TestCase
{
    private const ORDERS = ['data' => ['orders' => [
        ['id' => 1, 'price' => ['amount' => 1250, 'currency' => 'EUR'], 'total_amount' => 2500, 'total_currency' => 'EUR'],
        ['id' => 2, 'price' => null, 'total_amount' => null, 'total_currency' => null],
    ]]];

    #[Test]
    public function itSelectsTheFieldsOfTheEmbeddables(): void
    {
        $client = new FakeGraphqlClient(self::ORDERS);

        $this->orders($client)->findAll();

        self::assertSame('query { orders { id price { amount currency } total_amount total_currency } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itHydratesAnEmbeddableFromAnObjectAndFromPrefixedFields(): void
    {
        $orders = $this->orders(new FakeGraphqlClient(self::ORDERS))->findAll();

        self::assertEquals(new Money(1250, 'EUR'), $orders[0]->price);
        self::assertEquals(new Money(2500, 'EUR'), $orders[0]->total);
    }

    #[Test]
    public function itHydratesNullForAnEmbeddableWithoutValues(): void
    {
        $order = $this->orders(new FakeGraphqlClient(self::ORDERS))->findAll()[1];

        self::assertNull($order->price);
        self::assertNull($order->total);
    }

    #[Test]
    public function itSelectsAFieldOfAnEmbeddable(): void
    {
        $client = new FakeGraphqlClient(['data' => ['orders' => []]], ['data' => ['orders' => []]]);

        $this->orders($client)->createQueryBuilder()->select('total.amount')->getQuery()->getResult();
        $this->orders($client)->createQueryBuilder()->select('price.amount')->getQuery()->getResult();

        self::assertSame('query { orders { id total_amount } }', self::flatten($client->queries[0]));
        self::assertSame('query { orders { id price { amount } } }', self::flatten($client->queries[1]));
    }

    #[Test]
    public function itFiltersOnAFieldOfAnEmbeddable(): void
    {
        $client = new FakeGraphqlClient(['data' => ['orders' => []]]);
        $qb = $this->orders($client)->createQueryBuilder();

        $qb->where($qb->expr()->andX($qb->expr()->gte('total.amount', 100), $qb->expr()->eq('price.currency', 'EUR')))->getQuery()->getResult();

        $query = self::flatten($client->lastQuery);
        self::assertStringContainsString('total_amount: { gte: 100 }', $query);
        self::assertStringContainsString('price: { currency: { eq: "EUR" } }', $query);
    }

    #[Test]
    public function itFindsAndOrdersByAFieldOfAPrefixedEmbeddable(): void
    {
        $client = new FakeGraphqlClient(['data' => ['orders' => ['items' => []]]]);

        $this->orders($client, new DataApiBuilderDialect())->findBy(['total.currency' => 'EUR'], ['total.amount' => Direction::Desc]);

        $query = self::flatten($client->lastQuery);
        self::assertStringContainsString('total_currency: { eq: "EUR" }', $query);
        self::assertStringContainsString('orderBy: { total_amount: DESC }', $query);
    }

    #[Test]
    public function itKeepsAnEmbeddableInTheSecondLevelCache(): void
    {
        $cache = new GraphqlCache(entities: new ArrayAdapter());
        $this->repository(CachedOrder::class, new FakeGraphqlClient(self::ORDERS), cache: $cache)->findAll();
        $client = new FakeGraphqlClient();

        $order = $this->repository(CachedOrder::class, $client, cache: $cache)->find(1);

        self::assertSame(0, $client->callCount);
        self::assertInstanceOf(CachedOrder::class, $order);
        self::assertEquals(new Money(1250, 'EUR'), $order->price);
        self::assertEquals(new Money(2500, 'EUR'), $order->total);
    }

    #[Test]
    public function itHydratesAnEmbeddableParameterOfADto(): void
    {
        $client = new FakeGraphqlClient(['data' => ['orders' => [
            ['id' => 1, 'total_amount' => 2500, 'total_currency' => 'EUR', 'price' => ['amount' => 1250]],
        ]]]);

        $summaries = $this->orders($client)->createQueryBuilder()->hydrateAs(OrderSummary::class)->getQuery()->getResult();

        self::assertSame('query { orders { id total_amount total_currency price { amount } } }', self::flatten($client->lastQuery));
        self::assertEquals([new OrderSummary(1, new Money(2500, 'EUR'), 1250)], $summaries);
    }

    #[Test]
    public function itRejectsAnEmbeddedClassThatIsNotEmbeddable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\Embedded\OrderWithNotEmbeddable::$price" is mapped with #[GraphqlOrm\\Embedded] but "GraphqlOrm\Tests\Fixtures\Embedded\NotEmbeddable" has no #[GraphqlOrm\\Embeddable].');

        (new GraphqlEntityMetadataFactory())->getMetadata(OrderWithNotEmbeddable::class);
    }

    private static function flatten(string $graphql): string
    {
        return (string) preg_replace('/\s+/', ' ', $graphql);
    }

    /**
     * @return GraphqlEntityRepository<Order>
     */
    private function orders(FakeGraphqlClient $client, ?GraphqlQueryDialect $dialect = null): GraphqlEntityRepository
    {
        return $this->repository(Order::class, $client, $dialect);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(string $class, FakeGraphqlClient $client, ?GraphqlQueryDialect $dialect = null, GraphqlCache $cache = new GraphqlCache()): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, $dialect ?? new DefaultDialect(), cache: $cache);

        return new GraphqlEntityRepository($manager, $class);
    }
}
