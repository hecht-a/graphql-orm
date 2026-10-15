<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Client;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityQueryingOnRead;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class GraphqlClientCallerTest extends TestCase
{
    #[Test]
    public function itRecordsTheCallerRelativeToTheProjectDirectory(): void
    {
        $client = new GraphqlClient(
            new MockHttpClient(new MockResponse('{"data":{}}')),
            'https://graphql.example.com/graphql',
            projectDir: \dirname(__DIR__, 2),
        );
        $context = new GraphqlExecutionContext();

        $client->query('query { tasks { id } }', $context);

        self::assertSame('tests/Client/GraphqlClientCallerTest.php', $context->trace->caller['file'] ?? null);
        self::assertSame(__CLASS__, $context->trace->caller['class'] ?? null);
    }

    #[Test]
    public function itSkipsTheMethodsOfTheEntities(): void
    {
        $entity = new FakeEntityQueryingOnRead($this->client());

        (string) $entity;

        self::assertSame('tests/Client/GraphqlClientCallerTest.php', $entity->context->trace->caller['file'] ?? null);
        self::assertSame(__FUNCTION__, $entity->context->trace->caller['function'] ?? null);
    }

    #[Test]
    public function itNamesTheLineOfTheTwigTemplateReadingTheEntity(): void
    {
        $cache = sys_get_temp_dir() . '/graphql-orm-twig-' . bin2hex(random_bytes(4));
        $twig = new Environment(new ArrayLoader(['task.html.twig' => "<h1>Task</h1>\n\n<p>{{ entity }}</p>\n"]), ['cache' => $cache]);
        $entity = new FakeEntityQueryingOnRead($this->client());

        try {
            $twig->render('task.html.twig', ['entity' => $entity]);
        } finally {
            (new Filesystem())->remove($cache);
        }

        self::assertSame('task.html.twig', $entity->context->trace->caller['file'] ?? null);
        self::assertSame(3, $entity->context->trace->caller['line'] ?? null);
        self::assertNull($entity->context->trace->caller['class'] ?? null);
    }

    private function client(): GraphqlClient
    {
        return new GraphqlClient(new MockHttpClient(new MockResponse('{"data":{}}')), 'https://graphql.example.com/graphql', projectDir: \dirname(__DIR__, 2));
    }
}
