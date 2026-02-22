<?php

declare(strict_types=1);

namespace GraphqlOrm\Codegen;

use Symfony\Component\Filesystem\Exception\FileNotFoundException;

final readonly class StubRenderer
{
    public function __construct(
        private string $stubsDir,
    ) {
    }

    /**
     * @param array<string, string> $vars
     */
    public function render(string $stubFile, array $vars): string
    {
        $path = rtrim($this->stubsDir, '/') . '/' . $stubFile;

        if (!is_file($path)) {
            throw new FileNotFoundException(\sprintf('Stub not found: %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(\sprintf('Unable to read stub: %s', $path));
        }

        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{ ' . $key . ' }}'] = $value;
        }

        return strtr($content, $replacements);
    }
}
