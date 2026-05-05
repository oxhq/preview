[CmdletBinding()]
param(
    [string] $Version = '',
    [string] $WorkDir = (Join-Path ([System.IO.Path]::GetTempPath()) 'preview-packagist-smoke'),
    [string] $LaravelVersion = '^12.0',
    [switch] $KeepWorkDir
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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
        [string] $Path
    )

    $fullPath = Get-FullPath -Path $Path
    $parent = [System.IO.Directory]::GetParent($fullPath)

    if ($null -eq $parent) {
        throw "Refusing to use filesystem root [$fullPath] as smoke work directory."
    }

    $currentFull = Get-FullPath -Path (Get-Location).Path

    if ($fullPath.TrimEnd('\') -ieq $currentFull.TrimEnd('\')) {
        throw "Refusing to use current directory [$currentFull] as smoke work directory."
    }

    if ([string]::IsNullOrWhiteSpace([System.IO.Path]::GetFileName($fullPath))) {
        throw "Refusing to use unsafe smoke work directory [$fullPath]."
    }

    return $fullPath
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

function Get-CommandPath {
    param(
        [Parameter(Mandatory)]
        [string] $Name
    )

    $command = Get-Command $Name -ErrorAction Stop

    return $command.Source
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

Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '/preview-packagist-smoke', function () {
    return response()->json(['ok' => true]);
});
'@

    Add-Content -LiteralPath $routesPath -Value $route
}

$workRoot = Assert-SafeSmokeDirectory -Path $WorkDir
$appPath = Join-Path $workRoot 'app'
$scriptRoot = if ($PSScriptRoot -ne '') {
    $PSScriptRoot
} else {
    Split-Path -Parent $MyInvocation.MyCommand.Path
}
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptRoot '..'))
$packageRequirement = if ([string]::IsNullOrWhiteSpace($Version)) {
    'oxhq/preview'
} else {
    "oxhq/preview:$Version"
}
$proof = [System.Collections.Generic.List[string]]::new()

try {
    $composer = Get-CommandPath -Name 'composer'
    $php = Get-CommandPath -Name 'php'

    Write-Host "WorkDir: $workRoot"
    Write-Host "LaravelVersion: $LaravelVersion"
    Write-Host "PackageRequirement: $packageRequirement"
    Write-Host "Composer: $composer"
    Write-Host "PHP: $php"

    $packagistCheckArguments = @('scripts/check-packagist.php')
    if ([string]::IsNullOrWhiteSpace($Version)) {
        $packagistCheckArguments += '--package-only'
    } else {
        $packagistCheckArguments += $Version
    }

    Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments $packagistCheckArguments `
        -WorkingDirectory $repoRoot `
        -Label 'Check Packagist package visibility before install' | Out-Null
    $proof.Add('Packagist metadata check passed before install')

    if (Test-Path -LiteralPath $workRoot) {
        Remove-Item -LiteralPath $workRoot -Recurse -Force
    }

    New-Item -ItemType Directory -Path $workRoot -Force | Out-Null

    Invoke-LoggedCommand `
        -FilePath $composer `
        -Arguments @('create-project', 'laravel/laravel', $appPath, $LaravelVersion, '--no-interaction', '--prefer-dist') `
        -WorkingDirectory $workRoot `
        -Label 'Create fresh Laravel app' | Out-Null
    $proof.Add("fresh Laravel app created at [$appPath]")

    Add-SmokeRoute -AppPath $appPath
    $proof.Add('smoke route registered at [/preview-packagist-smoke]')

    Invoke-LoggedCommand `
        -FilePath $composer `
        -Arguments @('require', '--dev', $packageRequirement, '--no-interaction', '--with-all-dependencies') `
        -WorkingDirectory $appPath `
        -Label 'Require oxhq/preview from Packagist' | Out-Null
    $proof.Add("Packagist package installed as [$packageRequirement]")

    Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @('artisan', 'preview:doctor') `
        -WorkingDirectory $appPath `
        -Label 'Run Preview doctor' | Out-Null
    $proof.Add('preview:doctor completed')

    $captureOutput = Invoke-LoggedCommand `
        -FilePath $php `
        -Arguments @(
            'artisan',
            'preview:capture',
            'generic',
            '--path=/preview-packagist-smoke',
            '--header=X-Preview-Event: preview.packagist',
            '--body={"ok":true}'
        ) `
        -WorkingDirectory $appPath `
        -Label 'Run Packagist synthetic generic capture'

    $captureMatch = [regex]::Match($captureOutput, 'Captured \[(?<id>[^\]]+)\]')

    if (-not $captureMatch.Success) {
        throw 'Could not parse capture ID from preview:capture output.'
    }

    $captureId = $captureMatch.Groups['id'].Value
    $proof.Add("synthetic capture generated ID [$captureId]")

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
        -Label 'Generate test for capture' | Out-Null
    $proof.Add("capture:test generated test for [$captureId]")

    Write-Host ""
    Write-Host 'Packagist smoke proof summary:'
    foreach ($item in $proof) {
        Write-Host "- $item"
    }
    Write-Host "- consumer app: $appPath"
} finally {
    if ($KeepWorkDir) {
        Write-Host ""
        Write-Host "Keeping smoke work directory: $workRoot"
    } elseif (Test-Path -LiteralPath $workRoot) {
        $safeWorkRoot = Assert-SafeSmokeDirectory -Path $workRoot
        Remove-Item -LiteralPath $safeWorkRoot -Recurse -Force
        Write-Host ""
        Write-Host "Deleted smoke work directory: $safeWorkRoot"
    }
}
