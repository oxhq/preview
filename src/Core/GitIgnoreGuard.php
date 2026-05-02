<?php

declare(strict_types=1);

namespace Oxhq\Preview\Core;

final class GitIgnoreGuard
{
    public function ensureIgnored(string $path): void
    {
        $root = $this->gitRootFor($path);

        if ($root === null) {
            return;
        }

        $relative = $this->relativePath($root, $path);

        if ($relative === null || $relative === '') {
            return;
        }

        $gitignore = $root.DIRECTORY_SEPARATOR.'.gitignore';
        $contents = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';

        if ($this->isCovered($relative, $contents)) {
            return;
        }

        $line = '/'.str_replace(DIRECTORY_SEPARATOR, '/', trim($relative, DIRECTORY_SEPARATOR)).'/';
        $prefix = $contents === '' || str_ends_with($contents, "\n") ? '' : PHP_EOL;
        $entry = $prefix.'# Laravel Preview local files'.PHP_EOL.$line.PHP_EOL;

        file_put_contents($gitignore, $entry, FILE_APPEND | LOCK_EX);
    }

    private function gitRootFor(string $path): ?string
    {
        $current = is_dir($path) ? $path : dirname($path);

        while ($current !== '' && $current !== dirname($current)) {
            if (is_dir($current.DIRECTORY_SEPARATOR.'.git')) {
                return $current;
            }

            $current = dirname($current);
        }

        return null;
    }

    private function relativePath(string $root, string $path): ?string
    {
        $root = rtrim($this->normalize($root), '/').'/';
        $path = rtrim($this->normalize($path), '/');

        if (! str_starts_with($path.'/', $root)) {
            return null;
        }

        return trim(substr($path, strlen($root)), '/');
    }

    private function isCovered(string $relative, string $contents): bool
    {
        $relative = trim($this->normalize($relative), '/').'/';

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $pattern = trim($line);

            if ($pattern === '' || str_starts_with($pattern, '#') || str_starts_with($pattern, '!')) {
                continue;
            }

            $pattern = trim($this->normalize($pattern), '/');

            if ($pattern === '') {
                continue;
            }

            $pattern = rtrim($pattern, '/').'/';

            if ($relative === $pattern || str_starts_with($relative, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
