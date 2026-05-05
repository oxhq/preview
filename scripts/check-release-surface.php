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

$composerPath = $path('composer.json');
$composer = null;

$check(is_file($composerPath), 'composer.json exists');

if (is_file($composerPath)) {
    $contents = file_get_contents($composerPath);
    $composer = json_decode($contents === false ? '' : $contents, true);

    $check(json_last_error() === JSON_ERROR_NONE && is_array($composer), 'composer.json is valid JSON');
    $check(($composer['name'] ?? null) === 'oxhq/preview', 'composer.json package name is oxhq/preview');
    $check(($composer['homepage'] ?? null) === 'https://github.com/oxhq/preview', 'composer.json homepage points to GitHub repository');
    $check(($composer['support']['docs'] ?? null) === 'https://github.com/oxhq/preview#readme', 'composer.json docs support points to README');
} else {
    $check(false, 'composer.json is valid JSON');
    $check(false, 'composer.json package name is oxhq/preview');
    $check(false, 'composer.json homepage points to GitHub repository');
    $check(false, 'composer.json docs support points to README');
}

$check(is_file($path('README.md')), 'README.md exists');
$check(is_file($path('CHANGELOG.md')), 'CHANGELOG.md exists');
$check(is_file($path('RELEASE.md')), 'RELEASE.md exists');
$check(is_file($path('SECURITY.md')), 'SECURITY.md exists');
$check(is_file($path('SUPPORT.md')), 'SUPPORT.md exists');
$check(is_file($path('.gitattributes')), '.gitattributes exists');
$check(is_file($path('.github/dependabot.yml')), '.github/dependabot.yml exists');
$check(is_file($path('.github/workflows/ci.yml')), '.github/workflows/ci.yml exists');
$check(is_file($path('.github/workflows/release.yml')), '.github/workflows/release.yml exists');
$check(is_file($path('scripts/check-command-surface.php')), 'scripts/check-command-surface.php exists');
$check(is_file($path('scripts/check-dist-archive.php')), 'scripts/check-dist-archive.php exists');
$check(is_file($path('scripts/check-github-release.ps1')), 'scripts/check-github-release.ps1 exists');
$check(is_file($path('scripts/check-packagist.php')), 'scripts/check-packagist.php exists');
$check(is_file($path('scripts/check-powershell-surface.ps1')), 'scripts/check-powershell-surface.ps1 exists');
$check(is_file($path('scripts/check-public-surface.php')), 'scripts/check-public-surface.php exists');
$check(is_file($path('scripts/check-source-archive.php')), 'scripts/check-source-archive.php exists');
$check(is_file($path('scripts/prepare-release.ps1')), 'scripts/prepare-release.ps1 exists');
$check(is_file($path('scripts/smoke-packagist-install.ps1')), 'scripts/smoke-packagist-install.ps1 exists');
$check(is_file($path('scripts/smoke-public-ingress.ps1')), 'scripts/smoke-public-ingress.ps1 exists');
$check(is_file($path('scripts/smoke-provider-signatures.ps1')), 'scripts/smoke-provider-signatures.ps1 exists');
$check(is_file($path('scripts/smoke-tunnel.ps1')), 'scripts/smoke-tunnel.ps1 exists');

$readmePath = $path('README.md');
if (is_file($readmePath)) {
    $readme = file_get_contents($readmePath);
    $check($readme !== false && ! str_contains($readme, 'docs/preview'), 'README.md does not reference docs/preview');
} else {
    $check(false, 'README.md does not reference docs/preview');
}

$specFiles = [];
$docsPreviewPath = $path('docs/preview');

if (is_dir($docsPreviewPath)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docsPreviewPath, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
            $specFiles[] = $file->getPathname();
        }
    }
}

$check(count($specFiles) === 0, 'no docs/preview Markdown spec files exist');

exit($failed ? 1 : 0);
