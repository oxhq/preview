<?php

declare(strict_types=1);

namespace Oxhq\Preview\Tests\Core;

use PHPUnit\Framework\TestCase;

final class PublicSurfaceTest extends TestCase
{
    public function test_internal_planning_and_agent_files_are_not_tracked(): void
    {
        $tracked = $this->trackedFiles();

        $forbidden = [
            '.agents/',
            '.claude/',
            '.codex/',
            '.gemini/',
            '.preview-internal/',
            'docs/preview/',
            'AGENTS.md',
            'CLAUDE.md',
            'GEMINI.md',
        ];

        $leaked = array_values(array_filter(
            $tracked,
            static fn (string $file): bool => self::matchesForbiddenPublicPath($file, $forbidden),
        ));

        self::assertSame([], $leaked, 'Internal planning or agent files are tracked in the public package surface.');
    }

    /**
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $output = [];
        $exitCode = 1;

        exec('git ls-files', $output, $exitCode);

        self::assertSame(0, $exitCode, 'Could not inspect tracked files with git ls-files.');

        return array_values(array_filter(
            array_map(static fn (string $file): string => str_replace('\\', '/', trim($file)), $output),
            static fn (string $file): bool => $file !== '',
        ));
    }

    /**
     * @param list<string> $forbidden
     */
    private static function matchesForbiddenPublicPath(string $file, array $forbidden): bool
    {
        foreach ($forbidden as $path) {
            if (str_ends_with($path, '/') && str_starts_with($file, $path)) {
                return true;
            }

            if ($file === $path) {
                return true;
            }
        }

        return false;
    }
}
