<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;

$check = static function (bool $condition, string $message) use (&$failed): void {
    if ($condition) {
        echo "OK   {$message}" . PHP_EOL;

        return;
    }

    echo "FAIL {$message}" . PHP_EOL;
    $failed = true;
};

$fail = static function (string $message) use (&$failed): void {
    echo "FAIL {$message}" . PHP_EOL;
    $failed = true;
};

$path = static fn (string $relative): string => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

$removeDirectory = static function (string $directory) use (&$removeDirectory): void {
    if (! is_dir($directory)) {
        return;
    }

    $items = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);

    foreach ($items as $item) {
        $path = $item->getPathname();

        if ($item->isDir() && ! $item->isLink()) {
            $removeDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
};

$normalizeEntries = static function (array $entries): array {
    $normalized = [];

    foreach ($entries as $entry) {
        $entry = str_replace('\\', '/', $entry);
        $entry = ltrim($entry, './');

        if ($entry === '') {
            continue;
        }

        $normalized[] = rtrim($entry, '/');
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized);

    return $normalized;
};

$entriesContain = static function (array $entries, string $expected): bool {
    $expected = trim(str_replace('\\', '/', $expected), '/');

    return in_array($expected, $entries, true);
};

$entriesContainPrefix = static function (array $entries, string $prefix): bool {
    $prefix = trim(str_replace('\\', '/', $prefix), '/') . '/';

    foreach ($entries as $entry) {
        if (str_starts_with($entry . '/', $prefix)) {
            return true;
        }
    }

    return false;
};

$archiveContainsPath = static function (array $entries, string $path) use ($entriesContain, $entriesContainPrefix): bool {
    $path = trim(str_replace('\\', '/', $path), '/');

    return $entriesContain($entries, $path) || $entriesContainPrefix($entries, $path);
};

$escapeArgument = static function (string $argument): string {
    if (PHP_OS_FAMILY !== 'Windows') {
        return escapeshellarg($argument);
    }

    return '"' . str_replace('"', '\"', $argument) . '"';
};

$resolveGit = static function (): string {
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'git';
    }

    $output = [];
    $exitCode = 1;
    @exec('where.exe git 2>NUL', $output, $exitCode);

    if ($exitCode === 0) {
        foreach ($output as $line) {
            $line = trim($line);

            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }
    }

    return 'git';
};

$runProcess = static function (array $arguments) use ($root, $escapeArgument): array {
    $command = implode(' ', array_map($escapeArgument, $arguments));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, $root);

    if (! is_resource($process)) {
        return [1, '', 'Unable to start process: ' . $command];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        proc_close($process),
        $stdout === false ? '' : $stdout,
        $stderr === false ? '' : $stderr,
    ];
};

$runGit = static function (array $arguments) use ($resolveGit, $runProcess): array {
    array_unshift($arguments, $resolveGit());

    return $runProcess($arguments);
};

$trackedEntriesFor = static function (string $relativePath) use ($runGit, $normalizeEntries): array {
    [$exitCode, $stdout] = $runGit(['ls-files', '--', $relativePath]);

    if ($exitCode !== 0) {
        return [];
    }

    return $normalizeEntries(preg_split('/\R/', trim($stdout)) ?: []);
};

$inspectTar = static function (string $archivePath) use ($normalizeEntries): array {
    $archive = new PharData($archivePath);
    $entries = [];
    $iterator = new RecursiveIteratorIterator($archive);

    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        $entries[] = $relative;
    }

    return $normalizeEntries($entries);
};

$requiredFiles = [
    'composer.json',
    'README.md',
    'CHANGELOG.md',
    'RELEASE.md',
    'SECURITY.md',
    'SUPPORT.md',
    'config/preview.php',
    'src/PreviewServiceProvider.php',
];

$devLocalDirectories = [
    '.git',
    '.github',
    '.preview-internal',
    '.agents',
    '.claude',
    '.codex',
    '.gemini',
    'docs/preview',
    'tests',
    'vendor',
    'storage',
    'fixtures',
    'local-fixtures',
    '.phpunit.cache',
    'coverage',
];

$devLocalFiles = [
    '.gitattributes',
    '.gitignore',
    'phpunit.xml',
    '.phpunit.result.cache',
    'AGENTS.md',
    'CLAUDE.md',
    'GEMINI.md',
];

$exportIgnoreProofPaths = [
    '.gitattributes',
    '.gitignore',
    '.github',
    'tests',
    'phpunit.xml',
];

$temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'preview-source-archive-check-' . bin2hex(random_bytes(6));
$archivePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'github-source.tar';

mkdir($temporaryDirectory, 0777, true);

try {
    $check(is_file($path('.gitattributes')), '.gitattributes exists');

    [$archiveExitCode, $archiveStdout, $archiveStderr] = $runGit([
        'archive',
        '--worktree-attributes',
        '--format=tar',
        '--output=' . $archivePath,
        'HEAD',
    ]);

    $check($archiveExitCode === 0, 'git archive --worktree-attributes produced a tar archive');

    if ($archiveExitCode !== 0) {
        $output = trim($archiveStderr . PHP_EOL . $archiveStdout);
        throw new RuntimeException($output !== '' ? $output : 'git archive failed without output.');
    }

    $check(is_file($archivePath), 'temporary GitHub source archive file exists');

    if (! is_file($archivePath)) {
        throw new RuntimeException('Archive file was not created: ' . $archivePath);
    }

    $entries = $inspectTar($archivePath);
    $check($entries !== [], 'source archive contains file entries');

    foreach ($requiredFiles as $requiredFile) {
        $check($entriesContain($entries, $requiredFile), 'source archive contains ' . $requiredFile);
    }

    foreach ($devLocalDirectories as $directory) {
        $check(! $entriesContainPrefix($entries, $directory), 'source archive excludes ' . $directory . '/');
    }

    foreach ($devLocalFiles as $file) {
        $check(! $entriesContain($entries, $file), 'source archive excludes ' . $file);
    }

    foreach ($exportIgnoreProofPaths as $exportIgnoredPath) {
        $trackedEntries = $trackedEntriesFor($exportIgnoredPath);

        $check($trackedEntries !== [], 'export-ignore proof path is tracked: ' . $exportIgnoredPath);
        $check(! $archiveContainsPath($entries, $exportIgnoredPath), 'export-ignore removes tracked path ' . $exportIgnoredPath);
    }
} catch (Throwable $exception) {
    $fail($exception->getMessage());
} finally {
    $removeDirectory($temporaryDirectory);
}

exit($failed ? 1 : 0);
