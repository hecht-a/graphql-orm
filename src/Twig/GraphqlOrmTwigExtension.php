<?php

declare(strict_types=1);

namespace GraphqlOrm\Twig;

use GraphqlOrm\DataCollector\GraphqlHighlighter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The Twig filters of the profiler panel, registered when Twig is installed.
 *
 * @internal
 */
final class GraphqlOrmTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly GraphqlHighlighter $highlighter = new GraphqlHighlighter(),
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('graphql_orm_highlight', $this->highlighter->highlight(...), ['is_safe' => ['html']]),
        ];
    }
}
