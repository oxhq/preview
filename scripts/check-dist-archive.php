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

    $topLevelDirectories = [];
    foreach ($normalized as $entry) {
        if (str_contains($entry, '/')) {
            $topLevelDirectories[strstr($entry, '/', true)] = true;
        }
    }

    if (count($topLevelDirectories) === 1) {
        $prefix = array_key_first($topLevelDirectories) . '/';
        $stripped = [];

        foreach ($normalized as $entry) {
            if ($entry === rtrim($prefix, '/')) {
                continue;
            }

            $stripped[] = str_starts_with($entry, $prefix) ? substr($entry, strlen($prefix)) : $entry;
        }

        $normalized = array_values(array_unique(array_filter($stripped, static fn (string $entry): bool => $entry !== '')));
        sort($normalized);
    }

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

$readArchiveFile = static function (array $files, string $path): ?string {
    $path = trim(str_replace('\\', '/', $path), '/');

    foreach ($files as $file => $contents) {
        if ($file === $path) {
            return $contents;
        }
    }

    return null;
};

$escapeArgument = static function (string $argument): string {
    if (PHP_OS_FAMILY !== 'Windows') {
        return escapeshellarg($argument);
    }

    return '"' . str_replace('"', '\"', $argument) . '"';
};

$resolveComposer = static function (): string {
    if (PHP_OS_FAMILY !== 'Windows') {
        return 'composer';
    }

    $output = [];
    $exitCode = 1;
    @exec('where.exe composer 2>NUL', $output, $exitCode);

    if ($exitCode === 0) {
        foreach ($output as $line) {
            $line = trim($line);

            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }
    }

    return 'composer';
};

$runComposerArchive = static function (string $format, string $temporaryDirectory) use ($root, $escapeArgument, $resolveComposer): array {
    $command = implode(' ', array_map($escapeArgument, [
        $resolveComposer(),
        'archive',
        '--no-interaction',
        '--format=' . $format,
        '--dir=' . $temporaryDirectory,
        '--file=preview-dist',
    ]));

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, $root);

    if (! is_resource($process)) {
        return [1, '', 'Unable to start composer archive process.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
};

$inspectZip = static function (string $archivePath) use ($normalizeEntries): array {
    $zip = new ZipArchive();
    $opened = $zip->open($archivePath);

    if ($opened !== true) {
        throw new RuntimeException('Unable to open zip archive: ' . $archivePath);
    }

    $entries = [];
    $files = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);

        if ($name === false) {
            continue;
        }

        $normalized = str_replace('\\', '/', ltrim($name, './'));
        $entries[] = $normalized;

        if (! str_ends_with($normalized, '/')) {
            $contents = $zip->getFromIndex($index);

            if ($contents !== false) {
                $files[$normalized] = $contents;
            }
        }
    }

    $zip->close();

    $entries = $normalizeEntries($entries);
    $files = array_combine($normalizeEntries(array_keys($files)), array_values($files));

    return [$entries, $files === false ? [] : $files];
};

$inspectTar = static function (string $archivePath) use ($normalizeEntries): array {
    $archive = new PharData($archivePath);
    $entries = [];
    $files = [];

    $iterator = new RecursiveIteratorIterator($archive);

    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', $iterator->getSubPathName());
        $entries[] = $relative;

        if ($file->isFile()) {
            $contents = file_get_contents($file->getPathname());

            if ($contents !== false) {
                $files[$relative] = $contents;
            }
        }
    }

    $entries = $normalizeEntries($entries);
    $files = array_combine($normalizeEntries(array_keys($files)), array_values($files));

    return [$entries, $files === false ? [] : $files];
};

$requiredFiles = [
    'composer.json',
    'README.md',
    'CHANGELOG.md',
    'RELEASE.md',
    'config/preview.php',
    'src/PreviewServiceProvider.php',
];

$excludedDirectories = [
    'tests',
    'vendor',
    '.git',
    '.github',
    '.preview-internal',
    '.phpunit.cache',
];

$excludedFiles = [
    '.gitattributes',
    '.gitignore',
    'phpunit.xml',
    '.phpunit.result.cache',
];

$temporaryDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'preview-dist-check-' . bin2hex(random_bytes(6));
mkdir($temporaryDirectory, 0777, true);

try {
    $format = class_exists(ZipArchive::class) ? 'zip' : 'tar';
    $archivePath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'preview-dist.' . $format;

    [$exitCode, $stdout, $stderr] = $runComposerArchive($format, $temporaryDirectory);
    $check($exitCode === 0, 'composer archive produced a ' . $format . ' archive');

    if ($exitCode !== 0) {
        $output = trim($stderr . PHP_EOL . $stdout);
        throw new RuntimeException($output !== '' ? $output : 'composer archive failed without output.');
    }

    $check(is_file($archivePath), 'archive file exists at deterministic name preview-dist.' . $format);

    if (! is_file($archivePath)) {
        throw new RuntimeException('Archive file was not created: ' . $archivePath);
    }

    [$entries, $files] = $format === 'zip' ? $inspectZip($archivePath) : $inspectTar($archivePath);
    $check($entries !== [], 'archive contains file entries');

    foreach ($requiredFiles as $requiredFile) {
        $check($entriesContain($entries, $requiredFile), 'dist contains ' . $requiredFile);
    }

    foreach ($excludedDirectories as $excludedDirectory) {
        $check(! $entriesContainPrefix($entries, $excludedDirectory), 'dist excludes ' . $excludedDirectory . '/');
    }

    foreach ($excludedFiles as $excludedFile) {
        $check(! $entriesContain($entries, $excludedFile), 'dist excludes ' . $excludedFile);
    }

    $phpunitCacheFiles = array_filter(
        $entries,
        static fn (string $entry): bool => str_starts_with(basename($entry), '.phpunit')
    );
    $check($phpunitCacheFiles === [], 'dist excludes .phpunit cache files');

    $composerJson = $readArchiveFile($files, 'composer.json');
    $check($composerJson !== null, 'dist composer.json can be read');

    if ($composerJson !== null) {
        $composer = json_decode($composerJson, true);

        $check(json_last_error() === JSON_ERROR_NONE && is_array($composer), 'dist composer.json is valid JSON');
        $check(($composer['name'] ?? null) === 'oxhq/preview', 'dist composer.json package name is oxhq/preview');
    } else {
        $check(false, 'dist composer.json is valid JSON');
        $check(false, 'dist composer.json package name is oxhq/preview');
    }
} catch (Throwable $exception) {
    $fail($exception->getMessage());
} finally {
    $removeDirectory($temporaryDirectory);
}

exit($failed ? 1 : 0);
