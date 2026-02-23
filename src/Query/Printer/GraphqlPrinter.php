<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Printer;

final class GraphqlPrinter
{
    private int $level = 0;
    private string $buffer = '';

    public function line(string $line): void
    {
        $this->buffer .= str_repeat('  ', $this->level) . $line . "\n";
    }

    public function indent(): void
    {
        ++$this->level;
    }

    public function outdent(): void
    {
        --$this->level;
    }

    public function get(): string
    {
        return rtrim($this->buffer, "\n");
    }
}
