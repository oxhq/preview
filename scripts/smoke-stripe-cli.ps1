param(
    [string] $LocalUrl = 'http://127.0.0.1:8000',
    [int] $HoldSeconds = 60,
    [string] $TriggerEvent = '',
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

$stripeCommand = Get-Command stripe -ErrorAction SilentlyContinue

if ($null -eq $stripeCommand) {
    Write-Error 'Stripe CLI was not found. Install it and make sure `stripe` is available on PATH before running this smoke script.'
    exit 1
}

$resolvedPackageRoot = Resolve-Path $PackageRoot
$testbench = Join-Path $resolvedPackageRoot.Path 'vendor/bin/testbench'

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

if ([string]::IsNullOrWhiteSpace($env:PREVIEW_STRIPE_ENDPOINT_SECRET)) {
    Write-Error 'PREVIEW_STRIPE_ENDPOINT_SECRET is required. Set it to the Stripe CLI webhook signing secret for this endpoint before running this smoke script. The value will not be printed.'
    exit 1
}

if ($HoldSeconds -lt 0) {
    Write-Error 'HoldSeconds must be zero or greater.'
    exit 1
}

Write-Info "Using Stripe CLI at [$($stripeCommand.Source)]."
Write-Info "Using package root [$($resolvedPackageRoot.Path)]."
Write-Info "Starting Stripe CLI listener through testbench."

$listenerJob = Start-Job -Name 'preview-stripe-cli-listener' -ArgumentList @(
    $resolvedPackageRoot.Path,
    $phpCommand.Source,
    $testbenchCommand.Source,
    $LocalUrl,
    $HoldSeconds,
    $env:PREVIEW_STRIPE_ENDPOINT_SECRET
) -ScriptBlock {
    param(
        [string] $WorkingDirectory,
        [string] $Php,
        [string] $Testbench,
        [string] $ForwardLocalUrl,
        [int] $TunnelHoldSeconds,
        [string] $EndpointSecret
    )

    Set-StrictMode -Version Latest
    $ErrorActionPreference = 'Stop'
    $env:PREVIEW_LIVE_ENABLED = 'true'
    $env:PREVIEW_STRIPE_ENDPOINT_SECRET = $EndpointSecret

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

        $triggerOutput = & stripe trigger $TriggerEvent 2>&1
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
} finally {
    Stop-ListenerJob -Job $listenerJob
    Remove-Job -Job $listenerJob -Force -ErrorAction SilentlyContinue
}
