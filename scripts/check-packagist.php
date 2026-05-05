<?php

declare(strict_types=1);

const PACKAGE_NAME = 'oxhq/preview';
const PACKAGE_URL = 'https://packagist.org/packages/oxhq/preview';
const METADATA_URL = 'https://repo.packagist.org/p2/oxhq/preview.json';
const SUBMIT_URL = 'https://packagist.org/packages/submit';
const TIMEOUT_SECONDS = 5;

$requestedVersion = null;
$packageOnly = false;
$waitSeconds = 0;
$intervalSeconds = 15;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--package-only') {
        $packageOnly = true;

        continue;
    }

    if (str_starts_with($argument, '--wait=')) {
        $waitSeconds = max(0, (int) substr($argument, strlen('--wait=')));

        continue;
    }

    if ($argument === '--wait') {
        $waitSeconds = 300;

        continue;
    }

    if (str_starts_with($argument, '--interval=')) {
        $intervalSeconds = max(1, (int) substr($argument, strlen('--interval=')));

        continue;
    }

    if (str_starts_with($argument, '-')) {
        echo "FAIL Unknown option {$argument}" . PHP_EOL;
        exit(2);
    }

    if ($requestedVersion !== null) {
        echo 'FAIL Expected at most one version argument.' . PHP_EOL;
        exit(2);
    }

    $requestedVersion = $argument;
}

if ($packageOnly && $requestedVersion !== null) {
    echo 'FAIL --package-only cannot be combined with a version argument.' . PHP_EOL;
    exit(2);
}

$fail = static function (string $message, string $status = 'failed', int $exitCode = 1): never {
    echo "FAIL {$message}" . PHP_EOL;
    echo "STATUS {$status}" . PHP_EOL;
    exit($exitCode);
};

$ok = static function (string $message): void {
    echo "OK   {$message}" . PHP_EOL;
};

$normalizeVersion = static function (string $version): string {
    if (preg_match('/^\d/', $version) === 1) {
        return 'v' . $version;
    }

    return $version;
};

$readPackagistMetadata = static function () use ($fail): array {
    $context = stream_context_create([
        'http' => [
            'timeout' => TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'header' => [
                'Accept: application/json',
                'User-Agent: oxhq-preview-packagist-check/1.0',
            ],
        ],
    ]);

    $body = @file_get_contents(METADATA_URL, false, $context);
    $statusCode = null;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $statusCode = (int) $matches[1];
            break;
        }
    }

    if ($body === false) {
        $lastError = error_get_last();
        $detail = isset($lastError['message']) ? ': ' . $lastError['message'] : '';

        $fail('Packagist metadata request failed; network may be unavailable' . $detail, 'network-error');
    }

    if ($statusCode === 404) {
        return [
            'published' => false,
            'versions' => [],
            'statusCode' => $statusCode,
        ];
    }

    if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
        $fail('Packagist metadata request returned HTTP ' . $statusCode . ' for ' . METADATA_URL, 'metadata-error');
    }

    $metadata = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($metadata)) {
        $fail('Packagist metadata response was not valid JSON: ' . json_last_error_msg(), 'metadata-error');
    }

    $versions = $metadata['packages'][PACKAGE_NAME] ?? null;

    if (! is_array($versions) || $versions === []) {
        return [
            'published' => false,
            'versions' => [],
            'statusCode' => $statusCode,
        ];
    }

    $versionNames = [];
    foreach ($versions as $version) {
        if (is_array($version) && isset($version['version']) && is_string($version['version'])) {
            $versionNames[] = $version['version'];
        }
    }

    return [
        'published' => $versionNames !== [],
        'versions' => $versionNames,
        'statusCode' => $statusCode,
    ];
};

$printPackagistNextSteps = static function (string $target): void {
    echo 'URL  ' . PACKAGE_URL . PHP_EOL;
    echo 'NEXT Confirm the GitHub repository webhook or Packagist package update for ' . $target . '.' . PHP_EOL;
    echo 'NEXT If the package has never been submitted, submit it at ' . SUBMIT_URL . '.' . PHP_EOL;
};

$deadline = time() + $waitSeconds;
$attempt = 0;

do {
    $attempt++;
    $state = $readPackagistMetadata();
    $versions = $state['versions'];

    if (! $state['published']) {
        if (time() >= $deadline) {
            $printPackagistNextSteps(PACKAGE_NAME);
            $fail('Packagist package is not published yet: ' . PACKAGE_NAME, 'unpublished-package');
        }
    } elseif ($packageOnly || $requestedVersion === null) {
        $ok('Packagist package is visible: ' . PACKAGE_NAME);
        echo 'URL  ' . PACKAGE_URL . PHP_EOL;
        echo 'STATUS published' . PHP_EOL;
        echo 'LATEST ' . $versions[0] . PHP_EOL;
        echo 'VISIBLE ' . implode(', ', array_slice($versions, 0, 10)) . PHP_EOL;

        exit(0);
    } else {
        $requested = $normalizeVersion($requestedVersion);
        $visibleVersions = array_flip(array_map($normalizeVersion, $versions));

        if (array_key_exists($requested, $visibleVersions)) {
            $ok('Packagist version ' . $requestedVersion . ' is visible for ' . PACKAGE_NAME);
            echo 'URL  ' . PACKAGE_URL . PHP_EOL;
            echo 'STATUS published-version' . PHP_EOL;
            echo 'VISIBLE ' . implode(', ', array_slice($versions, 0, 10)) . PHP_EOL;

            exit(0);
        }

        if (time() >= $deadline) {
            $printPackagistNextSteps($requestedVersion);
            $fail(
                'Packagist version ' . $requestedVersion . ' is not visible for ' . PACKAGE_NAME
                . '; visible versions: ' . implode(', ', array_slice($versions, 0, 10)),
                'unpublished-version'
            );
        }
    }

    echo 'WAIT Packagist has not exposed the requested release state yet'
        . " (attempt {$attempt}, sleeping {$intervalSeconds}s)." . PHP_EOL;
    sleep($intervalSeconds);
} while (time() <= $deadline);

$fail('Packagist release state was not visible before timeout.', 'timeout');
