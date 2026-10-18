<?php

declare(strict_types=1);

namespace GraphqlOrm\Controller;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Sends again a query of a profile, from the Replay button of the profiler: its response once the API or its data
 * changed, without replaying the whole page. Only while developing, for a query read with its values, and never a
 * mutation, which would change the data again.
 *
 * @internal
 */
final class ProfilerReplayController
{
    /** The path of the route of config/routes.php, followed by the token of the profile and the number of the query. */
    public const PATH = '/_graphql_orm/replay';

    private const COLLECTOR = 'graphql_orm';

    public function __construct(
        private readonly GraphqlManagerRegistry $managers,
        private readonly ?Profiler $profiler,
        private readonly bool $debug,
    ) {
    }

    /**
     * @param int $number the number of the query in the profile, from 1 as the profiler shows it
     *
     * @throws NotFoundHttpException   outside the debug mode, or for a query the profile does not detail
     * @throws BadRequestHttpException for a query the profile cannot send again
     */
    public function __invoke(Request $request, string $token, int $number): JsonResponse
    {
        if (!$this->debug || $this->profiler === null) {
            throw new NotFoundHttpException('The queries of a profile are replayed while developing only.');
        }

        // A header a cross-site form cannot send: only the profiler replays a query.
        if (!$request->isXmlHttpRequest()) {
            throw new BadRequestHttpException('A query is replayed from the profiler.');
        }

        $profile = $this->profiler->loadProfile($token);
        $collector = $profile !== null && $profile->hasCollector(self::COLLECTOR) ? $profile->getCollector(self::COLLECTOR) : null;
        $query = $collector instanceof GraphqlOrmDataCollector ? $collector->getQueries()[$number - 1] ?? null : null;

        if ($query === null || ($query['detailed'] ?? true) !== true || !\is_string($query['graphql'] ?? null)) {
            throw new NotFoundHttpException(\sprintf('The profile "%s" does not detail a query #%d.', $token, $number));
        }

        if (($query['redacted'] ?? true) !== false) {
            throw new BadRequestHttpException('The profile masks the values of the query: set redact_query_values to false to replay it.');
        }

        if (preg_match('/^\s*(mutation|subscription)\b/i', $query['graphql']) === 1) {
            throw new BadRequestHttpException('A mutation is not replayed: it would change the data again.');
        }

        $manager = $this->managers->getManager(\is_string($query['manager'] ?? null) ? $query['manager'] : null);
        $variables = \is_array($query['variables'] ?? null) ? array_filter($query['variables'], \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
        $startedAt = hrtime(true);

        try {
            $response = $manager->execute($query['graphql'], static fn (array $response): array => $response, variables: $variables);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['response' => $response, 'duration' => (hrtime(true) - $startedAt) / 1e6]);
    }
}
