[CmdletBinding()]
param(
    [ValidateSet('stripe', 'github', 'shopify')]
    [string[]] $Provider = @('stripe', 'github', 'shopify'),

    [string] $PackageRoot = '',

    [string] $WorkDir = '',

    [switch] $KeepWorkDir
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Smoke {
    param([string] $Message)

    Write-Host "[provider-signatures-smoke] $Message"
}

function Get-CommandPath {
    param(
        [Parameter(Mandatory)]
        [string] $Name
    )

    $command = Get-Command $Name -ErrorAction Stop

    return $command.Source
}

function Get-FullPath {
    param(
        [Parameter(Mandatory)]
        [string] $Path
    )

    return [System.IO.Path]::GetFullPath($Path)
}

function Assert-SafeSmokeDirectory {
    param(
        [Parameter(Mandatory)]
        [string] $Path,
        [Parameter(Mandatory)]
        [string] $PackageRoot
    )

    $fullPath = Get-FullPath -Path $Path
    $parent = [System.IO.Directory]::GetParent($fullPath)

    if ($null -eq $parent) {
        throw "Refusing to use filesystem root [$fullPath] as smoke work directory."
    }

    $packageFull = Get-FullPath -Path $PackageRoot
    $currentFull = Get-FullPath -Path (Get-Location).Path

    if ($fullPath.TrimEnd('\') -ieq $packageFull.TrimEnd('\')) {
        throw "Refusing to use package root [$packageFull] as smoke work directory."
    }

    if ($fullPath.TrimEnd('\') -ieq $currentFull.TrimEnd('\')) {
        throw "Refusing to use current directory [$currentFull] as smoke work directory."
    }

    if ([string]::IsNullOrWhiteSpace([System.IO.Path]::GetFileName($fullPath))) {
        throw "Refusing to use unsafe smoke work directory [$fullPath]."
    }

    return $fullPath
}

function Redact-SmokeOutput {
    param(
        [AllowNull()]
        [string] $Text,
        [string] $StripeSecret,
        [string] $GitHubSecret,
        [string] $ShopifySecret
    )

    if ([string]::IsNullOrEmpty($Text)) {
        return ''
    }

    $redacted = $Text

    foreach ($secret in @($StripeSecret, $GitHubSecret, $ShopifySecret)) {
        if (-not [string]::IsNullOrWhiteSpace($secret)) {
            $redacted = $redacted.Replace($secret, '[redacted-provider-secret]')
        }
    }

    $redacted = $redacted -replace 'whsec_[A-Za-z0-9_]+', 'whsec_[redacted]'
    $redacted = $redacted -replace 'sha256=[a-f0-9]{64}', 'sha256=[redacted]'
    $redacted = $redacted -replace 't=\d+,v1=[a-f0-9]{64}', 't=[redacted],v1=[redacted]'
    $redacted = $redacted -replace '(?i)("X-Shopify-Hmac-Sha256"\s*:\s*")[^"]+(")', '$1[redacted]$2'
    $redacted = $redacted -replace '(?i)(X-Shopify-Hmac-Sha256:\s*)[A-Za-z0-9+/=]+', '$1[redacted]'

    return $redacted
}

function Invoke-PreviewCommand {
    param(
        [Parameter(Mandatory)]
        [string] $Php,
        [Parameter(Mandatory)]
        [string] $Testbench,
        [Parameter(Mandatory)]
        [string[]] $Arguments,
        [Parameter(Mandatory)]
        [string] $WorkingDirectory,
        [Parameter(Mandatory)]
        [string] $Label,
        [Parameter(Mandatory)]
        [string] $StripeSecret,
        [Parameter(Mandatory)]
        [string] $GitHubSecret,
        [Parameter(Mandatory)]
        [string] $ShopifySecret
    )

    Write-Smoke $Label

    Push-Location -LiteralPath $WorkingDirectory

    try {
        $previousErrorActionPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'

        try {
            $outputObjects = & $Php $Testbench @Arguments 2>&1
            $exitCode = if ($null -eq (Get-Variable -Name LASTEXITCODE -ErrorAction SilentlyContinue)) { 0 } else { [int] $LASTEXITCODE }
        } finally {
            $ErrorActionPreference = $previousErrorActionPreference
        }
    } finally {
        Pop-Location
    }

    $output = ($outputObjects | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine

    if ($exitCode -ne 0) {
        $safeOutput = Redact-SmokeOutput -Text $output -StripeSecret $StripeSecret -GitHubSecret $GitHubSecret -ShopifySecret $ShopifySecret
        throw "$Label failed with exit code $exitCode.$([Environment]::NewLine)$safeOutput"
    }

    return $output
}

function Invoke-PhpLint {
    param(
        [Parameter(Mandatory)]
        [string] $Php,
        [Parameter(Mandatory)]
        [string] $Path
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Expected generated PHP file [$Path] was not found."
    }

    $output = & $Php '-l' $Path 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw "Generated PHP file [$Path] is not lintable.$([Environment]::NewLine)$(($output | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine)"
    }
}

function Get-SampleEvent {
    param(
        [Parameter(Mandatory)]
        [string] $Provider
    )

    switch ($Provider) {
        'stripe' { return 'checkout.session.completed' }
        'github' { return 'pull_request' }
        'shopify' { return 'orders/create' }
    }

    throw "Unsupported provider [$Provider]."
}

$scriptRoot = if ($PSScriptRoot -ne '') {
    $PSScriptRoot
} else {
    Split-Path -Parent $MyInvocation.MyCommand.Path
}

if ([string]::IsNullOrWhiteSpace($PackageRoot)) {
    $PackageRoot = Join-Path $scriptRoot '..'
}

$resolvedPackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path

if ([string]::IsNullOrWhiteSpace($WorkDir)) {
    $WorkDir = Join-Path ([System.IO.Path]::GetTempPath()) ('preview-provider-signatures-smoke-' + [Guid]::NewGuid().ToString('N'))
}

$safeWorkDir = Assert-SafeSmokeDirectory -Path $WorkDir -PackageRoot $resolvedPackageRoot
$storagePath = Join-Path $safeWorkDir 'captures'
$fixturePath = Join-Path $safeWorkDir 'fixtures'
$testPath = Join-Path $safeWorkDir 'tests'
$proof = [System.Collections.Generic.List[string]]::new()

$previousStoragePath = [Environment]::GetEnvironmentVariable('PREVIEW_STORAGE_PATH', 'Process')
$previousFixturePath = [Environment]::GetEnvironmentVariable('PREVIEW_FIXTURE_PATH', 'Process')
$previousTestPath = [Environment]::GetEnvironmentVariable('PREVIEW_TEST_PATH', 'Process')
$previousStripeSecret = [Environment]::GetEnvironmentVariable('PREVIEW_STRIPE_ENDPOINT_SECRET', 'Process')
$previousGitHubSecret = [Environment]::GetEnvironmentVariable('PREVIEW_GITHUB_WEBHOOK_SECRET', 'Process')
$previousShopifySecret = [Environment]::GetEnvironmentVariable('PREVIEW_SHOPIFY_CLIENT_SECRET', 'Process')

$stripeSecret = if ([string]::IsNullOrWhiteSpace($previousStripeSecret)) { 'whsec_preview_signature_smoke' } else { $previousStripeSecret }
$githubSecret = if ([string]::IsNullOrWhiteSpace($previousGitHubSecret)) { 'github-preview-signature-smoke-secret' } else { $previousGitHubSecret }
$shopifySecret = if ([string]::IsNullOrWhiteSpace($previousShopifySecret)) { 'shopify-preview-signature-smoke-secret' } else { $previousShopifySecret }

try {
    if (Test-Path -LiteralPath $safeWorkDir) {
        Remove-Item -LiteralPath $safeWorkDir -Recurse -Force
    }

    New-Item -ItemType Directory -Path $storagePath, $fixturePath, $testPath -Force | Out-Null

    [Environment]::SetEnvironmentVariable('PREVIEW_STORAGE_PATH', $storagePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_FIXTURE_PATH', $fixturePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_TEST_PATH', $testPath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_STRIPE_ENDPOINT_SECRET', $stripeSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_GITHUB_WEBHOOK_SECRET', $githubSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_SHOPIFY_CLIENT_SECRET', $shopifySecret, 'Process')

    $php = Get-CommandPath -Name 'php'
    $testbench = Join-Path $resolvedPackageRoot 'vendor/bin/testbench'

    if (-not (Test-Path -LiteralPath $testbench -PathType Leaf)) {
        throw "Missing vendor/bin/testbench under [$resolvedPackageRoot]. Run Composer install before running this smoke script."
    }

    Write-Smoke "PackageRoot: $resolvedPackageRoot"
    Write-Smoke "WorkDir: $safeWorkDir"
    Write-Smoke 'Provider secrets are process-local and will not be printed.'

    foreach ($providerName in $Provider) {
        $event = Get-SampleEvent -Provider $providerName

        Invoke-PreviewCommand `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:provider:self-test', $providerName, '--json') `
            -WorkingDirectory $resolvedPackageRoot `
            -Label "Run $providerName provider self-test" `
            -StripeSecret $stripeSecret `
            -GitHubSecret $githubSecret `
            -ShopifySecret $shopifySecret | Out-Null
        $proof.Add("$providerName self-test verifies synthetic signed request")

        $sampleOutput = Invoke-PreviewCommand `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:provider:sample', $providerName, "--event=$event", '--json') `
            -WorkingDirectory $resolvedPackageRoot `
            -Label "Generate $providerName signed sample" `
            -StripeSecret $stripeSecret `
            -GitHubSecret $githubSecret `
            -ShopifySecret $shopifySecret

        $sample = $sampleOutput | ConvertFrom-Json
        $headers = @()
        $rawBodyArgument = (([string] $sample.raw_body) -replace '"', '\"')

        foreach ($header in $sample.headers.PSObject.Properties) {
            $headers += "--header=$($header.Name): $($header.Value)"
        }

        $captureArguments = @(
            'preview:capture',
            $providerName,
            '--method=POST',
            "--path=/webhook/$providerName",
            "--body=$rawBodyArgument"
        ) + $headers

        $captureOutput = Invoke-PreviewCommand `
            -Php $php `
            -Testbench $testbench `
            -Arguments $captureArguments `
            -WorkingDirectory $resolvedPackageRoot `
            -Label "Capture $providerName signed sample" `
            -StripeSecret $stripeSecret `
            -GitHubSecret $githubSecret `
            -ShopifySecret $shopifySecret

        $captureMatch = [regex]::Match($captureOutput, 'Captured \[(?<id>[^\]]+)\]')

        if (-not $captureMatch.Success) {
            throw "Could not parse capture ID from $providerName capture output."
        }

        $captureId = $captureMatch.Groups['id'].Value
        $proof.Add("$providerName capture [$captureId] stored and verified")

        $showOutput = Invoke-PreviewCommand `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:capture:show', $captureId, '--json') `
            -WorkingDirectory $resolvedPackageRoot `
            -Label "Show $providerName capture metadata" `
            -StripeSecret $stripeSecret `
            -GitHubSecret $githubSecret `
            -ShopifySecret $shopifySecret

        $show = $showOutput | ConvertFrom-Json

        if ($show.verified -ne $true) {
            throw "$providerName capture [$captureId] was not verified."
        }

        if ($show.event_type -ne $event) {
            throw "$providerName capture [$captureId] event type was [$($show.event_type)], expected [$event]."
        }

        foreach ($mode in @('exact', 'resign')) {
            $replayOutput = Invoke-PreviewCommand `
                -Php $php `
                -Testbench $testbench `
                -Arguments @('preview:capture:replay', $captureId, "--$mode", '--json') `
                -WorkingDirectory $resolvedPackageRoot `
                -Label "Build $providerName $mode replay payload" `
                -StripeSecret $stripeSecret `
                -GitHubSecret $githubSecret `
                -ShopifySecret $shopifySecret

            $replay = $replayOutput | ConvertFrom-Json

            if ($replay.mode -ne $mode) {
                throw "$providerName replay mode was [$($replay.mode)], expected [$mode]."
            }
        }

        $proof.Add("$providerName exact and resign replay payloads build successfully")

        $fixtureOutput = Invoke-PreviewCommand `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:capture:fixture', $captureId, '--json') `
            -WorkingDirectory $resolvedPackageRoot `
            -Label "Generate $providerName fixture" `
            -StripeSecret $stripeSecret `
            -GitHubSecret $githubSecret `
            -ShopifySecret $shopifySecret

        $fixture = $fixtureOutput | ConvertFrom-Json
        Invoke-PhpLint -Php $php -Path $fixture.fixture_path
        $proof.Add("$providerName fixture generated and linted")

        $testOutput = Invoke-PreviewCommand `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:capture:test', $captureId, '--json') `
            -WorkingDirectory $resolvedPackageRoot `
            -Label "Generate $providerName Pest test" `
            -StripeSecret $stripeSecret `
            -GitHubSecret $githubSecret `
            -ShopifySecret $shopifySecret

        $test = $testOutput | ConvertFrom-Json
        Invoke-PhpLint -Php $php -Path $test.test_path
        $proof.Add("$providerName Pest test generated and linted")
    }

    Write-Host ''
    Write-Host 'Provider signature smoke proof summary:'
    foreach ($item in $proof) {
        Write-Host "- $item"
    }
} finally {
    [Environment]::SetEnvironmentVariable('PREVIEW_STORAGE_PATH', $previousStoragePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_FIXTURE_PATH', $previousFixturePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_TEST_PATH', $previousTestPath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_STRIPE_ENDPOINT_SECRET', $previousStripeSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_GITHUB_WEBHOOK_SECRET', $previousGitHubSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_SHOPIFY_CLIENT_SECRET', $previousShopifySecret, 'Process')

    if ($KeepWorkDir) {
        Write-Host ''
        Write-Host "Keeping smoke work directory: $safeWorkDir"
    } elseif (Test-Path -LiteralPath $safeWorkDir) {
        $safeWorkDir = Assert-SafeSmokeDirectory -Path $safeWorkDir -PackageRoot $resolvedPackageRoot
        Remove-Item -LiteralPath $safeWorkDir -Recurse -Force
        Write-Host ''
        Write-Host "Deleted smoke work directory: $safeWorkDir"
    }
}
