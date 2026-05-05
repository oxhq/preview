<?php

declare(strict_types=1);

const PACKAGE_NAME = 'oxhq/preview';
const PACKAGE_URL = 'https://packagist.org/packages/oxhq/preview';
const METADATA_URL = 'https://repo.packagist.org/p2/oxhq/preview.json';
const TIMEOUT_SECONDS = 5;

$requestedVersion = $argv[1] ?? null;

$fail = static function (string $message, int $exitCode = 1): never {
    echo "FAIL {$message}" . PHP_EOL;
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

        $fail('Packagist metadata request failed; network may be unavailable' . $detail);
    }

    if ($statusCode === 404) {
        $fail('Packagist package is not published yet: ' . PACKAGE_NAME . ' (' . PACKAGE_URL . ')');
    }

    if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
        $fail('Packagist metadata request returned HTTP ' . $statusCode . ' for ' . METADATA_URL);
    }

    $metadata = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($metadata)) {
        $fail('Packagist metadata response was not valid JSON: ' . json_last_error_msg());
    }

    return $metadata;
};

$metadata = $readPackagistMetadata();
$versions = $metadata['packages'][PACKAGE_NAME] ?? null;

if (! is_array($versions) || $versions === []) {
    $fail('Packagist metadata did not contain visible versions for ' . PACKAGE_NAME . '; package may not be published yet');
}

$versionNames = [];
foreach ($versions as $version) {
    if (is_array($version) && isset($version['version']) && is_string($version['version'])) {
        $versionNames[] = $version['version'];
    }
}

if ($versionNames === []) {
    $fail('Packagist metadata contained no usable version names for ' . PACKAGE_NAME);
}

if ($requestedVersion !== null) {
    $requested = $normalizeVersion($requestedVersion);
    $visibleVersions = array_flip(array_map($normalizeVersion, $versionNames));

    if (! array_key_exists($requested, $visibleVersions)) {
        $fail(
            'Packagist version ' . $requestedVersion . ' is not visible for ' . PACKAGE_NAME
            . '; visible versions: ' . implode(', ', array_slice($versionNames, 0, 10))
        );
    }

    $ok('Packagist version ' . $requestedVersion . ' is visible for ' . PACKAGE_NAME);
    echo 'URL  ' . PACKAGE_URL . PHP_EOL;

    exit(0);
}

$ok('Packagist package is visible: ' . PACKAGE_NAME);
echo 'URL  ' . PACKAGE_URL . PHP_EOL;
echo 'STATUS published' . PHP_EOL;
echo 'LATEST ' . $versionNames[0] . PHP_EOL;
echo 'VISIBLE ' . implode(', ', array_slice($versionNames, 0, 10)) . PHP_EOL;

exit(0);
