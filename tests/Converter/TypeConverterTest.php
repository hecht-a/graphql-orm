<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Converter;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Tests\Fixtures\Converter\CachedProduct;
use GraphqlOrm\Tests\Fixtures\Converter\Email;
use GraphqlOrm\Tests\Fixtures\Converter\EmailConverter;
use GraphqlOrm\Tests\Fixtures\Converter\Money;
use GraphqlOrm\Tests\Fixtures\Converter\MoneyConverter;
use GraphqlOrm\Tests\Fixtures\Converter\Product;
use GraphqlOrm\Tests\Fixtures\Converter\ProductSummary;
use GraphqlOrm\Tests\Fixtures\Converter\ProductWithUnregisteredConverter;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A type converter turns a scalar of the API into a value object, and back in the filters.
 */
final class TypeConverterTest extends TestCase
{
    private const PRODUCTS = ['data' => ['products' => [
        ['id' => 1, 'name' => 'Product A', 'price' => 1250, 'discount' => 2.5, 'contact' => 'user.a@example.com'],
        ['id' => 2, 'name' => 'Product B', 'price' => null, 'discount' => null, 'contact' => null],
    ]]];

    #[Test]
    public function itHydratesAValueObjectWithTheConverterOfItsType(): void
    {
        $products = $this->repository(new FakeGraphqlClient(self::PRODUCTS))->findAll();

        self::assertEquals(new Money(1250), $products[0]->price);
        self::assertEquals(new Email('user.a@example.com'), $products[0]->contact);
    }

    #[Test]
    public function itUsesTheConverterNamedByTheField(): void
    {
        $products = $this->repository(new FakeGraphqlClient(self::PRODUCTS))->findAll();

        self::assertEquals(new Money(250), $products[0]->discount);
    }

    #[Test]
    public function itLeavesNullAsNull(): void
    {
        $product = $this->repository(new FakeGraphqlClient(self::PRODUCTS))->findAll()[1];

        self::assertNull($product->price);
        self::assertNull($product->discount);
        self::assertNull($product->contact);
    }

    #[Test]
    public function itConvertsTheValuesOfAFilter(): void
    {
        $client = new FakeGraphqlClient(self::PRODUCTS);
        $qb = $this->repository($client)->createQueryBuilder();

        $qb->where($qb->expr()->andX(
            $qb->expr()->in('price', [new Money(1250), new Money(990)]),
            $qb->expr()->eq('discount', new Money(250)),
            $qb->expr()->eq('contact', new Email('user.a@example.com')),
        ))->getQuery()->getResult();

        $query = (string) preg_replace('/\s+/', ' ', $client->lastQuery);
        self::assertStringContainsString('price: { in: [1250, 990] }', $query);
        self::assertStringContainsString('discount: { eq: 2.5 }', $query);
        self::assertStringContainsString('contact: { eq: "user.a@example.com" }', $query);
    }

    #[Test]
    public function itConvertsTheValuesOfTheCriteria(): void
    {
        $client = new FakeGraphqlClient(self::PRODUCTS);

        $this->repository($client)->findBy(['price' => new Money(1250), 'discount' => new Money(250)]);

        self::assertStringContainsString('products(price: 1250, discount: 2.5)', $client->lastQuery);
    }

    #[Test]
    public function itSendsTheConvertedValuesAsVariables(): void
    {
        $introspection = ['data' => ['__schema' => ['queryType' => ['fields' => [
            ['name' => 'products', 'args' => [['name' => 'price', 'type' => ['kind' => 'SCALAR', 'name' => 'Int', 'ofType' => null]]]],
        ]]]]];
        $client = new FakeGraphqlClient($introspection, self::PRODUCTS);

        $this->repository($client, withVariables: true)->findBy(['price' => new Money(1250)]);

        self::assertSame(['price' => 1250], $client->lastVariables);
    }

    #[Test]
    public function itKeysTheResultByAValueObjectConverted(): void
    {
        $products = $this->repository(new FakeGraphqlClient(['data' => ['products' => [
            ['id' => 1, 'name' => 'Product A', 'price' => null, 'discount' => null, 'contact' => 'user.a@example.com'],
        ]]]))->findBy([], indexBy: 'contact');

        self::assertSame(['user.a@example.com'], array_keys($products));
    }

    #[Test]
    public function itHydratesTheParametersOfADto(): void
    {
        $summaries = $this->repository(new FakeGraphqlClient(self::PRODUCTS))->createQueryBuilder()
            ->hydrateAs(ProductSummary::class)
            ->getQuery()
            ->getResult();

        self::assertEquals(
            [new ProductSummary('Product A', new Money(1250), new Money(250)), new ProductSummary('Product B', null)],
            $summaries,
        );
    }

    #[Test]
    public function itConvertsTheEntitiesReadFromTheSecondLevelCache(): void
    {
        $cache = new GraphqlCache(entities: new ArrayAdapter());
        $this->cachedProducts(new FakeGraphqlClient(['data' => ['products' => [['id' => 1, 'price' => 1250, 'discount' => 2.5]]]]), $cache)->findAll();
        $client = new FakeGraphqlClient();

        $product = $this->cachedProducts($client, $cache)->find(1);

        self::assertSame(0, $client->callCount);
        self::assertInstanceOf(CachedProduct::class, $product);
        self::assertEquals(new Money(1250), $product->price);
        self::assertEquals(new Money(250), $product->discount);
    }

    #[Test]
    public function itFailsForAConverterThatIsNeitherRegisteredNorCreatable(): void
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, new FakeGraphqlClient(['data' => ['products' => [['id' => 1, 'name' => 'Product A']]]]), new EntityHydrator($metadataFactory), null, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The type converter "GraphqlOrm\Tests\Fixtures\Converter\ConfiguredConverter" is not registered');

        (new GraphqlEntityRepository($manager, ProductWithUnregisteredConverter::class))->findAll();
    }

    /**
     * A repository of a manager of its own, as in another request: only the second-level cache is shared.
     *
     * @return GraphqlEntityRepository<CachedProduct>
     */
    private function cachedProducts(FakeGraphqlClient $client, GraphqlCache $cache): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            1,
            cache: $cache,
            typeConverters: new TypeConverterRegistry([new MoneyConverter()]),
        );

        return new GraphqlEntityRepository($manager, CachedProduct::class);
    }

    /**
     * @return GraphqlEntityRepository<Product>
     */
    private function repository(FakeGraphqlClient $client, bool $withVariables = false): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            1,
            argumentTypeResolver: $withVariables ? new RootArgumentTypeResolver($client, new ArrayAdapter(), 3600) : null,
            typeConverters: new TypeConverterRegistry([new MoneyConverter(), new EmailConverter()]),
        );

        return new GraphqlEntityRepository($manager, Product::class);
    }
}
