<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Controller;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Controller\ProfilerReplayController;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Sends again a query of a profile, while developing, to compare its response once the API or the data changed.
 */
final class ProfilerReplayControllerTest extends TestCase
{
    private const TOKEN = 'a1b2c3';

    private FakeGraphqlClient $client;
    private string $directory;

    protected function setUp(): void
    {
        $this->client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 2, 'title' => 'Task A']]]]]);
        $this->directory = sys_get_temp_dir() . '/graphql-orm-replay-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new FileProfilerStorage('file:' . $this->directory))->purge();
    }

    #[Test]
    public function itSendsTheQueryOfTheProfileAgain(): void
    {
        $this->profile(self::trace('query ($title: String) { tasks(filter: { title: { eq: $title } }) { items { id title } } }', ['title' => 'Task A']));

        $response = $this->controller()(self::request(), self::TOKEN, 1);
        $content = json_decode((string) $response->getContent(), true);

        self::assertIsArray($content);
        self::assertSame(['data' => ['tasks' => ['items' => [['id' => 2, 'title' => 'Task A']]]]], $content['response'] ?? null);
        self::assertIsFloat($content['duration'] ?? null);
        self::assertStringContainsString('tasks(filter: { title: { eq: $title } })', $this->client->lastQuery);
        self::assertSame(['title' => 'Task A'], $this->client->lastVariables);
    }

    #[Test]
    public function itTellsTheErrorOfTheReplay(): void
    {
        $this->profile(self::trace('query { tasks { items { id } } }'));
        $controller = $this->controller(new class implements GraphqlClientInterface {
            public function query(string $query, GraphqlExecutionContext $context, array $variables = []): array
            {
                throw new GraphqlRequestException('The API is down.');
            }
        });

        $response = $controller(self::request(), self::TOKEN, 1);

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('The API is down.', (string) $response->getContent());
    }

    #[Test]
    public function itRefusesAQueryWhoseValuesAreMasked(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $this->client, new EntityHydrator($metadataFactory), $collector, 2, new DataApiBuilderDialect(), redactor: new QueryRedactor());
        $manager->execute('query { tasks(filter: { title: { eq: "Task A" } }) { items { id } } }', static fn (array $response): array => $response);
        $this->save($collector);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('redact_query_values');

        $this->controller()(self::request(), self::TOKEN, 1);
    }

    #[Test]
    public function itRefusesAMutation(): void
    {
        $this->profile(self::trace('mutation { createTask(item: { title: "Task A" }) { id } }'));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('mutation');

        $this->controller()(self::request(), self::TOKEN, 1);
    }

    #[Test]
    public function itRefusesAQueryTheProfileDoesNotDetail(): void
    {
        $this->profile(self::trace('query { tasks { items { id } } }'));

        $this->expectException(NotFoundHttpException::class);

        $this->controller()(self::request(), self::TOKEN, 2);
    }

    #[Test]
    public function itRefusesARequestNotSentByTheProfiler(): void
    {
        $this->profile(self::trace('query { tasks { items { id } } }'));

        $this->expectException(BadRequestHttpException::class);

        $this->controller()(Request::create('/', 'POST'), self::TOKEN, 1);
    }

    #[Test]
    public function itDoesNotExistOutsideTheDebugMode(): void
    {
        $this->profile(self::trace('query { tasks { items { id } } }'));

        $this->expectException(NotFoundHttpException::class);

        $this->controller(debug: false)(self::request(), self::TOKEN, 1);
    }

    #[Test]
    public function itDoesNotExistWithoutTheProfiler(): void
    {
        $controller = new ProfilerReplayController(GraphqlManagerRegistry::forManager($this->manager()), null, true);

        $this->expectException(NotFoundHttpException::class);

        $controller(self::request(), self::TOKEN, 1);
    }

    private function profile(GraphqlQueryTrace $trace): void
    {
        $collector = new GraphqlOrmDataCollector();
        $collector->addQuery($trace);
        $this->save($collector);
    }

    private function save(GraphqlOrmDataCollector $collector): void
    {
        $profile = new Profile(self::TOKEN);
        $profile->setMethod('GET');
        $profile->setUrl('/tasks');
        $profile->setIp('127.0.0.1');
        $profile->setTime(time());
        $profile->setStatusCode(200);
        $profile->addCollector($collector);
        $this->profiler()->saveProfile($profile);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function trace(string $graphql, array $variables = []): GraphqlQueryTrace
    {
        $trace = new GraphqlQueryTrace();
        $trace->graphql = $graphql;
        $trace->variables = $variables;
        $trace->manager = 'default';

        return $trace;
    }

    private static function request(): Request
    {
        return Request::create('/_graphql_orm/replay/' . self::TOKEN . '/1', 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function controller(?GraphqlClientInterface $client = null, bool $debug = true): ProfilerReplayController
    {
        return new ProfilerReplayController(GraphqlManagerRegistry::forManager($this->manager($client)), $this->profiler(), $debug);
    }

    private function manager(?GraphqlClientInterface $client = null): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client ?? $this->client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect(), redactor: new QueryRedactor());
    }

    private function profiler(): Profiler
    {
        return new Profiler(new FileProfilerStorage('file:' . $this->directory));
    }
}
