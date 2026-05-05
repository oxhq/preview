[CmdletBinding()]
param(
    [ValidateSet('dry-run', 'trigger', 'subscription')]
    [string] $Mode = 'dry-run',

    [string] $Topic = 'orders/create',

    [string] $ApiVersion = '2026-04',

    [string] $CaptureUrl = $env:PREVIEW_SHOPIFY_CAPTURE_URL,

    [string] $ClientSecret = $env:PREVIEW_SHOPIFY_CLIENT_SECRET,

    [string] $ClientId = $env:PREVIEW_SHOPIFY_CLIENT_ID,

    [string] $Shop = $env:PREVIEW_SHOPIFY_SHOP,

    [string] $AdminAccessToken = $env:PREVIEW_SHOPIFY_ADMIN_ACCESS_TOKEN,

    [switch] $KeepSubscription
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Smoke {
    param([string] $Message)

    Write-Host "[shopify-webhook-smoke] $Message"
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

function Redact-SmokeOutput {
    param(
        [AllowNull()]
        [string] $Text,

        [AllowNull()]
        [string[]] $Secrets
    )

    if ([string]::IsNullOrEmpty($Text)) {
        return ''
    }

    $redacted = $Text

    foreach ($secret in @($Secrets)) {
        if (-not [string]::IsNullOrWhiteSpace($secret)) {
            $redacted = $redacted.Replace($secret, '[redacted-shopify-secret]')
        }
    }

    $redacted = $redacted -replace '(?i)(X-Shopify-Hmac-Sha256:\s*)[A-Za-z0-9+/=]+', '$1[redacted]'
    $redacted = $redacted -replace '(?i)("X-Shopify-Hmac-Sha256"\s*:\s*")[^"]+(")', '$1[redacted]$2'
    $redacted = $redacted -replace '(?i)(X-Shopify-Access-Token:\s*)[^\s]+', '$1[redacted]'
    $redacted = $redacted -replace '(?i)(--client-secret\s+)[^\s]+', '$1[redacted]'
    $redacted = $redacted -replace '(?i)("clientSecret"\s*:\s*")[^"]+(")', '$1[redacted]$2'

    return $redacted
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

function Assert-RequiredValue {
    param(
        [AllowNull()]
        [string] $Value,

        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    if ([string]::IsNullOrWhiteSpace($Value)) {
        Write-Failure "Missing required [$Name]. Provide it as a parameter or environment variable."
    }
}

function Assert-CaptureUrl {
    param([Parameter(Mandatory = $true)][string] $Url)

    try {
        $uri = [Uri] $Url
    } catch {
        Write-Failure "CaptureUrl [$Url] is not a valid URL."
    }

    if ($uri.Scheme -notin @('http', 'https')) {
        Write-Failure "CaptureUrl [$Url] must use http or https."
    }

    if ($Mode -eq 'subscription' -and $uri.Scheme -ne 'https') {
        Write-Failure 'Shopify HTTPS webhook subscriptions require a public https CaptureUrl.'
    }
}

function Normalize-ShopDomain {
    param([Parameter(Mandatory = $true)][string] $Value)

    $normalized = $Value.Trim()
    $normalized = $normalized -replace '^https?://', ''
    $normalized = $normalized.TrimEnd('/')

    if ($normalized -notmatch '^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$') {
        Write-Failure "Shop [$Value] must look like your-dev-store.myshopify.com."
    }

    return $normalized.ToLowerInvariant()
}

function ConvertTo-ShopifyGraphqlTopic {
    param([Parameter(Mandatory = $true)][string] $Value)

    $topicValue = $Value.Trim()

    if ($topicValue -notmatch '^[A-Za-z0-9_/-]+$') {
        Write-Failure "Topic [$Value] contains unsupported characters."
    }

    return ($topicValue -replace '/', '_' -replace '-', '_').ToUpperInvariant()
}

function Invoke-ShopifyCliTrigger {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Shopify,

        [Parameter(Mandatory = $true)]
        [string[]] $Arguments,

        [Parameter(Mandatory = $true)]
        [string[]] $Secrets
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $outputObjects = & $Shopify @Arguments 2>&1
        $exitCode = if ($null -eq (Get-Variable -Name LASTEXITCODE -ErrorAction SilentlyContinue)) { 0 } else { [int] $LASTEXITCODE }
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $output = ($outputObjects | ForEach-Object { $_.ToString() }) -join [Environment]::NewLine
    $safeOutput = Redact-SmokeOutput -Text $output -Secrets $Secrets

    if ($exitCode -ne 0) {
        Write-Failure "shopify app webhook trigger failed with exit code $exitCode.$([Environment]::NewLine)$safeOutput"
    }

    if (-not [string]::IsNullOrWhiteSpace($safeOutput)) {
        Write-Smoke $safeOutput
    }
}

function Invoke-ShopifyGraphQL {
    param(
        [Parameter(Mandatory = $true)]
        [string] $ShopDomain,

        [Parameter(Mandatory = $true)]
        [string] $Version,

        [Parameter(Mandatory = $true)]
        [string] $Token,

        [Parameter(Mandatory = $true)]
        [string] $Query,

        [Parameter(Mandatory = $true)]
        [hashtable] $Variables,

        [Parameter(Mandatory = $true)]
        [string[]] $Secrets
    )

    $uri = "https://$ShopDomain/admin/api/$Version/graphql.json"
    $body = @{
        query = $Query
        variables = $Variables
    } | ConvertTo-Json -Depth 12

    try {
        return Invoke-RestMethod `
            -Method Post `
            -Uri $uri `
            -Headers @{
                'X-Shopify-Access-Token' = $Token
                'Content-Type' = 'application/json'
            } `
            -Body $body
    } catch {
        $message = $_.Exception.Message

        if ($_.ErrorDetails -and $_.ErrorDetails.Message) {
            $message = "$message$([Environment]::NewLine)$($_.ErrorDetails.Message)"
        }

        Write-Failure (Redact-SmokeOutput -Text "Shopify GraphQL request failed. $message" -Secrets $Secrets)
    }
}

function Assert-NoUserErrors {
    param(
        [AllowNull()]
        [object[]] $UserErrors,

        [Parameter(Mandatory = $true)]
        [string] $Operation
    )

    $errors = @($UserErrors | Where-Object { $null -ne $_ })

    if ($errors.Count -eq 0) {
        return
    }

    $messages = @($errors | ForEach-Object {
        $field = if ($_.field) { ($_.field -join '.') } else { 'unknown' }
        "${field}: $($_.message)"
    })

    Write-Failure "$Operation returned Shopify user error(s): $($messages -join '; ')"
}

$secrets = @($ClientSecret, $AdminAccessToken)
$graphqlTopic = ConvertTo-ShopifyGraphqlTopic -Value $Topic

if (-not [string]::IsNullOrWhiteSpace($CaptureUrl)) {
    Assert-CaptureUrl -Url $CaptureUrl
}

Write-Smoke "Mode: $Mode"
Write-Smoke "Topic: $Topic ($graphqlTopic)"
Write-Smoke "API version: $ApiVersion"

if ($Mode -eq 'dry-run') {
    Write-Smoke 'Dry run only; no Shopify CLI, Admin API, or webhook delivery request will be attempted.'
    Write-Smoke 'trigger mode requires PREVIEW_SHOPIFY_CAPTURE_URL and PREVIEW_SHOPIFY_CLIENT_SECRET, and proves a Shopify CLI sample payload reaches the endpoint with a valid HMAC header.'
    Write-Smoke 'subscription mode requires PREVIEW_SHOPIFY_SHOP, PREVIEW_SHOPIFY_ADMIN_ACCESS_TOKEN, and PREVIEW_SHOPIFY_CAPTURE_URL, and validates a dev-store GraphQL Admin webhook subscription can be created.'
    Write-Smoke 'A real end-to-end Shopify event still requires triggering the matching action in the dev store; Shopify CLI sample delivery does not validate API webhook subscriptions.'
    exit 0
}

Assert-RequiredValue -Value $CaptureUrl -Name 'PREVIEW_SHOPIFY_CAPTURE_URL'
Assert-CaptureUrl -Url $CaptureUrl

if ($Mode -eq 'trigger') {
    Assert-RequiredValue -Value $ClientSecret -Name 'PREVIEW_SHOPIFY_CLIENT_SECRET'

    $shopify = Get-CommandPath -Name 'shopify'

    if ($null -eq $shopify) {
        Write-Failure 'Missing [shopify] on PATH. Install Shopify CLI before running trigger mode.'
    }

    $arguments = @(
        'app',
        'webhook',
        'trigger',
        "--topic=$Topic",
        "--api-version=$ApiVersion",
        "--address=$CaptureUrl",
        "--client-secret=$ClientSecret"
    )

    if (-not [string]::IsNullOrWhiteSpace($ClientId)) {
        $arguments += "--client-id=$ClientId"
    }

    Write-Smoke "Triggering Shopify CLI sample webhook delivery to [$CaptureUrl]."
    Write-Smoke 'Client secret is process-local and will not be printed.'

    Invoke-ShopifyCliTrigger -Shopify $shopify -Arguments $arguments -Secrets $secrets

    Write-Smoke 'Shopify CLI sample webhook delivery completed.'
    Write-Smoke 'Proof boundary: signed sample payload delivery only; Shopify documents that this does not validate API webhook subscriptions.'
    exit 0
}

Assert-RequiredValue -Value $Shop -Name 'PREVIEW_SHOPIFY_SHOP'
Assert-RequiredValue -Value $AdminAccessToken -Name 'PREVIEW_SHOPIFY_ADMIN_ACCESS_TOKEN'

$shopDomain = Normalize-ShopDomain -Value $Shop
$createdSubscriptionId = $null

try {
    Write-Smoke "Creating Shopify GraphQL Admin webhook subscription on [$shopDomain]."
    Write-Smoke 'Admin access token is process-local and will not be printed.'

    $createQuery = @'
mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
    userErrors {
      field
      message
    }
    webhookSubscription {
      id
      topic
      uri
      format
    }
  }
}
'@

    $createResponse = Invoke-ShopifyGraphQL `
        -ShopDomain $shopDomain `
        -Version $ApiVersion `
        -Token $AdminAccessToken `
        -Query $createQuery `
        -Variables @{
            topic = $graphqlTopic
            webhookSubscription = @{
                uri = $CaptureUrl
                format = 'JSON'
            }
        } `
        -Secrets $secrets

    if ($createResponse.errors) {
        Write-Failure (Redact-SmokeOutput -Text "Shopify GraphQL returned error(s): $($createResponse.errors | ConvertTo-Json -Depth 8)" -Secrets $secrets)
    }

    $result = $createResponse.data.webhookSubscriptionCreate
    Assert-NoUserErrors -UserErrors @($result.userErrors) -Operation 'webhookSubscriptionCreate'

    $createdSubscriptionId = [string] $result.webhookSubscription.id

    if ([string]::IsNullOrWhiteSpace($createdSubscriptionId)) {
        Write-Failure 'Shopify subscription creation returned no subscription id.'
    }

    Write-Smoke "Created Shopify webhook subscription: $createdSubscriptionId"
    Write-Smoke "Subscription topic: $($result.webhookSubscription.topic)"
    Write-Smoke "Subscription uri: $($result.webhookSubscription.uri)"

    if ($KeepSubscription.IsPresent) {
        Write-Smoke 'Keeping subscription for a manual dev-store event proof. Trigger the matching Shopify action and inspect Preview captures.'
    } else {
        Write-Smoke 'Deleting subscription after creation check. Use -KeepSubscription when you want to trigger a real dev-store event while the endpoint is live.'
    }
} finally {
    if (-not $KeepSubscription.IsPresent -and -not [string]::IsNullOrWhiteSpace($createdSubscriptionId)) {
        try {
            $deleteQuery = @'
mutation webhookSubscriptionDelete($id: ID!) {
  webhookSubscriptionDelete(id: $id) {
    deletedWebhookSubscriptionId
    userErrors {
      field
      message
    }
  }
}
'@

            $deleteResponse = Invoke-ShopifyGraphQL `
                -ShopDomain $shopDomain `
                -Version $ApiVersion `
                -Token $AdminAccessToken `
                -Query $deleteQuery `
                -Variables @{ id = $createdSubscriptionId } `
                -Secrets $secrets

            if ($deleteResponse.errors) {
                Write-Smoke (Redact-SmokeOutput -Text "Subscription cleanup returned GraphQL error(s): $($deleteResponse.errors | ConvertTo-Json -Depth 8)" -Secrets $secrets)
            } else {
                Assert-NoUserErrors -UserErrors @($deleteResponse.data.webhookSubscriptionDelete.userErrors) -Operation 'webhookSubscriptionDelete'
                Write-Smoke "Deleted Shopify webhook subscription: $createdSubscriptionId"
            }
        } catch {
            Write-Smoke "Failed to delete Shopify subscription [$createdSubscriptionId]. Delete it manually in [$shopDomain]."
        }
    }
}

Write-Smoke 'Shopify webhook subscription check completed.'
Write-Smoke 'Proof boundary: subscription create/delete proves credentials, topic, scopes, and endpoint acceptance; delivery proof requires Shopify CLI trigger mode or a real store action while the subscription is kept.'
exit 0
