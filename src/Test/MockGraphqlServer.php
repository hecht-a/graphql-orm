<?php

declare(strict_types=1);

namespace GraphqlOrm\Test;

use GraphqlOrm\Exception\LogicException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Stands for the GraphQL server in the tests of an application: it answers the queries with the responses queued, in
 * order, and records them. Set as the "http_client" of the bundle, the queries still go through the GraphQL client,
 * its error handling and the profiler.
 */
final class MockGraphqlServer implements HttpClientInterface
{
    private HttpClientInterface $client;

    /** @var list<MockResponse> */
    private array $responses = [];

    /** @var list<RecordedRequest> */
    private array $requests = [];

    public function __construct()
    {
        $this->client = new MockHttpClient($this->answer(...));
    }

    /**
     * Queues a response holding this data.
     *
     * @param array<mixed> $data the "data" of the response, keyed by root field
     */
    public function respond(array $data): self
    {
        return $this->queue(['data' => $data]);
    }

    /**
     * Queues a response holding these GraphQL errors, and the partial data given if any.
     *
     * @param list<array<mixed>> $errors e.g. [['message' => 'Forbidden']]
     * @param array<mixed>|null  $data
     */
    public function respondWithErrors(array $errors, ?array $data = null): self
    {
        return $this->queue(['data' => $data, 'errors' => $errors]);
    }

    /**
     * Queues an HTTP error, e.g. 503.
     */
    public function respondWithHttpStatus(int $statusCode): self
    {
        $this->responses[] = new MockResponse('', ['http_code' => $statusCode]);

        return $this;
    }

    /**
     * @return list<RecordedRequest> the requests received, in order
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $server = clone $this;
        $server->client = $this->client->withOptions($options);

        return $server;
    }

    /**
     * @param array<mixed> $body
     */
    private function queue(array $body): self
    {
        $this->responses[] = new MockResponse(json_encode($body, JSON_THROW_ON_ERROR));

        return $this;
    }

    /**
     * @param array<mixed> $options
     *
     * @throws LogicException when no response is queued
     */
    private function answer(string $method, string $url, array $options): MockResponse
    {
        $body = json_decode(\is_string($options['body'] ?? null) ? $options['body'] : '', true);
        $query = \is_array($body) && \is_string($body['query'] ?? null) ? $body['query'] : '';
        $variables = \is_array($body) && \is_array($body['variables'] ?? null) ? $body['variables'] : [];
        $this->requests[] = new RecordedRequest($query, $variables);

        return array_shift($this->responses)
            ?? throw new LogicException(\sprintf("The mock GraphQL server has no response left for the query:\n%s\nQueue one with respond().", $query));
    }
}
