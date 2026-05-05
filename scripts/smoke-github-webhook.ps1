[CmdletBinding()]
param(
    [string] $Repo = 'oxhq/preview',

    [ValidateSet('cloudflare')]
    [string] $Transport = 'cloudflare',

    [string] $LocalUrl = 'http://127.0.0.1:8000',

    [ValidateRange(20, 86400)]
    [int] $HoldSeconds = 60,

    [ValidateRange(1, 20)]
    [int] $Attempts = 8,

    [string] $PackageRoot = '',

    [string] $StoragePath = '',

    [switch] $KeepWorkDir,

    [switch] $RequireDns
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Smoke {
    param([string] $Message)

    Write-Host "[github-webhook-smoke] $Message"
}

function Write-Failure {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Message,

        [int] $ExitCode = 1
    )

    Write-Error $Message
    exit $ExitCode
}

function Get-FullPath {
    param([Parameter(Mandatory = $true)][string] $Path)

    return [System.IO.Path]::GetFullPath($Path)
}

function Assert-SafeSmokeDirectory {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,

        [Parameter(Mandatory = $true)]
        [string] $PackageRoot,

        [Parameter(Mandatory = $true)]
        [string] $Label
    )

    $fullPath = Get-FullPath -Path $Path
    $parent = [System.IO.Directory]::GetParent($fullPath)

    if ($null -eq $parent) {
        throw "Refusing to use filesystem root [$fullPath] as $Label."
    }

    $packageFull = Get-FullPath -Path $PackageRoot
    $currentFull = Get-FullPath -Path (Get-Location).Path

    if ($fullPath.TrimEnd('\') -ieq $packageFull.TrimEnd('\')) {
        throw "Refusing to use package root [$packageFull] as $Label."
    }

    if ($fullPath.TrimEnd('\') -ieq $currentFull.TrimEnd('\')) {
        throw "Refusing to use current directory [$currentFull] as $Label."
    }

    if ([string]::IsNullOrWhiteSpace([System.IO.Path]::GetFileName($fullPath))) {
        throw "Refusing to use unsafe $Label [$fullPath]."
    }

    return $fullPath
}

function Get-CommandPath {
    param([Parameter(Mandatory = $true)][string] $Name)

    if (Test-Path -LiteralPath $Name -PathType Leaf) {
        return (Resolve-Path -LiteralPath $Name).Path
    }

    $command = Get-Command $Name -ErrorAction SilentlyContinue

    if ($null -eq $command) {
        return $null
    }

    return $command.Source
}

function Redact-SmokeOutput {
    param(
        [AllowNull()]
        [string] $Text,

        [AllowNull()]
        [string] $Secret
    )

    if ([string]::IsNullOrEmpty($Text)) {
        return ''
    }

    $redacted = $Text

    if (-not [string]::IsNullOrWhiteSpace($Secret)) {
        $redacted = $redacted.Replace($Secret, '[redacted-github-webhook-secret]')
    }

    $redacted = $redacted -replace 'sha256=[a-f0-9]{64}', 'sha256=[redacted]'
    $redacted = $redacted -replace '(?i)(authorization:\s*bearer\s+)[^\s]+', '$1[redacted]'
    $redacted = $redacted -replace '(?i)("secret"\s*:\s*")[^"]+(")', '$1[redacted]$2'

    return $redacted
}

function Receive-JobText {
    param([Parameter(Mandatory = $true)][System.Management.Automation.Job] $Job)

    $jobErrors = @()
    $output = Receive-Job -Job $Job -Keep -ErrorVariable jobErrors -ErrorAction SilentlyContinue
    $lines = @()

    foreach ($line in $output) {
        $lines += [string] $line
    }

    foreach ($line in $jobErrors) {
        $lines += [string] $line
    }

    return ($lines | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine
}

function Stop-SmokeJob {
    param([AllowNull()][System.Management.Automation.Job] $Job)

    if ($null -eq $Job) {
        return
    }

    if ($Job.State -eq 'Running') {
        Stop-Job -Job $Job -ErrorAction SilentlyContinue
    }

    Remove-Job -Job $Job -Force -ErrorAction SilentlyContinue
}

function Get-CaptureUrl {
    param([AllowEmptyString()][string] $Output)

    if ([string]::IsNullOrEmpty($Output)) {
        return $null
    }

    $urlMatches = [regex]::Matches(
        $Output,
        '(?im)^\s*(?:Capture URL|Public URL)\s*:\s*(https?://[^\s<>"'']+)\s*$'
    )

    if ($urlMatches.Count -eq 0) {
        return $null
    }

    return $urlMatches[$urlMatches.Count - 1].Groups[1].Value.TrimEnd('.', ',', ';')
}

function Wait-ForCaptureUrl {
    param(
        [Parameter(Mandatory = $true)]
        [System.Management.Automation.Job] $Job,

        [Parameter(Mandatory = $true)]
        [int] $TimeoutSeconds
    )

    $deadline = [DateTimeOffset]::UtcNow.AddSeconds($TimeoutSeconds)

    while ([DateTimeOffset]::UtcNow -lt $deadline) {
        $output = Receive-JobText -Job $Job
        $captureUrl = Get-CaptureUrl -Output $output

        if (-not [string]::IsNullOrWhiteSpace($captureUrl)) {
            return $captureUrl
        }

        if ($Job.State -ne 'Running') {
            break
        }

        Start-Sleep -Milliseconds 500
    }

    return $null
}

function Wait-TcpEndpoint {
    param(
        [Parameter(Mandatory = $true)]
        [string] $HostName,

        [Parameter(Mandatory = $true)]
        [int] $Port,

        [Parameter(Mandatory = $true)]
        [int] $TimeoutSeconds
    )

    $deadline = [DateTimeOffset]::UtcNow.AddSeconds($TimeoutSeconds)

    while ([DateTimeOffset]::UtcNow -lt $deadline) {
        $client = [System.Net.Sockets.TcpClient]::new()

        try {
            $async = $client.BeginConnect($HostName, $Port, $null, $null)

            if ($async.AsyncWaitHandle.WaitOne(500)) {
                $client.EndConnect($async)
                return $true
            }
        } catch {
            Start-Sleep -Milliseconds 250
        } finally {
            $client.Close()
        }
    }

    return $false
}

function Invoke-TestbenchJson {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Php,

        [Parameter(Mandatory = $true)]
        [string] $Testbench,

        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,

        [Parameter(Mandatory = $true)]
        [string] $WorkingDirectory,

        [Parameter(Mandatory = $true)]
        [string] $Secret
    )

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
        throw "testbench $($Arguments -join ' ') failed with exit code $exitCode.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output -Secret $Secret)"
    }

    try {
        return $output | ConvertFrom-Json
    } catch {
        throw "testbench $($Arguments -join ' ') did not return valid JSON.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output -Secret $Secret)"
    }
}

function Invoke-GhJson {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Gh,

        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,

        [Parameter(Mandatory = $true)]
        [string] $Secret
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $outputObjects = & $Gh @Arguments 2>&1
        $exitCode = if ($null -eq (Get-Variable -Name LASTEXITCODE -ErrorAction SilentlyContinue)) { 0 } else { [int] $LASTEXITCODE }
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $output = ($outputObjects | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine

    if ($exitCode -ne 0) {
        throw "gh $($Arguments -join ' ') failed with exit code $exitCode.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output -Secret $Secret)"
    }

    if ([string]::IsNullOrWhiteSpace($output)) {
        return $null
    }

    try {
        return $output | ConvertFrom-Json
    } catch {
        throw "gh $($Arguments -join ' ') did not return valid JSON.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output -Secret $Secret)"
    }
}

function Invoke-GhSilent {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Gh,

        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,

        [Parameter(Mandatory = $true)]
        [string] $Secret
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $outputObjects = & $Gh @Arguments 2>&1
        $exitCode = if ($null -eq (Get-Variable -Name LASTEXITCODE -ErrorAction SilentlyContinue)) { 0 } else { [int] $LASTEXITCODE }
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        $output = ($outputObjects | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine
        throw "gh $($Arguments -join ' ') failed with exit code $exitCode.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output -Secret $Secret)"
    }
}

function New-WebhookSecret {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()

    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }

    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-MatchingCapture {
    param([AllowNull()][object] $Captures)

    return @($Captures | Where-Object {
        $_.provider -eq 'github' -and $_.event_type -eq 'ping' -and $_.verified -eq $true
    }) | Select-Object -First 1
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
$testbench = Join-Path $resolvedPackageRoot 'vendor/bin/testbench'
$serverJob = $null
$tunnelJob = $null
$hookId = $null
$webhookSecret = New-WebhookSecret
$storagePathWasProvided = -not [string]::IsNullOrWhiteSpace($StoragePath)
$workDir = Join-Path ([System.IO.Path]::GetTempPath()) ('preview-github-webhook-smoke-' + [Guid]::NewGuid().ToString('N'))
$safeWorkDir = Assert-SafeSmokeDirectory -Path $workDir -PackageRoot $resolvedPackageRoot -Label 'smoke work directory'

if (-not $storagePathWasProvided) {
    $StoragePath = Join-Path $safeWorkDir 'captures'
}

$resolvedStoragePath = Assert-SafeSmokeDirectory -Path $StoragePath -PackageRoot $resolvedPackageRoot -Label 'capture storage path'

$previousStoragePath = [Environment]::GetEnvironmentVariable('PREVIEW_STORAGE_PATH', 'Process')
$previousLiveEnabled = [Environment]::GetEnvironmentVariable('PREVIEW_LIVE_ENABLED', 'Process')
$previousGithubSecret = [Environment]::GetEnvironmentVariable('PREVIEW_GITHUB_WEBHOOK_SECRET', 'Process')
$previousCloudflaredBinary = [Environment]::GetEnvironmentVariable('PREVIEW_CLOUDFLARED_BINARY', 'Process')

try {
    if (-not (Test-Path -LiteralPath $testbench -PathType Leaf)) {
        Write-Failure "Missing vendor/bin/testbench under [$resolvedPackageRoot]. Run Composer install before running this smoke script."
    }

    $php = Get-CommandPath -Name 'php'
    $gh = Get-CommandPath -Name 'gh'
    $cloudflared = Get-CommandPath -Name ($(if ([string]::IsNullOrWhiteSpace($env:PREVIEW_CLOUDFLARED_BINARY)) { 'cloudflared' } else { $env:PREVIEW_CLOUDFLARED_BINARY }))

    if ($null -eq $php) {
        Write-Failure 'Missing [php] on PATH. The GitHub webhook smoke needs PHP to run vendor/bin/testbench.'
    }

    if ($null -eq $gh) {
        Write-Failure 'Missing [gh] on PATH. Install GitHub CLI and authenticate before running this smoke.'
    }

    if ($null -eq $cloudflared) {
        Write-Failure 'Missing [cloudflared] on PATH. Install cloudflared or set PREVIEW_CLOUDFLARED_BINARY.'
    }

    try {
        $localUri = [Uri] $LocalUrl
    } catch {
        Write-Failure "LocalUrl [$LocalUrl] is not a valid URL."
    }

    if ($localUri.Scheme -notin @('http', 'https')) {
        Write-Failure "LocalUrl [$LocalUrl] must use http or https."
    }

    $serverHost = if ([string]::IsNullOrWhiteSpace($localUri.Host)) { '127.0.0.1' } else { $localUri.Host }
    $serverPort = if ($localUri.Port -gt 0) { $localUri.Port } elseif ($localUri.Scheme -eq 'https') { 443 } else { 80 }

    New-Item -ItemType Directory -Path $safeWorkDir, $resolvedStoragePath -Force | Out-Null

    [Environment]::SetEnvironmentVariable('PREVIEW_STORAGE_PATH', $resolvedStoragePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_LIVE_ENABLED', 'true', 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_GITHUB_WEBHOOK_SECRET', $webhookSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_CLOUDFLARED_BINARY', $cloudflared, 'Process')

    Write-Smoke "Repo: $Repo"
    Write-Smoke "PackageRoot: $resolvedPackageRoot"
    Write-Smoke "StoragePath: $resolvedStoragePath"
    Write-Smoke 'Webhook secret is process-local and will not be printed.'

    $repoInfo = Invoke-GhJson -Gh $gh -Secret $webhookSecret -Arguments @(
        'api',
        "repos/$Repo",
        '--jq',
        '{full_name,permissions}'
    )

    if ($repoInfo.permissions.admin -ne $true) {
        Write-Failure "Current gh auth does not have admin permission on [$Repo]."
    }

    Write-Smoke 'GitHub CLI auth has repository admin permission.'
    Write-Smoke "Starting Testbench server at [$LocalUrl]."

    $serverJob = Start-Job -Name 'preview-github-webhook-server' -ArgumentList @(
        $resolvedPackageRoot,
        $php,
        $testbench,
        $serverHost,
        $serverPort,
        $resolvedStoragePath,
        $webhookSecret
    ) -ScriptBlock {
        param(
            [string] $WorkingDirectory,
            [string] $Php,
            [string] $Testbench,
            [string] $ServerHost,
            [int] $ServerPort,
            [string] $CaptureStoragePath,
            [string] $WebhookSecret
        )

        Set-StrictMode -Version Latest
        $ErrorActionPreference = 'Stop'
        $env:PREVIEW_STORAGE_PATH = $CaptureStoragePath
        $env:PREVIEW_GITHUB_WEBHOOK_SECRET = $WebhookSecret

        Set-Location -LiteralPath $WorkingDirectory
        & $Php $Testbench 'serve' '--no-reload' "--host=$ServerHost" "--port=$ServerPort" 2>&1
    }

    if (-not (Wait-TcpEndpoint -HostName $serverHost -Port $serverPort -TimeoutSeconds 20)) {
        $serverOutput = Redact-SmokeOutput -Text (Receive-JobText -Job $serverJob) -Secret $webhookSecret
        Write-Failure "Testbench server did not accept TCP connections at [$serverHost`:$serverPort].$([Environment]::NewLine)$serverOutput"
    }

    if ($serverJob.State -ne 'Running') {
        $serverOutput = Redact-SmokeOutput -Text (Receive-JobText -Job $serverJob) -Secret $webhookSecret
        Write-Failure "Testbench server failed to stay open. Job state: $($serverJob.State).$([Environment]::NewLine)$serverOutput"
    }

    Write-Smoke 'Testbench server is running.'
    Write-Smoke 'Opening cloudflared GitHub capture URL.'

    $tunnelJob = Start-Job -Name 'preview-github-webhook-tunnel' -ArgumentList @(
        $resolvedPackageRoot,
        $php,
        $testbench,
        $LocalUrl,
        $HoldSeconds,
        $resolvedStoragePath,
        $webhookSecret,
        $cloudflared
    ) -ScriptBlock {
        param(
            [string] $WorkingDirectory,
            [string] $Php,
            [string] $Testbench,
            [string] $ForwardLocalUrl,
            [int] $TunnelHoldSeconds,
            [string] $CaptureStoragePath,
            [string] $WebhookSecret,
            [string] $Cloudflared
        )

        Set-StrictMode -Version Latest
        $ErrorActionPreference = 'Stop'
        $env:PREVIEW_LIVE_ENABLED = 'true'
        $env:PREVIEW_STORAGE_PATH = $CaptureStoragePath
        $env:PREVIEW_GITHUB_WEBHOOK_SECRET = $WebhookSecret
        $env:PREVIEW_CLOUDFLARED_BINARY = $Cloudflared

        Set-Location -LiteralPath $WorkingDirectory
        & $Php $Testbench 'preview:capture' 'github' '--transport=cloudflare' "--local-url=$ForwardLocalUrl" '--live' "--hold-seconds=$TunnelHoldSeconds" 2>&1

        if ($LASTEXITCODE -ne 0) {
            throw "testbench preview:capture exited with code $LASTEXITCODE"
        }
    }

    $urlTimeout = [Math]::Max(5, [Math]::Min(30, $HoldSeconds - 5))
    $captureUrl = Wait-ForCaptureUrl -Job $tunnelJob -TimeoutSeconds $urlTimeout

    if ([string]::IsNullOrWhiteSpace($captureUrl)) {
        $tunnelOutput = Redact-SmokeOutput -Text (Receive-JobText -Job $tunnelJob) -Secret $webhookSecret
        Write-Failure "Tunnel capture did not produce a Capture URL within $urlTimeout second(s).$([Environment]::NewLine)$tunnelOutput"
    }

    Write-Smoke "Capture URL parsed: $captureUrl"

    if ($RequireDns.IsPresent) {
        $captureUri = [Uri] $captureUrl
        $resolved = $false
        $lastDnsError = ''

        for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
            try {
                $null = Resolve-DnsName -Name $captureUri.Host -ErrorAction Stop
                $resolved = $true
                Write-Smoke "DNS resolved: $($captureUri.Host)"
                break
            } catch {
                $lastDnsError = $_.Exception.Message

                if ($attempt -lt $Attempts) {
                    Start-Sleep -Seconds 2
                }
            }
        }

        if (-not $resolved) {
            Write-Failure "DNS did not resolve for [$($captureUri.Host)] after $Attempts attempt(s). $lastDnsError"
        }
    }

    Write-Smoke 'Creating temporary GitHub repository webhook.'

    $hook = Invoke-GhJson -Gh $gh -Secret $webhookSecret -Arguments @(
        'api',
        '--method',
        'POST',
        "repos/$Repo/hooks",
        '-f',
        'name=web',
        '-F',
        'active=true',
        '-F',
        'events[]=push',
        '-f',
        "config[url]=$captureUrl",
        '-f',
        'config[content_type]=json',
        '-f',
        "config[secret]=$webhookSecret",
        '-f',
        'config[insecure_ssl]=0'
    )

    $hookId = [string] $hook.id

    if ([string]::IsNullOrWhiteSpace($hookId)) {
        Write-Failure 'GitHub webhook was created but no hook id was returned.'
    }

    Write-Smoke "Temporary webhook created: $hookId"
    Write-Smoke 'Requesting GitHub webhook ping delivery.'

    Invoke-GhSilent -Gh $gh -Secret $webhookSecret -Arguments @(
        'api',
        '--method',
        'POST',
        "repos/$Repo/hooks/$hookId/pings",
        '--silent'
    )

    $matchingCapture = $null

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        $captures = Invoke-TestbenchJson `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:capture:list', '--json') `
            -WorkingDirectory $resolvedPackageRoot `
            -Secret $webhookSecret

        $matchingCapture = Get-MatchingCapture -Captures $captures

        if ($null -ne $matchingCapture) {
            break
        }

        if ($attempt -lt $Attempts) {
            Start-Sleep -Seconds 3
        }
    }

    if ($null -eq $matchingCapture) {
        Write-Failure "No verified GitHub ping capture was found in [$resolvedStoragePath] after $Attempts attempt(s)."
    }

    Write-Smoke "Verified GitHub ping capture stored: $($matchingCapture.id)"
    Write-Smoke 'GitHub webhook delivery smoke completed.'
    exit 0
} finally {
    if (-not [string]::IsNullOrWhiteSpace($hookId)) {
        try {
            $ghPath = Get-CommandPath -Name 'gh'

            if ($null -ne $ghPath) {
                Invoke-GhSilent -Gh $ghPath -Secret $webhookSecret -Arguments @(
                    'api',
                    '--method',
                    'DELETE',
                    "repos/$Repo/hooks/$hookId",
                    '--silent'
                )

                Write-Smoke "Temporary webhook deleted: $hookId"
            }
        } catch {
            Write-Smoke "Failed to delete temporary webhook [$hookId]. Delete it manually from [$Repo]."
        }
    }

    Stop-SmokeJob -Job $tunnelJob
    Stop-SmokeJob -Job $serverJob

    [Environment]::SetEnvironmentVariable('PREVIEW_STORAGE_PATH', $previousStoragePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_LIVE_ENABLED', $previousLiveEnabled, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_GITHUB_WEBHOOK_SECRET', $previousGithubSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_CLOUDFLARED_BINARY', $previousCloudflaredBinary, 'Process')

    if ($KeepWorkDir.IsPresent) {
        Write-Smoke "Keeping smoke work directory: $safeWorkDir"
    } elseif (Test-Path -LiteralPath $safeWorkDir) {
        $safeWorkDir = Assert-SafeSmokeDirectory -Path $safeWorkDir -PackageRoot $resolvedPackageRoot -Label 'smoke work directory'
        Remove-Item -LiteralPath $safeWorkDir -Recurse -Force
        Write-Smoke "Deleted smoke work directory: $safeWorkDir"
    }
}
