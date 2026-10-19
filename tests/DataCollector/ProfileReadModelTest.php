<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DataCollector;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Tests\Fixtures\Cache\CachedTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything the profiler reads from the queries of a profile, on a request mixing find(), lazy loads, concurrent and
 * cached queries: what the timeline, the findings and the export show must not change unnoticed.
 */
final class ProfileReadModelTest extends TestCase
{
    private const EXPECTED = __DIR__ . '/../Fixtures/profile-read-model.json';

    #[Test]
    public function itReadsTheProfileAsBefore(): void
    {
        self::assertSame(
            json_decode((string) file_get_contents(self::EXPECTED), true, flags: JSON_THROW_ON_ERROR),
            json_decode(json_encode(self::readModel(self::profile()), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function readModel(GraphqlOrmDataCollector $collector): array
    {
        return [
            'offsets' => $collector->getQueryOffsets(),
            'total' => $collector->getTotalDuration(),
            'waited' => $collector->getWaitedDuration(),
            'span' => $collector->getTimelineSpan(),
            'ticks' => $collector->getTimelineTicks(),
            'repeated' => $collector->getRepeatedQueries(),
            'lazy_loads' => $collector->getLazyLoadsByRelation(),
            'by_caller' => $collector->getQueriesByCaller(),
            'cache_candidates' => $collector->getCacheCandidates(),
            'distinct' => $collector->getDistinctQueryCount(),
            'hydration' => $collector->getHydrationDuration(),
            'errors' => $collector->getErrorCount(),
            'export' => $collector->getExport(),
        ];
    }

    public static function profile(): GraphqlOrmDataCollector
    {
        $collector = new GraphqlOrmDataCollector(slowQueryThreshold: 40.0, maxQueries: 5, repeatedQueryThreshold: 2);
        $queries = [
            // A find() repeated in a loop, of an entity not cached: a findBy() and a cache candidate.
            ['query { task(id: 1) { id } }', 100.010, 12.0, 'find', FakeTask::class, 'TaskController.php', 18, null],
            ['query { task(id: 2) { id } }', 100.030, 45.0, 'find', FakeTask::class, 'TaskController.php', 18, null],
            // Lazy loads of one relation, two of them sent together.
            ['query { labels(id: 3) { id } }', 100.080, 20.0, 'lazy', FakeLabel::class, 'board.html.twig', 7, FakeTask::class . '::$label'],
            ['query { labels(id: 4) { id } }', 100.085, 25.0, 'lazy', FakeLabel::class, 'board.html.twig', 9, FakeTask::class . '::$label'],
            // An entity cached already: no candidate.
            ['query { cachedTask(id: 5) { id } }', 100.120, 3.0, 'find', CachedTask::class, 'TaskController.php', 30, null],
            // A query of the bundle itself, and one failing.
            ['query { __schema { types { name } } }', 100.130, 1.5, 'introspection', null, null, null, null],
            ['query { tasks { id } }', 100.140, 2.5, 'findBy', FakeTask::class, 'TaskController.php', 40, null],
        ];

        foreach ($queries as $number => [$graphql, $startedAt, $duration, $origin, $entity, $file, $line, $relation]) {
            $trace = new GraphqlQueryTrace();
            $trace->graphql = $graphql;
            $trace->startedAt = $startedAt;
            $trace->duration = $duration;
            $trace->hydrationDuration = 0.5;
            $trace->origin = $origin;
            $trace->entityClass = $entity;
            $trace->identifierProperty = $origin === 'find' ? 'id' : null;
            $trace->lazyRelation = $relation;
            $trace->manager = 'default';
            $trace->caller = $file !== null ? ['file' => $file, 'line' => $line, 'class' => null, 'function' => null] : null;
            $trace->responseSize = 100 + $number;
            $trace->hydratedEntities = 1;
            $trace->errors = $number === 6 ? [['message' => 'Failed']] : null;
            $collector->addQuery($trace);
        }

        $collector->collect(new Request(server: ['REQUEST_TIME_FLOAT' => 100.0]), new Response());

        return $collector;
    }
}
