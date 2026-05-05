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

$path = static fn (string $relative): string => $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

/**
 * @return list<string>
 */
$matchingTerms = static function (string $contents, array $terms): array {
    $matches = [];

    foreach ($terms as $term) {
        $pattern = '/\b' . preg_quote($term, '/') . '\b/i';

        if (preg_match($pattern, $contents) === 1) {
            $matches[] = $term;
        }
    }

    return $matches;
};

/**
 * @return list<string>
 */
$matchingPhrases = static function (string $contents, array $phrases): array {
    $matches = [];
    $normalized = strtolower($contents);

    foreach ($phrases as $phrase) {
        if (str_contains($normalized, strtolower($phrase))) {
            $matches[] = $phrase;
        }
    }

    return $matches;
};

$requiredDocs = [
    'README.md',
    'CHANGELOG.md',
    'RELEASE.md',
    'SECURITY.md',
    'SUPPORT.md',
];

$publicArtifactTerms = [
    'subagent',
    'codex',
    'overpowered',
    'prompt',
    'TODO',
    'FIXME',
];

$readmeInternalPhrases = [
    'docs/preview',
    'internal spec',
    'internal roadmap',
    'internal plan',
    'internal planning',
    'planning artifact',
    'planning artifacts',
];

$readmeInternalTerms = [
    'spec',
    'roadmap',
    'planning',
];

$contentsByDoc = [];

foreach ($requiredDocs as $doc) {
    $docPath = $path($doc);
    $exists = is_file($docPath);

    $check($exists, "{$doc} exists");

    if (! $exists) {
        continue;
    }

    $contents = file_get_contents($docPath);

    $check($contents !== false, "{$doc} is readable");

    if ($contents !== false) {
        $contentsByDoc[$doc] = $contents;
    }
}

if (isset($contentsByDoc['README.md'])) {
    $readme = $contentsByDoc['README.md'];

    $check(str_contains($readme, 'oxhq/preview'), 'README.md mentions oxhq/preview');
    $check(str_contains($readme, 'Laravel Preview'), 'README.md mentions Laravel Preview');

    $internalMatches = [
        ...$matchingPhrases($readme, $readmeInternalPhrases),
        ...$matchingTerms($readme, $readmeInternalTerms),
    ];

    $internalMatches = array_values(array_unique($internalMatches));

    $check($internalMatches === [], 'README.md avoids docs/preview and internal planning language');

    foreach ($internalMatches as $match) {
        $check(false, "README.md contains internal public-surface phrase: {$match}");
    }
} else {
    $check(false, 'README.md mentions oxhq/preview');
    $check(false, 'README.md mentions Laravel Preview');
    $check(false, 'README.md avoids docs/preview and internal planning language');
}

foreach ($contentsByDoc as $doc => $contents) {
    $artifactMatches = $matchingTerms($contents, $publicArtifactTerms);

    $check($artifactMatches === [], "{$doc} avoids AI-agent artifacts");

    foreach ($artifactMatches as $match) {
        $check(false, "{$doc} contains AI-agent artifact: {$match}");
    }
}

exit($failed ? 1 : 0);
