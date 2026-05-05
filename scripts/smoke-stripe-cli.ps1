param(
    [string] $LocalUrl = 'http://127.0.0.1:8000',
    [int] $HoldSeconds = 60,
    [string] $TriggerEvent = '',
    [string] $StripeBinary = '',
    [switch] $StartServer,
    [int] $ServerStartupSeconds = 3,
    [string] $StoragePath = '',
    [string] $PackageRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Info {
    param([string] $Message)

    Write-Host "[stripe-cli-smoke] $Message"
}

function Redact-StripeOutput {
    param([AllowNull()][string] $Text)

    if ([string]::IsNullOrEmpty($Text)) {
        return ''
    }

    $redacted = $Text

    if (-not [string]::IsNullOrEmpty($env:PREVIEW_STRIPE_ENDPOINT_SECRET)) {
        $redacted = $redacted.Replace($env:PREVIEW_STRIPE_ENDPOINT_SECRET, '[redacted-stripe-endpoint-secret]')
    }

    return ($redacted -replace 'whsec_[A-Za-z0-9_]+', 'whsec_[redacted]')
}

function Receive-RedactedJobOutput {
    param([System.Management.Automation.Job] $Job)

    $jobErrors = @()
    $output = Receive-Job -Job $Job -ErrorVariable jobErrors -ErrorAction SilentlyContinue

    foreach ($line in $output) {
        $text = Redact-StripeOutput ([string] $line)

        if ($text -ne '') {
            Write-Host $text
        }
    }

    foreach ($line in $jobErrors) {
        $text = Redact-StripeOutput ([string] $line)

        if ($text -ne '') {
            Write-Host "[listener-error] $text"
        }
    }
}

function Stop-ListenerJob {
    param([System.Management.Automation.Job] $Job)

    if ($Job.State -eq 'Running') {
        Stop-Job -Job $Job
    }
}

function Stop-SmokeJob {
    param([AllowNull()][System.Management.Automation.Job] $Job)

    if ($null -eq $Job) {
        return
    }

    if ($Job.State -eq 'Running') {
        Stop-Job -Job $Job
    }

    Remove-Job -Job $Job -Force -ErrorAction SilentlyContinue
}

function Invoke-TestbenchJson {
    param(
        [string] $Php,
        [string] $Testbench,
        [string[]] $Arguments,
        [string] $WorkingDirectory
    )

    Push-Location -LiteralPath $WorkingDirectory

    try {
        $output = & $Php $Testbench @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    $text = ($output | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine

    if ($exitCode -ne 0) {
        Write-Error "testbench command failed with exit code $exitCode. Output: $(Redact-StripeOutput $text)"
        exit $exitCode
    }

    return $text | ConvertFrom-Json
}

function Resolve-StripeCommand {
    param([string] $ConfiguredBinary)

    $candidate = $ConfiguredBinary

    if ([string]::IsNullOrWhiteSpace($candidate)) {
        $candidate = $env:PREVIEW_STRIPE_CLI_BINARY
    }

    if ([string]::IsNullOrWhiteSpace($candidate)) {
        $candidate = 'stripe'
    }

    if (Test-Path -LiteralPath $candidate -PathType Leaf) {
        return (Resolve-Path -LiteralPath $candidate).Path
    }

    $command = Get-Command $candidate -ErrorAction SilentlyContinue

    if ($null -eq $command) {
        return $null
    }

    return $command.Source
}

function Resolve-StripeEndpointSecret {
    param([string] $StripePath)

    if (-not [string]::IsNullOrWhiteSpace($env:PREVIEW_STRIPE_ENDPOINT_SECRET)) {
        return $env:PREVIEW_STRIPE_ENDPOINT_SECRET
    }

    Write-Info 'PREVIEW_STRIPE_ENDPOINT_SECRET is not set. Asking Stripe CLI for the listener signing secret.'

    $secretOutput = & $StripePath 'listen' '--print-secret' 2>&1
    $secretExitCode = $LASTEXITCODE
    $secretText = ($secretOutput | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine

    if ($secretExitCode -ne 0) {
        Write-Error "Stripe CLI could not print the listener signing secret. Output: $(Redact-StripeOutput $secretText)"
        exit $secretExitCode
    }

    $secretMatch = [regex]::Match($secretText, 'whsec_[A-Za-z0-9_]+')

    if (-not $secretMatch.Success) {
        Write-Error "Stripe CLI did not return a webhook signing secret. Output: $(Redact-StripeOutput $secretText)"
        exit 1
    }

    return $secretMatch.Value
}

$stripePath = Resolve-StripeCommand -ConfiguredBinary $StripeBinary

if ($null -eq $stripePath) {
    Write-Error 'Stripe CLI was not found. Set -StripeBinary or PREVIEW_STRIPE_CLI_BINARY to the stripe executable path, or add stripe to PATH.'
    exit 1
}

$resolvedPackageRoot = Resolve-Path $PackageRoot
$testbench = Join-Path $resolvedPackageRoot.Path 'vendor/bin/testbench'
$serverJob = $null
$previousStoragePath = [Environment]::GetEnvironmentVariable('PREVIEW_STORAGE_PATH', 'Process')
$previousEndpointSecret = [Environment]::GetEnvironmentVariable('PREVIEW_STRIPE_ENDPOINT_SECRET', 'Process')
$previousStripeBinary = [Environment]::GetEnvironmentVariable('PREVIEW_STRIPE_CLI_BINARY', 'Process')

if (-not (Test-Path -LiteralPath $testbench)) {
    Write-Error "Missing vendor/bin/testbench under [$($resolvedPackageRoot.Path)]. Run Composer install for this package before running this smoke script."
    exit 1
}

$testbenchCommand = Get-Command $testbench -ErrorAction SilentlyContinue

if ($null -eq $testbenchCommand) {
    Write-Error "vendor/bin/testbench exists under [$($resolvedPackageRoot.Path)] but is not runnable from this PowerShell shell."
    exit 1
}

$phpCommand = Get-Command php -ErrorAction SilentlyContinue

if ($null -eq $phpCommand) {
    Write-Error 'PHP was not found on PATH. The Stripe CLI smoke needs PHP to run vendor/bin/testbench.'
    exit 1
}

$endpointSecret = Resolve-StripeEndpointSecret -StripePath $stripePath
$env:PREVIEW_STRIPE_ENDPOINT_SECRET = $endpointSecret
$env:PREVIEW_STRIPE_CLI_BINARY = $stripePath

$storagePathWasProvided = -not [string]::IsNullOrWhiteSpace($StoragePath)

if ([string]::IsNullOrWhiteSpace($StoragePath)) {
    $StoragePath = Join-Path ([System.IO.Path]::GetTempPath()) ('preview-stripe-cli-smoke-' + [Guid]::NewGuid().ToString('N'))
}

$resolvedStoragePath = [System.IO.Path]::GetFullPath($StoragePath)
New-Item -ItemType Directory -Path $resolvedStoragePath -Force | Out-Null
$env:PREVIEW_STORAGE_PATH = $resolvedStoragePath

if ($HoldSeconds -lt 0) {
    Write-Error 'HoldSeconds must be zero or greater.'
    exit 1
}

Write-Info "Using Stripe CLI at [$stripePath]."
Write-Info "Using package root [$($resolvedPackageRoot.Path)]."
Write-Info "Using capture storage [$resolvedStoragePath]."

if ($StartServer.IsPresent) {
    $localUri = [Uri] $LocalUrl
    $serverPort = if ($localUri.Port -gt 0) { $localUri.Port } else { 8000 }
    $serverHost = if ([string]::IsNullOrWhiteSpace($localUri.Host)) { '127.0.0.1' } else { $localUri.Host }

    Write-Info "Starting Testbench server at [$($localUri.Scheme)://$serverHost`:$serverPort]."

    $serverJob = Start-Job -Name 'preview-stripe-cli-server' -ArgumentList @(
        $resolvedPackageRoot.Path,
        $phpCommand.Source,
        $testbenchCommand.Source,
        $serverHost,
        $serverPort,
        $endpointSecret,
        $stripePath,
        $resolvedStoragePath
    ) -ScriptBlock {
        param(
            [string] $WorkingDirectory,
            [string] $Php,
            [string] $Testbench,
            [string] $ServerHost,
            [int] $ServerPort,
            [string] $EndpointSecret,
            [string] $StripePath,
            [string] $CaptureStoragePath
        )

        Set-StrictMode -Version Latest
        $ErrorActionPreference = 'Stop'
        $env:PREVIEW_STRIPE_ENDPOINT_SECRET = $EndpointSecret
        $env:PREVIEW_STRIPE_CLI_BINARY = $StripePath
        $env:PREVIEW_STORAGE_PATH = $CaptureStoragePath

        Set-Location -LiteralPath $WorkingDirectory
        & $Php $Testbench 'serve' '--no-reload' "--host=$ServerHost" "--port=$ServerPort" 2>&1
    }

    Start-Sleep -Seconds $ServerStartupSeconds

    if ($serverJob.State -ne 'Running') {
        Receive-RedactedJobOutput -Job $serverJob
        Write-Error "Testbench server failed to stay open. Job state: $($serverJob.State)."
        exit 1
    }

    Write-Info 'Testbench server is running.'
}

Write-Info "Starting Stripe CLI listener through testbench."

$listenerJob = Start-Job -Name 'preview-stripe-cli-listener' -ArgumentList @(
    $resolvedPackageRoot.Path,
    $phpCommand.Source,
    $testbenchCommand.Source,
    $stripePath,
    $LocalUrl,
    $HoldSeconds,
    $endpointSecret,
    $resolvedStoragePath
) -ScriptBlock {
    param(
        [string] $WorkingDirectory,
        [string] $Php,
        [string] $Testbench,
        [string] $StripePath,
        [string] $ForwardLocalUrl,
        [int] $TunnelHoldSeconds,
        [string] $EndpointSecret,
        [string] $CaptureStoragePath
    )

    Set-StrictMode -Version Latest
    $ErrorActionPreference = 'Stop'
    $env:PREVIEW_LIVE_ENABLED = 'true'
    $env:PREVIEW_STRIPE_ENDPOINT_SECRET = $EndpointSecret
    $env:PREVIEW_STRIPE_CLI_BINARY = $StripePath
    $env:PREVIEW_STORAGE_PATH = $CaptureStoragePath

    Set-Location -LiteralPath $WorkingDirectory
    & $Php $Testbench 'preview:capture' 'stripe' '--transport=stripe-cli' "--local-url=$ForwardLocalUrl" '--live' "--hold-seconds=$TunnelHoldSeconds" 2>&1

    if ($LASTEXITCODE -ne 0) {
        throw "testbench preview:capture exited with code $LASTEXITCODE"
    }
}

try {
    Start-Sleep -Seconds 2

    if ($listenerJob.State -ne 'Running') {
        Receive-RedactedJobOutput -Job $listenerJob
        Write-Error "Stripe CLI listener failed to stay open. Job state: $($listenerJob.State)."
        exit 1
    }

    Write-Info "Listener started. Capture/listen output will be printed with Stripe secrets redacted."

    if (-not [string]::IsNullOrWhiteSpace($TriggerEvent)) {
        Write-Info "Trigger attempted: stripe trigger $TriggerEvent"

        $triggerOutput = & $stripePath 'trigger' $TriggerEvent 2>&1
        $triggerExitCode = $LASTEXITCODE

        foreach ($line in $triggerOutput) {
            $text = Redact-StripeOutput ([string] $line)

            if ($text -ne '') {
                Write-Host $text
            }
        }

        if ($triggerExitCode -ne 0) {
            Write-Error "Stripe trigger failed with exit code $triggerExitCode. This is not proof of successful provider traffic."
            Stop-ListenerJob -Job $listenerJob
            Wait-Job -Job $listenerJob | Out-Null
            Receive-RedactedJobOutput -Job $listenerJob
            exit $triggerExitCode
        }

        Write-Info "Trigger succeeded: stripe trigger $TriggerEvent"
    } else {
        Write-Info "No TriggerEvent provided. Listener is open for manual Stripe CLI/dashboard trigger for up to $HoldSeconds second(s)."
    }

    Wait-Job -Job $listenerJob | Out-Null
    Receive-RedactedJobOutput -Job $listenerJob

    if ($listenerJob.State -ne 'Completed') {
        Write-Error "Stripe CLI listener ended with job state: $($listenerJob.State)."
        exit 1
    }

    if ($StartServer.IsPresent -and -not [string]::IsNullOrWhiteSpace($TriggerEvent)) {
        $captures = Invoke-TestbenchJson `
            -Php $phpCommand.Source `
            -Testbench $testbenchCommand.Source `
            -Arguments @('preview:capture:list', '--json') `
            -WorkingDirectory $resolvedPackageRoot.Path

        $verifiedCapture = @($captures | Where-Object {
            $_.provider -eq 'stripe' -and $_.verified -eq $true -and $_.event_type -eq $TriggerEvent
        }) | Select-Object -First 1

        if ($null -eq $verifiedCapture) {
            Write-Error "No verified Stripe capture for event [$TriggerEvent] was found in [$resolvedStoragePath]."
            exit 1
        }

        Write-Info "Verified Stripe capture stored: $($verifiedCapture.id)"
    }
} finally {
    Stop-ListenerJob -Job $listenerJob
    Remove-Job -Job $listenerJob -Force -ErrorAction SilentlyContinue
    Stop-SmokeJob -Job $serverJob

    [Environment]::SetEnvironmentVariable('PREVIEW_STORAGE_PATH', $previousStoragePath, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_STRIPE_ENDPOINT_SECRET', $previousEndpointSecret, 'Process')
    [Environment]::SetEnvironmentVariable('PREVIEW_STRIPE_CLI_BINARY', $previousStripeBinary, 'Process')

    if (-not $storagePathWasProvided -and (Test-Path -LiteralPath $resolvedStoragePath)) {
        Remove-Item -LiteralPath $resolvedStoragePath -Recurse -Force
    }
}
