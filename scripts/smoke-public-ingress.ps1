[CmdletBinding()]
param(
    [ValidateSet('cloudflare', 'ngrok')]
    [string] $Transport = 'cloudflare',

    [string] $LocalUrl = 'http://127.0.0.1:8000',

    [ValidateRange(10, 86400)]
    [int] $HoldSeconds = 45,

    [ValidateRange(1, 20)]
    [int] $Attempts = 6,

    [string] $PackageRoot = '',

    [string] $StoragePath = '',

    [switch] $KeepWorkDir,

    [switch] $RequireDns
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Smoke {
    param([string] $Message)

    Write-Host "[public-ingress-smoke] $Message"
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
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path
    )

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
    param(
        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    if (Test-Path -LiteralPath $Name -PathType Leaf) {
        return (Resolve-Path -LiteralPath $Name).Path
    }

    $command = Get-Command $Name -ErrorAction SilentlyContinue

    if ($null -eq $command) {
        return $null
    }

    return $command.Source
}

function Get-TransportBinaryPath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Transport
    )

    if ($Transport -eq 'cloudflare') {
        $candidate = $env:PREVIEW_CLOUDFLARED_BINARY

        if ([string]::IsNullOrWhiteSpace($candidate)) {
            $candidate = 'cloudflared'
        }

        return Get-CommandPath -Name $candidate
    }

    $candidate = $env:PREVIEW_NGROK_BINARY

    if ([string]::IsNullOrWhiteSpace($candidate)) {
        $candidate = 'ngrok'
    }

    return Get-CommandPath -Name $candidate
}

function Redact-SmokeOutput {
    param([AllowNull()][string] $Text)

    if ([string]::IsNullOrEmpty($Text)) {
        return ''
    }

    $redacted = $Text
    $redacted = $redacted -replace '(?i)(authtoken|token|secret|password)=\S+', '$1=[redacted]'
    $redacted = $redacted -replace '(?i)(authorization:\s*bearer\s+)[^\s]+', '$1[redacted]'
    $redacted = $redacted -replace 'whsec_[A-Za-z0-9_]+', 'whsec_[redacted]'

    return $redacted
}

function Receive-JobText {
    param(
        [Parameter(Mandatory = $true)]
        [System.Management.Automation.Job] $Job
    )

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
    param(
        [AllowEmptyString()]
        [string] $Output
    )

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

function Join-Url {
    param(
        [Parameter(Mandatory = $true)]
        [string] $BaseUrl,

        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    return $BaseUrl.TrimEnd('/') + '/' + $Path.TrimStart('/')
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
        [string] $WorkingDirectory
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
        throw "testbench $($Arguments -join ' ') failed with exit code $exitCode.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output)"
    }

    try {
        return $output | ConvertFrom-Json
    } catch {
        throw "testbench $($Arguments -join ' ') did not return valid JSON.$([Environment]::NewLine)$(Redact-SmokeOutput -Text $output)"
    }
}

function Get-MatchingCapture {
    param(
        [AllowNull()]
        [object] $Captures,

        [Parameter(Mandatory = $true)]
        [string] $Event
    )

    return @($Captures | Where-Object {
        $_.provider -eq 'generic' -and $_.event_type -eq $Event
    }) | Select-Object -First 1
}

function Assert-ParserSelfTest {
    $sample = @'
noise before
Capture URL: https://demo.trycloudflare.com/__preview/capture/generic
noise after
'@

    $parsed = Get-CaptureUrl -Output $sample

    if ($parsed -ne 'https://demo.trycloudflare.com/__preview/capture/generic') {
        throw "Parser self-test failed. Parsed [$parsed]."
    }
}

if ($env:PREVIEW_PUBLIC_INGRESS_PARSER_SELF_TEST -eq '1') {
    Assert-ParserSelfTest
    Write-Smoke 'Parser self-test passed.'
    return
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
$storagePathWasProvided = -not [string]::IsNullOrWhiteSpace($StoragePath)
$workDir = Join-Path ([System.IO.Path]::GetTempPath()) ('preview-public-ingress-smoke-' + [Guid]::NewGuid().ToString('N'))
$safeWorkDir = Assert-SafeSmokeDirectory -Path $workDir -PackageRoot $resolvedPackageRoot -Label 'smoke work directory'

if (-not $storagePathWasProvided) {
    $StoragePath = Join-Path $safeWorkDir 'captures'
}

$resolvedStoragePath = Assert-SafeSmokeDirectory -Path $StoragePath -PackageRoot $resolvedPackageRoot -Label 'capture storage path'

$previousStoragePath = [Environment]::GetEnvironmentVariable('PREVIEW_STORAGE_PATH', 'Process')
$previousLiveEnabled = [Environment]::GetEnvironmentVariable('PREVIEW_LIVE_ENABLED', 'Process')
$previousCloudflaredBinary = [Environment]::GetEnvironmentVariable('PREVIEW_CLOUDFLARED_BINARY', 'Process')
$previousNgrokBinary = [Environment]::GetEnvironmentVariable('PREVIEW_NGROK_BINARY', 'Process')

try {
    Assert-ParserSelfTest

    if (-not (Test-Path -LiteralPath $testbench -PathType Leaf)) {
        Write-Failure "Missing vendor/bin/testbench under [$resolvedPackageRoot]. Run Composer install before running this smoke script."
    }

    $php = Get-CommandPath -Name 'php'

    if ($null -eq $php) {
        Write-Failure 'Missing [php] on PATH. The public ingress smoke needs PHP to run vendor/bin/testbench.'
    }

    $transportBinary = Get-TransportBinaryPath -Transport $Transport
    $transportBinaryName = if ($Transport -eq 'cloudflare') { 'cloudflared' } else { 'ngrok' }

    if ($null -eq $transportBinary) {
        Write-Failure "Missing [$transportBinaryName] on PATH. Install it locally or set the matching PREVIEW_*_BINARY env var, then retry."
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

    if ($Transport -eq 'cloudflare') {
        [Environment]::SetEnvironmentVariable('PREVIEW_CLOUDFLARED_BINARY', $transportBinary, 'Process')
    } else {
        [Environment]::SetEnvironmentVariable('PREVIEW_NGROK_BINARY', $transportBinary, 'Process')
    }

    Write-Smoke "PackageRoot: $resolvedPackageRoot"
    Write-Smoke "StoragePath: $resolvedStoragePath"
    Write-Smoke "Transport: $Transport"
    Write-Smoke "Starting Testbench server at [$LocalUrl]."

    $serverJob = Start-Job -Name 'preview-public-ingress-server' -ArgumentList @(
        $resolvedPackageRoot,
        $php,
        $testbench,
        $serverHost,
        $serverPort,
        $resolvedStoragePath
    ) -ScriptBlock {
        param(
            [string] $WorkingDirectory,
            [string] $Php,
            [string] $Testbench,
            [string] $ServerHost,
            [int] $ServerPort,
            [string] $CaptureStoragePath
        )

        Set-StrictMode -Version Latest
        $ErrorActionPreference = 'Stop'
        $env:PREVIEW_STORAGE_PATH = $CaptureStoragePath

        Set-Location -LiteralPath $WorkingDirectory
        & $Php $Testbench 'serve' '--no-reload' "--host=$ServerHost" "--port=$ServerPort" 2>&1
    }

    if (-not (Wait-TcpEndpoint -HostName $serverHost -Port $serverPort -TimeoutSeconds 20)) {
        $serverOutput = Redact-SmokeOutput -Text (Receive-JobText -Job $serverJob)
        Write-Failure "Testbench server did not accept TCP connections at [$serverHost`:$serverPort].$([Environment]::NewLine)$serverOutput"
    }

    if ($serverJob.State -ne 'Running') {
        $serverOutput = Redact-SmokeOutput -Text (Receive-JobText -Job $serverJob)
        Write-Failure "Testbench server failed to stay open. Job state: $($serverJob.State).$([Environment]::NewLine)$serverOutput"
    }

    Write-Smoke 'Testbench server is running.'
    Write-Smoke 'Opening Preview tunnel capture.'

    $tunnelJob = Start-Job -Name 'preview-public-ingress-tunnel' -ArgumentList @(
        $resolvedPackageRoot,
        $php,
        $testbench,
        $Transport,
        $LocalUrl,
        $HoldSeconds,
        $resolvedStoragePath,
        $transportBinary
    ) -ScriptBlock {
        param(
            [string] $WorkingDirectory,
            [string] $Php,
            [string] $Testbench,
            [string] $TunnelTransport,
            [string] $ForwardLocalUrl,
            [int] $TunnelHoldSeconds,
            [string] $CaptureStoragePath,
            [string] $TransportBinary
        )

        Set-StrictMode -Version Latest
        $ErrorActionPreference = 'Stop'
        $env:PREVIEW_LIVE_ENABLED = 'true'
        $env:PREVIEW_STORAGE_PATH = $CaptureStoragePath

        if ($TunnelTransport -eq 'cloudflare') {
            $env:PREVIEW_CLOUDFLARED_BINARY = $TransportBinary
        } else {
            $env:PREVIEW_NGROK_BINARY = $TransportBinary
        }

        Set-Location -LiteralPath $WorkingDirectory
        & $Php $Testbench 'preview:capture' 'generic' "--transport=$TunnelTransport" "--local-url=$ForwardLocalUrl" '--live' "--hold-seconds=$TunnelHoldSeconds" 2>&1

        if ($LASTEXITCODE -ne 0) {
            throw "testbench preview:capture exited with code $LASTEXITCODE"
        }
    }

    $urlTimeout = [Math]::Max(5, [Math]::Min(30, $HoldSeconds - 3))
    $captureUrl = Wait-ForCaptureUrl -Job $tunnelJob -TimeoutSeconds $urlTimeout

    if ([string]::IsNullOrWhiteSpace($captureUrl)) {
        $tunnelOutput = Redact-SmokeOutput -Text (Receive-JobText -Job $tunnelJob)
        Write-Failure "Tunnel capture did not produce a Capture URL within $urlTimeout second(s).$([Environment]::NewLine)$tunnelOutput"
    }

    Write-Smoke "Capture URL parsed: $captureUrl"

    if ($RequireDns.IsPresent) {
        $captureUri = [Uri] $captureUrl

        try {
            $null = Resolve-DnsName -Name $captureUri.Host -ErrorAction Stop
            Write-Smoke "DNS resolved: $($captureUri.Host)"
        } catch {
            Write-Failure "DNS did not resolve for [$($captureUri.Host)]. $($_.Exception.Message)"
        }
    }

    $event = 'preview.public-ingress.' + [Guid]::NewGuid().ToString('N')
    $body = @{
        smoke = $true
        event = $event
        transport = $Transport
    } | ConvertTo-Json -Compress

    $headers = @{
        'X-Preview-Event' = $event
        'X-Preview-Original-Path' = '/preview-public-ingress-smoke'
        'Content-Type' = 'application/json'
    }

    $postSucceeded = $false
    $responseCaptureId = ''
    $lastPostError = ''

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        try {
            Write-Smoke "POST attempt $attempt of $Attempts to public capture URL."
            $response = Invoke-WebRequest -Uri $captureUrl -Method POST -Headers $headers -Body $body -TimeoutSec 20 -UseBasicParsing

            if ([int] $response.StatusCode -lt 200 -or [int] $response.StatusCode -ge 300) {
                throw "Unexpected HTTP status [$($response.StatusCode)]."
            }

            $responsePayload = $response.Content | ConvertFrom-Json

            if ($responsePayload.provider -ne 'generic') {
                throw "Capture response provider was [$($responsePayload.provider)], expected [generic]."
            }

            if ($responsePayload.event_type -ne $event) {
                throw "Capture response event_type was [$($responsePayload.event_type)], expected [$event]."
            }

            $responseCaptureId = [string] $responsePayload.id
            $postSucceeded = $true
            break
        } catch {
            $lastPostError = Redact-SmokeOutput -Text $_.Exception.Message

            if ($attempt -lt $Attempts) {
                Start-Sleep -Seconds 2
            }
        }
    }

    if (-not $postSucceeded) {
        Write-Failure "Synthetic POST did not reach the public capture URL after $Attempts attempt(s). Last error: $lastPostError"
    }

    Write-Smoke "Synthetic event posted: $event"

    $matchingCapture = $null

    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        $captures = Invoke-TestbenchJson `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:capture:list', '--json') `
            -WorkingDirectory $resolvedPackageRoot

        $candidateCapture = Get-MatchingCapture -Captures $captures -Event $event

        if ($null -ne $candidateCapture -and ([string]::IsNullOrWhiteSpace($responseCaptureId) -or $candidateCapture.id -eq $responseCaptureId)) {
            $matchingCapture = $candidateCapture
            break
        }

        if ($attempt -lt $Attempts) {
            Start-Sleep -Seconds 1
        }
    }

    if ($null -eq $matchingCapture) {
        $captures = Invoke-TestbenchJson `
            -Php $php `
            -Testbench $testbench `
            -Arguments @('preview:capture:list', '--json') `
            -WorkingDirectory $resolvedPackageRoot

        $sameEventCapture = @($captures | Where-Object {
            $_.provider -eq 'generic' -and $_.event_type -eq $event
        }) | Select-Object -First 1

        if ($null -ne $sameEventCapture) {
            Write-Failure "preview:capture:list found event [$event], but capture ID [$($sameEventCapture.id)] did not match HTTP response ID [$responseCaptureId]."
        }

        Write-Failure "preview:capture:list --json did not contain the generic capture for event [$event] in [$resolvedStoragePath]."
    }

    if ($matchingCapture.verified -eq $true) {
        Write-Smoke "Provider verification: verified."
    } else {
        Write-Smoke "Provider verification: $($matchingCapture.verification_message)"
    }

    Write-Smoke "Ingress-verified generic capture stored: $($matchingCapture.id)"
    Write-Smoke 'Public tunnel ingress smoke completed.'
    exit 0
} finally {
    Stop-SmokeJob -Job $tunnelJob
    Stop-SmokeJob -Job $serverJob

    [Environment]::SetEnvironmentVariable('PREVIEW_STORAGE_PATH', $previousStoragePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_LIVE_ENABLED', $previousLiveEnabled, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_CLOUDFLARED_BINARY', $previousCloudflaredBinary, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_NGROK_BINARY', $previousNgrokBinary, 'Process')

    if ($KeepWorkDir.IsPresent) {
        Write-Smoke "Keeping smoke work directory: $safeWorkDir"
    } elseif (Test-Path -LiteralPath $safeWorkDir) {
        $safeWorkDir = Assert-SafeSmokeDirectory -Path $safeWorkDir -PackageRoot $resolvedPackageRoot -Label 'smoke work directory'
        Remove-Item -LiteralPath $safeWorkDir -Recurse -Force
        Write-Smoke "Deleted smoke work directory: $safeWorkDir"
    }
}
