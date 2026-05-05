[CmdletBinding()]
param(
    [string] $PackagePath = '',
    [string] $WorkDir = (Join-Path ([System.IO.Path]::GetTempPath()) 'preview-consumer-smoke'),
    [string] $LaravelVersion = '^12.0',
    [switch] $KeepWorkDir
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-ExistingDirectory {
    param(
        [Parameter(Mandatory)]
        [string] $Path,
        [Parameter(Mandatory)]
        [string] $Label
    )

    $resolved = Resolve-Path -LiteralPath $Path -ErrorAction Stop
    $item = Get-Item -LiteralPath $resolved.Path -ErrorAction Stop

    if (-not $item.PSIsContainer) {
        throw "$Label [$Path] is not a directory."
    }

    return $item.FullName
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

function Invoke-LoggedCommand {
    param(
        [Parameter(Mandatory)]
        [string] $FilePath,
        [Parameter(Mandatory)]
        [string[]] $Arguments,
        [Parameter(Mandatory)]
        [string] $WorkingDirectory,
        [Parameter(Mandatory)]
        [string] $Label
    )

    Write-Host ""
    Write-Host "==> $Label"
    Write-Host ("$FilePath " + ($Arguments -join ' '))

    $stdoutPath = [System.IO.Path]::GetTempFileName()
    $stderrPath = [System.IO.Path]::GetTempFileName()

    try {
        $process = Start-Process `
            -FilePath $FilePath `
            -ArgumentList ($Arguments | ForEach-Object { ConvertTo-WindowsArgument -Argument $_ }) `
            -WorkingDirectory $WorkingDirectory `
            -RedirectStandardOutput $stdoutPath `
            -RedirectStandardError $stderrPath `
            -Wait `
            -PassThru `
            -NoNewWindow

        $stdout = Get-Content -LiteralPath $stdoutPath -Raw -ErrorAction SilentlyContinue
        $stderr = Get-Content -LiteralPath $stderrPath -Raw -ErrorAction SilentlyContinue
        $exitCode = $process.ExitCode
    } finally {
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }

    $text = (($stdout, $stderr) -join [Environment]::NewLine).TrimEnd()

    if ($text -ne '') {
        Write-Host $text
    }

    if ($exitCode -ne 0) {
        throw "$Label failed with exit code $exitCode."
    }

    return $text
}

function ConvertTo-WindowsArgument {
    param(
        [Parameter(Mandatory)]
        [string] $Argument
    )

    if ($Argument -eq '') {
        return '""'
    }

    if ($Argument -notmatch '[\s"]') {
        return $Argument
    }

    $quoted = '"'
    $backslashes = 0

    foreach ($char in $Argument.ToCharArray()) {
        if ($char -eq '\') {
            $backslashes++
            continue
        }

        if ($char -eq '"') {
            $quoted += ('\' * (($backslashes * 2) + 1))
            $quoted += '"'
            $backslashes = 0
            continue
        }

        if ($backslashes -gt 0) {
            $quoted += ('\' * $backslashes)
            $backslashes = 0
        }

        $quoted += $char
    }

    if ($backslashes -gt 0) {
        $quoted += ('\' * ($backslashes * 2))
    }

    $quoted += '"'

    return $quoted
}

function Get-CommandPath {
    param(
        [Parameter(Mandatory)]
        [string] $Name
    )

    $command = Get-Command $Name -ErrorAction Stop

    return $command.Source
}

function ConvertFrom-CommandJson {
    param(
        [Parameter(Mandatory)]
        [string] $Text,
        [Parameter(Mandatory)]
        [string] $Label
    )

    $match = [regex]::Match($Text, '(?s)(\{.*\}|\[.*\])')

    if (-not $match.Success) {
        throw "$Label did not include a JSON object or array."
    }

    try {
        return $match.Groups[1].Value | ConvertFrom-Json
    } catch {
        throw "$Label included JSON-looking output that could not be parsed: $($_.Exception.Message)"
    }
}

function Add-SmokeRoute {
    param(
        [Parameter(Mandatory)]
        [string] $AppPath
    )

    $routesPath = Join-Path $AppPath 'routes\web.php'

    if (-not (Test-Path -LiteralPath $routesPath)) {
        throw "Expected Laravel routes file [$routesPath] was not found."
    }

    $route = @'

Route::post('/preview-smoke', function () {
    return response()->json(['ok' => true]);
});

Route::get('/preview-smoke-route', function () {
    return response('preview route ok');
})->name('preview.smoke');
'@

    Add-Content -LiteralPath $routesPath -Value $route
}

function Install-PestIfMissing {
    param(
        [Parameter(Mandatory)]
        [string] $ComposerPath,
        [Parameter(Mandatory)]
        [string] $AppPath
    )

    $pestBat = Join-Path $AppPath 'vendor\bin\pest.bat'
    $pest = Join-Path $AppPath 'vendor\bin\pest'

    if ((Test-Path -LiteralPath $pestBat) -or (Test-Path -LiteralPath $pest)) {
        return 'already installed'
    }

    Invoke-LoggedCommand `
        -FilePath $ComposerPath `
        -Arguments @('config', 'allow-plugins.pestphp/pest-plugin', 'true') `
        -WorkingDirectory $AppPath `
        -Label 'Allow Pest Composer plugin in disposable consumer app' | Out-Null

    Invoke-LoggedCommand `
        -FilePath $ComposerPath `
        -Arguments @('require', '--dev', 'pestphp/pest:~3.8.2', 'pestphp/pest-plugin-laravel:~3.2.0', 'phpunit/phpunit:~11.5.15', '--no-interaction', '--with-all-dependencies') `
        -WorkingDirectory $AppPath `
        -Label 'Install Pest for generated Preview tests' | Out-Null

    return 'installed'
}

function Ensure-PestLaravelSetup {
    param(
        [Parameter(Mandatory)]
        [string] $AppPath
    )

    $pestSetupPath = Join-Path $AppPath 'tests\Pest.php'

    if (Test-Path -LiteralPath $pestSetupPath) {
        $existing = Get-Content -LiteralPath $pestSetupPath -Raw

        if ($existing -match 'Tests\\TestCase::class' -and $existing -match "->in\('Feature'\)") {
            return 'already configured'
        }
    }

    $contents = @'
<?php

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
'@

    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($pestSetupPath, $contents, $utf8NoBom)

    return 'configured'
}

$scriptRoot = if ($PSScriptRoot -ne '') {
    $PSScriptRoot
} else {
    Split-Path -Parent $MyInvocation.MyCommand.Path
}

if ([string]::IsNullOrWhiteSpace($PackagePath)) {
    $PackagePath = Join-Path $scriptRoot '..'
}

$packageRoot = Resolve-ExistingDirectory -Path $PackagePath -Label 'PackagePath'
$workRoot = Assert-SafeSmokeDirectory -Path $WorkDir -PackageRoot $packageRoot
$appPath = Join-Path $workRoot 'app'
$generatedTestFilter = 'handles generic preview.smoke'
$scenarioName = 'preview-smoke-flow'
$generatedScenarioTestFilter = "replays $scenarioName preview scenario"
$proof = [System.Collections.Generic.List[string]]::new()

try {
    $composer = Get-CommandPath -Name 'composer'
    $php = Get-CommandPath -Name 'php'

    Write-Host "PackagePath: $packageRoot"
    Write-Host "WorkDir: $workRoot"
    Write-Host "LaravelVersion: $LaravelVersion"
    Write-Host "Composer: $composer"
    Write-Host "PHP: $php"

    if (Test-Path -LiteralPath $workRoot) {
        Remove-Item -LiteralPath $workRoot -Recurse -Force
    }

    New-Item -ItemType Directory -Path $workRoot -Force | Out-Null

    Invoke-LoggedCommand `
        -FilePath $composer `
        -Arguments @('create-project', 'laravel/laravel', $appPath, $LaravelVersion, '--no-interaction', '--prefer-dist') `
        -WorkingDirectory $workRoot `
        -Label 'Create fresh Laravel consumer app' | Out-Null

    Add-SmokeRoute -AppPath $appPath

    $repositoryJson = @{
        type = 'path'
        url = $packageRoot
        options = @{
            symlink = $true
        }
    } | ConvertTo-Json -Compress

    Invoke-LoggedCommand `
        -FilePath $composer `
        -Arguments @('config', 'repositories.oxhq-preview', $repositoryJson) `
        -WorkingDirectory $appPath `
        -Label 'Configure oxhq/preview path repository with symlink' | Out-Null

    Invoke-LoggedCommand `
        -FilePath $composer `
        -Arguments @('require', '--dev', 'oxhq/preview:*@dev', '--no-interaction', '--with-all-dependencies') `
        -WorkingDirectory $appPath `
        -Label 'Require oxhq/preview as dev dependency' | Out-Null

    $artisanList = Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'list') `
        -WorkingDirectory $appPath `
        -Label 'Verify Preview commands are discoverable'

    if ($artisanList -notmatch 'preview:capture' -or $artisanList -notmatch 'preview:capture:list' -or $artisanList -notmatch 'preview:capture:fixture' -or $artisanList -notmatch 'preview:capture:test') {
        throw 'php artisan list did not include the expected Preview capture commands.'
    }

    $proof.Add('artisan list includes preview:capture, preview:capture:list, preview:capture:fixture, preview:capture:test')

    if ($artisanList -notmatch 'preview:scenario:make' -or $artisanList -notmatch 'preview:scenario:replay' -or $artisanList -notmatch 'preview:scenario:test') {
        throw 'php artisan list did not include the expected Preview scenario commands.'
    }

    $proof.Add('artisan list includes preview:scenario:make, preview:scenario:replay, preview:scenario:test')

    $captureOutput = Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @(
            'artisan',
            'preview:capture',
            'generic',
            '--method=POST',
            '--path=/preview-smoke',
            '--header=X-Preview-Event: preview.smoke',
            '--body={"smoke":true}'
        ) `
        -WorkingDirectory $appPath `
        -Label 'Run synthetic generic capture'

    $captureMatch = [regex]::Match($captureOutput, 'Captured \[(?<id>[^\]]+)\]')

    if (-not $captureMatch.Success) {
        throw 'Could not parse capture ID from preview:capture output.'
    }

    $captureId = $captureMatch.Groups['id'].Value
    $proof.Add("synthetic capture generated ID [$captureId]")

    $listOutput = Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'preview:capture:list', '--json') `
        -WorkingDirectory $appPath `
        -Label 'List captures'

    if ($listOutput -notmatch [regex]::Escape($captureId)) {
        throw "preview:capture:list output did not include capture [$captureId]."
    }

    $proof.Add("capture:list includes [$captureId]")

    Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'preview:capture:fixture', $captureId) `
        -WorkingDirectory $appPath `
        -Label 'Generate fixture for capture' | Out-Null
    $proof.Add("capture:fixture generated fixture for [$captureId]")

    Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'preview:capture:test', $captureId) `
        -WorkingDirectory $appPath `
        -Label 'Generate Pest test for capture' | Out-Null
    $proof.Add("capture:test generated Pest test for [$captureId]")

    Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @(
            'artisan',
            'preview:scenario:make',
            $scenarioName,
            '--capture',
            $captureId,
            '--route',
            'preview.smoke',
            '--route-status',
            'preview.smoke=200',
            '--route-output-contains',
            'preview.smoke=route ok',
            '--fake',
            'queue',
            '--note',
            'Disposable consumer smoke scenario',
            '--force'
        ) `
        -WorkingDirectory $appPath `
        -Label 'Create scenario from generated capture and route' | Out-Null
    $proof.Add("scenario:make created [$scenarioName] with capture [$captureId] and route [preview.smoke]")

    $scenarioReplayOutput = Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'preview:scenario:replay', $scenarioName, '--exact', '--json') `
        -WorkingDirectory $appPath `
        -Label 'Replay generated scenario'

    $scenarioReplay = ConvertFrom-CommandJson -Text $scenarioReplayOutput -Label 'preview:scenario:replay'

    if ($scenarioReplay.scenario -ne $scenarioName -or $scenarioReplay.successful -ne $true) {
        throw "preview:scenario:replay did not report successful replay for [$scenarioName]."
    }

    if ($scenarioReplay.captures.Count -ne 1 -or $scenarioReplay.captures[0].id -ne $captureId) {
        throw "preview:scenario:replay output did not include capture [$captureId]."
    }

    if ($scenarioReplay.routes.Count -ne 1 -or $scenarioReplay.routes[0].name -ne 'preview.smoke' -or $scenarioReplay.routes[0].status_code -ne 200) {
        throw 'preview:scenario:replay output did not include successful route [preview.smoke].'
    }

    $proof.Add("scenario:replay passed for [$scenarioName] with capture [$captureId] and route [preview.smoke]")

    $scenarioTestOutput = Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'preview:scenario:test', $scenarioName, '--json') `
        -WorkingDirectory $appPath `
        -Label 'Generate Pest test for scenario'

    $scenarioTest = ConvertFrom-CommandJson -Text $scenarioTestOutput -Label 'preview:scenario:test'

    if ($scenarioTest.scenario -ne $scenarioName -or -not (Test-Path -LiteralPath $scenarioTest.test_path)) {
        throw "preview:scenario:test did not generate a test file for [$scenarioName]."
    }

    $proof.Add("scenario:test generated Pest test [$($scenarioTest.test_path)]")

    $pestState = Install-PestIfMissing -ComposerPath $composer -AppPath $appPath
    $proof.Add("Pest test runner $pestState in disposable consumer app")
    $pestSetupState = Ensure-PestLaravelSetup -AppPath $appPath
    $proof.Add("Pest Laravel feature-test setup $pestSetupState in disposable consumer app")

    $artisanTestOutput = ''
    try {
        $artisanTestOutput = Invoke-LoggedCommand `
            -FilePath $php `
            -Arguments @('artisan', 'test', "--filter=$generatedTestFilter") `
            -WorkingDirectory $appPath `
            -Label 'Run generated test through php artisan test'
        $proof.Add('generated test passed via php artisan test')
    } catch {
        $phpunitBat = Join-Path $appPath 'vendor\bin\phpunit.bat'
        $phpunit = Join-Path $appPath 'vendor\bin\phpunit'
        $pestBat = Join-Path $appPath 'vendor\bin\pest.bat'
        $pest = Join-Path $appPath 'vendor\bin\pest'

        if (Test-Path -LiteralPath $pestBat) {
            Invoke-LoggedCommand `
                -FilePath $pestBat `
                -Arguments @("--filter=$generatedTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated test through vendor/bin/pest fallback' | Out-Null
            $proof.Add('generated test passed via vendor/bin/pest fallback')
        } elseif (Test-Path -LiteralPath $pest) {
            Invoke-LoggedCommand `
                -FilePath $php `
                -Arguments @($pest, "--filter=$generatedTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated test through vendor/bin/pest fallback' | Out-Null
            $proof.Add('generated test passed via vendor/bin/pest fallback')
        } elseif (Test-Path -LiteralPath $phpunitBat) {
            Invoke-LoggedCommand `
                -FilePath $phpunitBat `
                -Arguments @("--filter=$generatedTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated test through vendor/bin/phpunit fallback' | Out-Null
            $proof.Add('generated test passed via vendor/bin/phpunit fallback')
        } elseif (Test-Path -LiteralPath $phpunit) {
            Invoke-LoggedCommand `
                -FilePath $php `
                -Arguments @($phpunit, "--filter=$generatedTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated test through vendor/bin/phpunit fallback' | Out-Null
            $proof.Add('generated test passed via vendor/bin/phpunit fallback')
        } else {
            if ($artisanTestOutput -ne '') {
                Write-Host $artisanTestOutput
            }

            throw
        }
    }

    try {
        Invoke-LoggedCommand `
            -FilePath $php `
            -Arguments @('artisan', 'test', "--filter=$generatedScenarioTestFilter") `
            -WorkingDirectory $appPath `
            -Label 'Run generated scenario test through php artisan test' | Out-Null
        $proof.Add('generated scenario test passed via php artisan test')
    } catch {
        $phpunitBat = Join-Path $appPath 'vendor\bin\phpunit.bat'
        $phpunit = Join-Path $appPath 'vendor\bin\phpunit'
        $pestBat = Join-Path $appPath 'vendor\bin\pest.bat'
        $pest = Join-Path $appPath 'vendor\bin\pest'

        if (Test-Path -LiteralPath $pestBat) {
            Invoke-LoggedCommand `
                -FilePath $pestBat `
                -Arguments @("--filter=$generatedScenarioTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated scenario test through vendor/bin/pest fallback' | Out-Null
            $proof.Add('generated scenario test passed via vendor/bin/pest fallback')
        } elseif (Test-Path -LiteralPath $pest) {
            Invoke-LoggedCommand `
                -FilePath $php `
                -Arguments @($pest, "--filter=$generatedScenarioTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated scenario test through vendor/bin/pest fallback' | Out-Null
            $proof.Add('generated scenario test passed via vendor/bin/pest fallback')
        } elseif (Test-Path -LiteralPath $phpunitBat) {
            Invoke-LoggedCommand `
                -FilePath $phpunitBat `
                -Arguments @("--filter=$generatedScenarioTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated scenario test through vendor/bin/phpunit fallback' | Out-Null
            $proof.Add('generated scenario test passed via vendor/bin/phpunit fallback')
        } elseif (Test-Path -LiteralPath $phpunit) {
            Invoke-LoggedCommand `
                -FilePath $php `
                -Arguments @($phpunit, "--filter=$generatedScenarioTestFilter") `
                -WorkingDirectory $appPath `
                -Label 'Run generated scenario test through vendor/bin/phpunit fallback' | Out-Null
            $proof.Add('generated scenario test passed via vendor/bin/phpunit fallback')
        } else {
            throw
        }
    }

    Write-Host ""
    Write-Host 'Smoke proof summary:'
    foreach ($item in $proof) {
        Write-Host "- $item"
    }
    Write-Host "- consumer app: $appPath"
} finally {
    if ($KeepWorkDir) {
        Write-Host ""
        Write-Host "Keeping smoke work directory: $workRoot"
    } elseif (Test-Path -LiteralPath $workRoot) {
        $safeWorkRoot = Assert-SafeSmokeDirectory -Path $workRoot -PackageRoot $packageRoot
        Remove-Item -LiteralPath $safeWorkRoot -Recurse -Force
        Write-Host ""
        Write-Host "Deleted smoke work directory: $safeWorkRoot"
    }
}
