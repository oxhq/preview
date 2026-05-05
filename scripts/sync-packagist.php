<?php

declare(strict_types=1);

const PACKAGE_NAME = 'oxhq/preview';
const REPOSITORY_URL = 'https://github.com/oxhq/preview';
const METADATA_URL = 'https://repo.packagist.org/p2/oxhq/preview.json';
const CREATE_PACKAGE_URL = 'https://packagist.org/api/create-package';
const UPDATE_PACKAGE_URL = 'https://packagist.org/api/update-package';
const TIMEOUT_SECONDS = 15;

$mode = 'ensure';
$repositoryUrl = REPOSITORY_URL;

foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--ensure', '--create', '--update'], true)) {
        $mode = substr($argument, 2);

        continue;
    }

    if (str_starts_with($argument, '--repository=')) {
        $repositoryUrl = substr($argument, strlen('--repository='));

        continue;
    }

    echo "FAIL Unknown option {$argument}" . PHP_EOL;
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

$redact = static function (string $text, array $secrets): string {
    foreach ($secrets as $secret) {
        if (is_string($secret) && $secret !== '') {
            $text = str_replace($secret, '[redacted-packagist-secret]', $text);
        }
    }

    return $text;
};

$readMetadata = static function (): array {
    $context = stream_context_create([
        'http' => [
            'timeout' => TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'header' => [
                'Accept: application/json',
                'User-Agent: oxhq-preview-packagist-sync/1.0',
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

    if ($statusCode === 404) {
        return ['published' => false, 'statusCode' => 404];
    }

    if ($body === false) {
        return ['published' => false, 'statusCode' => $statusCode, 'error' => error_get_last()['message'] ?? 'unknown error'];
    }

    if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
        return ['published' => false, 'statusCode' => $statusCode, 'error' => 'metadata HTTP ' . $statusCode];
    }

    $metadata = json_decode($body, true);

    return [
        'published' => is_array($metadata) && isset($metadata['packages'][PACKAGE_NAME]) && is_array($metadata['packages'][PACKAGE_NAME]),
        'statusCode' => $statusCode,
    ];
};

$callPackagist = static function (string $url, string $repository, string $username, string $apiToken) use ($fail, $redact): array {
    $body = json_encode(['repository' => $repository], JSON_THROW_ON_ERROR);
    $authorization = $username . ':' . $apiToken;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'header' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $authorization,
                'User-Agent: oxhq-preview-packagist-sync/1.0',
            ],
            'content' => $body,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $statusCode = null;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            $statusCode = (int) $matches[1];
            break;
        }
    }

    $safeBody = $redact((string) $responseBody, [$username, $apiToken, $authorization]);

    if ($responseBody === false) {
        $detail = error_get_last()['message'] ?? 'unknown error';
        $fail('Packagist API request failed: ' . $redact($detail, [$username, $apiToken, $authorization]), 'api-error');
    }

    if ($statusCode === null || $statusCode < 200 || $statusCode >= 300) {
        $fail(
            'Packagist API returned HTTP ' . ($statusCode ?? 'unknown') . ($safeBody !== '' ? ': ' . $safeBody : ''),
            'api-error'
        );
    }

    $decoded = json_decode((string) $responseBody, true);

    return is_array($decoded) ? $decoded : [];
};

if (! filter_var($repositoryUrl, FILTER_VALIDATE_URL)) {
    $fail('Repository URL is invalid: ' . $repositoryUrl, 'invalid-argument', 2);
}

$username = getenv('PACKAGIST_USERNAME') ?: '';
$apiToken = getenv('PACKAGIST_API_TOKEN') ?: '';

if ($username === '' || $apiToken === '') {
    $fail(
        'Missing PACKAGIST_USERNAME or PACKAGIST_API_TOKEN. Configure GitHub Actions secrets before running Packagist sync.',
        'missing-secrets'
    );
}

$state = $readMetadata();
$published = (bool) ($state['published'] ?? false);

if ($mode === 'ensure') {
    $mode = $published ? 'update' : 'create';
}

if ($mode === 'create' && $published) {
    $ok('Packagist package is already registered: ' . PACKAGE_NAME);
    echo 'STATUS already-published' . PHP_EOL;
    exit(0);
}

if ($mode === 'update' && ! $published) {
    $fail('Packagist package is not registered yet; run with --ensure or --create first.', 'unpublished-package');
}

$endpoint = $mode === 'create' ? CREATE_PACKAGE_URL : UPDATE_PACKAGE_URL;
$response = $callPackagist($endpoint, $repositoryUrl, $username, $apiToken);
$status = isset($response['status']) && is_string($response['status']) ? $response['status'] : 'success';

$ok('Packagist ' . $mode . ' request accepted for ' . PACKAGE_NAME . '.');
echo 'STATUS ' . $status . PHP_EOL;
echo 'URL  https://packagist.org/packages/' . PACKAGE_NAME . PHP_EOL;
exit(0);
