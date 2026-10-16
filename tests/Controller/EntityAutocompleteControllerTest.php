<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Controller;

use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The search of an autocomplete field: the entities whose fields contain the query, as Symfony UX Autocomplete expects.
 */
final class EntityAutocompleteControllerTest extends TestCase
{
    private FakeGraphqlClient $client;

    protected function setUp(): void
    {
        $this->client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 2, 'title' => 'Fix the API'], ['id' => 7, 'title' => 'Fix the export']]]]]);
    }

    #[Test]
    public function itAnswersTheSearchOfASignedUrl(): void
    {
        $url = $this->signer()->sign(GraphqlEntityType::AUTOCOMPLETE_PATH . '?' . http_build_query(['class' => FakeTask::class, 'fields' => 'title', 'label' => 'title']));

        $response = $this->controller()(Request::create($url . '&query=Fix'));

        self::assertSame(
            ['results' => [['value' => '2', 'text' => 'Fix the API'], ['value' => '7', 'text' => 'Fix the export']], 'next_page' => null],
            json_decode((string) $response->getContent(), true),
        );
        self::assertStringContainsString('title: { contains: "Fix" }', $this->client->lastQuery);
    }

    #[Test]
    public function itGivesTheNumberOfResultsTheFieldAsksFor(): void
    {
        $url = $this->signer()->sign(GraphqlEntityType::AUTOCOMPLETE_PATH . '?' . http_build_query(['class' => FakeTask::class, 'fields' => 'title', 'max' => 5]));

        $this->controller()(Request::create($url . '&query=Fix'));

        self::assertStringContainsString('first: 5', $this->client->lastQuery);
    }

    #[Test]
    public function itTellsTheProfilerTheSearch(): void
    {
        $collector = new GraphqlOrmDataCollector(configuration: ['redact_query_values' => false]);
        $url = $this->signer()->sign(GraphqlEntityType::AUTOCOMPLETE_PATH . '?' . http_build_query(['class' => FakeTask::class, 'fields' => 'title']));

        $this->controller($collector)(Request::create($url . '&query=Fix'));

        self::assertSame('autocomplete', $collector->getQueries()[0]['origin'] ?? null);
        self::assertSame([['class' => FakeTask::class, 'fields' => ['title'], 'query' => 'Fix', 'results' => 2, 'limit' => 20]], $collector->getAutocompleteSearches());
    }

    #[Test]
    public function itMasksTheSearchedTextInTheProfilerAsTheValuesOfTheQueries(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $url = $this->signer()->sign(GraphqlEntityType::AUTOCOMPLETE_PATH . '?' . http_build_query(['class' => FakeTask::class, 'fields' => 'title']));

        $this->controller($collector)(Request::create($url . '&query=Fix'));

        self::assertSame('***', $collector->getAutocompleteSearches()[0]['query']);
    }

    #[Test]
    public function itRefusesASearchItDidNotSign(): void
    {
        $url = GraphqlEntityType::AUTOCOMPLETE_PATH . '?' . http_build_query(['class' => FakeTask::class, 'fields' => 'id']);

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller()(Request::create($url . '&query=1'));
    }

    private function controller(?GraphqlOrmDataCollector $collector = null): EntityAutocompleteController
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $this->client, new EntityHydrator($metadataFactory), $collector, 2, new DataApiBuilderDialect());

        return new EntityAutocompleteController($manager, $this->signer(), $collector);
    }

    private function signer(): UriSigner
    {
        return new UriSigner('secret');
    }
}
