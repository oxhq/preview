[CmdletBinding()]
param(
    [ValidateSet('cloudflare', 'ngrok')]
    [string] $Transport = 'cloudflare',

    [string] $LocalUrl = 'http://127.0.0.1:8000',

    [ValidateRange(0, 86400)]
    [int] $HoldSeconds = 8,

    [ValidateRange(1, 5)]
    [int] $Attempts = 3,

    [switch] $RequireDns,

    [string] $PackageRoot = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Failure {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message,

        [int] $ExitCode = 1
    )

    Write-Error $Message
    exit $ExitCode
}

function Get-CaptureUrl {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Output
    )

    $urlMatches = [regex]::Matches(
        $Output,
        '(?im)^\s*(?:Capture URL|Public URL)\s*:\s*(https?://\S+)\s*$'
    )

    if ($urlMatches.Count -eq 0) {
        return $null
    }

    return $urlMatches[$urlMatches.Count - 1].Groups[1].Value.TrimEnd('.', ',', ';')
}

$scriptRoot = if ($PSScriptRoot -ne '') {
    $PSScriptRoot
} else {
    Split-Path -Parent $MyInvocation.MyCommand.Path
}

if ($PackageRoot -eq '') {
    $PackageRoot = Join-Path $scriptRoot '..'
}

$resolvedPackageRoot = (Resolve-Path -LiteralPath $PackageRoot).Path
$previousLiveEnabled = [Environment]::GetEnvironmentVariable('PREVIEW_LIVE_ENABLED', 'Process')

Push-Location -LiteralPath $resolvedPackageRoot

try {
    $testbench = Join-Path $resolvedPackageRoot 'vendor/bin/testbench'

    if (-not (Test-Path -LiteralPath $testbench -PathType Leaf)) {
        Write-Failure "Missing vendor/bin/testbench. Run composer install from [$resolvedPackageRoot], then retry."
    }

    $php = $null
    if ($env:OS -eq 'Windows_NT') {
        $php = Get-Command php -ErrorAction SilentlyContinue

        if ($null -eq $php) {
            Write-Failure 'Missing [php] on PATH. Composer bin proxies require PHP to run vendor/bin/testbench on Windows.'
        }
    }

    $binaryName = if ($Transport -eq 'cloudflare') { 'cloudflared' } else { 'ngrok' }
    $binary = Get-Command $binaryName -ErrorAction SilentlyContinue

    if ($null -eq $binary) {
        Write-Failure "Missing [$binaryName] on PATH. Install it locally or add it to PATH, then retry."
    }

    [Environment]::SetEnvironmentVariable('PREVIEW_LIVE_ENABLED', 'true', 'Process')

    $arguments = @(
        'preview:capture'
        'generic'
        "--transport=$Transport"
        "--local-url=$LocalUrl"
        '--live'
        "--hold-seconds=$HoldSeconds"
    )

    Write-Host "Starting [$Transport] tunnel smoke for [$LocalUrl]. This proves startup and URL capture only."

    $output = ''
    $commandExitCode = 1
    $captureUrl = $null

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        if ($Attempts -gt 1) {
            Write-Host "Attempt $attempt of $Attempts."
        }

        $previousErrorActionPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'

        try {
            if ($null -eq $php) {
                $outputObjects = & $testbench @arguments 2>&1
            } else {
                $outputObjects = & $php $testbench @arguments 2>&1
            }

            $lastExitCodeVariable = Get-Variable -Name LASTEXITCODE -ErrorAction SilentlyContinue
            $commandExitCode = if ($null -eq $lastExitCodeVariable) { 0 } else { [int] $lastExitCodeVariable.Value }
        } finally {
            $ErrorActionPreference = $previousErrorActionPreference
        }

        $output = ($outputObjects | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine

        if ($output -ne '') {
            Write-Host $output
        }

        $captureUrl = Get-CaptureUrl $output

        if ($commandExitCode -eq 0 -and $null -ne $captureUrl -and $captureUrl -ne '') {
            break
        }

        if ($attempt -lt $Attempts) {
            Write-Host "Tunnel startup did not produce a usable URL on attempt $attempt; retrying."
            Start-Sleep -Seconds 2
        }
    }

    if ($commandExitCode -ne 0) {
        Write-Failure "Tunnel smoke command failed after $Attempts attempt(s) with exit code [$commandExitCode]." $commandExitCode
    }

    if ($null -eq $captureUrl -or $captureUrl -eq '') {
        Write-Failure "Tunnel smoke command completed after $Attempts attempt(s), but no Capture URL or Public URL was found in output."
    }

    Write-Host "Smoke URL: $captureUrl"

    if ($RequireDns.IsPresent) {
        $uri = [Uri] $captureUrl
        $hostname = $uri.Host

        if ($hostname -eq '') {
            Write-Failure "Unable to parse hostname from smoke URL [$captureUrl]."
        }

        try {
            $null = Resolve-DnsName -Name $hostname -ErrorAction Stop
            Write-Host "DNS resolved: $hostname"
        } catch {
            Write-Failure "DNS did not resolve for [$hostname]. $($_.Exception.Message)"
        }
    }

    Write-Host 'Startup smoke completed. This did not prove webhook delivery or provider traffic.'
    exit 0
} finally {
    if ($null -eq $previousLiveEnabled) {
        [Environment]::SetEnvironmentVariable('PREVIEW_LIVE_ENABLED', $null, 'Process')
    } else {
        [Environment]::SetEnvironmentVariable('PREVIEW_LIVE_ENABLED', $previousLiveEnabled, 'Process')
    }

    Pop-Location
}
