<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

/**
 * When the queries of a profile ran: their offsets in the request, the time the request waited for the API, and the
 * graduations of the timeline of the profiler.
 *
 * @internal
 */
final readonly class ProfileTimeline
{
    /** The graduations the timeline is divided in, roughly. */
    private const TICKS = 4;

    /**
     * @param array<int, array<string, mixed>> $queries
     * @param float|null                       $requestStartedAt the start of the request, in seconds; unknown, the
     *                                                           timeline starts with the first query
     */
    public function __construct(
        private array $queries,
        private ?float $requestStartedAt,
    ) {
    }

    /**
     * When each query started, in milliseconds since the request started, or since the first query when unknown.
     *
     * @return list<float>
     */
    public function offsets(): array
    {
        $startedAts = array_map(fn (array $query): ?float => \is_float($query['started_at'] ?? null) ? $query['started_at'] : null, $this->queries);
        $known = array_filter($startedAts, fn (?float $startedAt): bool => $startedAt !== null);
        $origin = $this->requestStartedAt ?? ($known !== [] ? min($known) : null);

        return array_values(array_map(
            fn (?float $startedAt): float => $startedAt !== null && \is_float($origin) ? max(0.0, ($startedAt - $origin) * 1000) : 0.0,
            $startedAts,
        ));
    }

    public function totalDuration(): float
    {
        $total = 0.0;

        foreach ($this->queries as $query) {
            $total += ProfiledQuery::milliseconds($query, 'duration');
        }

        return $total;
    }

    /**
     * The milliseconds the request waited for the API: the queries sent together (GraphqlQuery::concurrently(), the
     * concurrent lazy loads) counted once, unlike totalDuration(). Their sum when the start of a query is unknown.
     */
    public function waitedDuration(): float
    {
        $intervals = [];

        foreach ($this->offsets() as $index => $offset) {
            if (!\is_float($this->queries[$index]['started_at'] ?? null)) {
                return $this->totalDuration();
            }

            $intervals[] = [$offset, $offset + ProfiledQuery::milliseconds($this->queries[$index], 'duration')];
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $waited = 0.0;
        $end = null;

        foreach ($intervals as [$start, $stop]) {
            if ($end === null || $start > $end) {
                $waited += $stop - $start;
                $end = $stop;
            } elseif ($stop > $end) {
                $waited += $stop - $end;
                $end = $stop;
            }
        }

        return $waited;
    }

    /**
     * @return float the milliseconds from the start of the timeline to the end of the last query, its hydration included
     */
    public function span(): float
    {
        $span = 0.0;

        foreach ($this->offsets() as $index => $offset) {
            $query = $this->queries[$index];
            $span = max($span, $offset + ProfiledQuery::milliseconds($query, 'duration') + ProfiledQuery::milliseconds($query, 'hydration_duration'));
        }

        return $span;
    }

    /**
     * The graduations of the timeline, in milliseconds: about four, a step of 1, 2, 2.5 or 5 times a power of ten.
     *
     * @return list<float>
     */
    public function ticks(): array
    {
        $span = $this->span();

        if ($span <= 0) {
            return [0.0];
        }

        $rough = $span / self::TICKS;
        $power = 10 ** floor(log10($rough));
        $step = $power * match (true) {
            $rough / $power <= 1 => 1,
            $rough / $power <= 2 => 2,
            $rough / $power <= 2.5 => 2.5,
            $rough / $power <= 5 => 5,
            default => 10,
        };
        $ticks = [];

        for ($tick = 0.0; $tick <= $span; $tick += $step) {
            $ticks[] = round($tick, 6);
        }

        return $ticks;
    }
}
