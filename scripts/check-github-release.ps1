[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $Version,

    [ValidateNotNullOrEmpty()]
    [string] $Repo = 'oxhq/preview',

    [switch] $RequireAssets
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Ok {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Host "OK $Message"
}

function Write-Fail {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Host "FAIL $Message"
}

function Invoke-Gh {
    param(
        [Parameter(Mandatory = $true)]
        [string[]] $Arguments
    )

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $output = & gh @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    [pscustomobject] @{
        ExitCode = $exitCode
        Output = ($output -join [Environment]::NewLine)
    }
}

function Get-JsonOrNull {
    param([AllowNull()][string] $Json)

    if ([string]::IsNullOrWhiteSpace($Json)) {
        return $null
    }

    return $Json | ConvertFrom-Json
}

function New-TemporaryDirectory {
    $directory = Join-Path ([System.IO.Path]::GetTempPath()) ('preview-github-release-' + [System.Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $directory -Force | Out-Null

    return $directory
}

function Assert-ReleaseAsset {
    param(
        [Parameter(Mandatory = $true)]
        [object[]] $Assets,

        [Parameter(Mandatory = $true)]
        [string] $Name
    )

    $asset = $Assets | Where-Object { [string] $_.name -eq $Name } | Select-Object -First 1

    if ($null -eq $asset) {
        Write-Fail "GitHub Release asset [$Name] is missing."
        return $false
    }

    $size = [int64] $asset.size
    if ($size -le 0) {
        Write-Fail "GitHub Release asset [$Name] is empty."
        return $false
    }

    Write-Ok "GitHub Release asset [$Name] exists with size [$size] bytes."

    return $true
}

function Test-ChecksumFile {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Directory,

        [Parameter(Mandatory = $true)]
        [string] $AssetName
    )

    $assetPath = Join-Path $Directory $AssetName
    $checksumPath = Join-Path $Directory 'SHA256SUMS'

    if (-not (Test-Path -LiteralPath $assetPath -PathType Leaf)) {
        Write-Fail "Downloaded release asset [$AssetName] was not found."
        return $false
    }

    if (-not (Test-Path -LiteralPath $checksumPath -PathType Leaf)) {
        Write-Fail 'Downloaded release asset [SHA256SUMS] was not found.'
        return $false
    }

    $checksumLines = @(Get-Content -LiteralPath $checksumPath)
    $checksumLine = $checksumLines | Where-Object { $_ -match "(^|\s)$([regex]::Escape($AssetName))$" } | Select-Object -First 1

    if ($null -eq $checksumLine) {
        Write-Fail "SHA256SUMS does not contain an entry for [$AssetName]."
        return $false
    }

    if ($checksumLine -notmatch '^(?<hash>[a-fA-F0-9]{64})\s+\*?(?<file>.+)$') {
        Write-Fail "SHA256SUMS entry for [$AssetName] is not a valid sha256sum line."
        return $false
    }

    $expectedHash = $matches.hash.ToLowerInvariant()
    $actualHash = (Get-FileHash -LiteralPath $assetPath -Algorithm SHA256).Hash.ToLowerInvariant()

    if ($actualHash -ne $expectedHash) {
        Write-Fail "SHA256 mismatch for [$AssetName]: expected [$expectedHash], got [$actualHash]."
        return $false
    }

    Write-Ok "SHA256SUMS verifies [$AssetName]."

    return $true
}

$failed = $false
$ghCommand = Get-Command gh -ErrorAction SilentlyContinue

if ($null -eq $ghCommand) {
    Write-Fail 'GitHub CLI [gh] was not found on PATH.'
    exit 1
}

Write-Ok "GitHub CLI found at [$($ghCommand.Source)]."

$authResult = Invoke-Gh @('auth', 'status', '--hostname', 'github.com')
if ($authResult.ExitCode -ne 0) {
    Write-Fail 'GitHub CLI is not authenticated enough to read GitHub state. Run [gh auth status] for details.'
    exit 1
}

Write-Ok 'GitHub CLI auth status is usable for github.com.'

$tagResult = Invoke-Gh @('api', "repos/$Repo/git/ref/tags/$Version")
$tagSha = ''
$commitSha = ''

if ($tagResult.ExitCode -ne 0) {
    Write-Fail "Remote tag [$Version] does not exist in [$Repo]."
    $failed = $true
} else {
    $tag = Get-JsonOrNull $tagResult.Output
    $tagSha = [string] $tag.object.sha
    $tagType = [string] $tag.object.type

    if ($tagType -eq 'tag') {
        $annotatedTagResult = Invoke-Gh @('api', "repos/$Repo/git/tags/$tagSha")

        if ($annotatedTagResult.ExitCode -eq 0) {
            $annotatedTag = Get-JsonOrNull $annotatedTagResult.Output
            $commitSha = [string] $annotatedTag.object.sha
        }
    } else {
        $commitSha = $tagSha
    }

    if ($commitSha -eq '') {
        Write-Fail "Remote tag [$Version] exists, but its commit SHA could not be resolved."
        $failed = $true
    } else {
        Write-Ok "Remote tag [$Version] exists at commit [$commitSha]."
    }
}

$releaseResult = Invoke-Gh @('release', 'view', $Version, '--repo', $Repo, '--json', 'tagName,url,isDraft,isPrerelease,assets')

if ($releaseResult.ExitCode -ne 0) {
    Write-Fail "GitHub Release for [$Version] does not exist in [$Repo]."
    $failed = $true
} else {
    $release = Get-JsonOrNull $releaseResult.Output
    Write-Ok "GitHub Release exists for [$($release.tagName)] at [$($release.url)]."

    if ([bool] $release.isDraft) {
        Write-Fail "GitHub Release [$Version] is still a draft."
        $failed = $true
    }

    if ($RequireAssets.IsPresent) {
        $assets = @($release.assets)
        $archiveName = "preview-$Version.zip"
        $hasArchive = Assert-ReleaseAsset -Assets $assets -Name $archiveName
        $hasChecksums = Assert-ReleaseAsset -Assets $assets -Name 'SHA256SUMS'

        if (-not $hasArchive -or -not $hasChecksums) {
            $failed = $true
        } else {
            $temporaryDirectory = New-TemporaryDirectory

            try {
                $downloadResult = Invoke-Gh @(
                    'release',
                    'download',
                    $Version,
                    '--repo',
                    $Repo,
                    '--dir',
                    $temporaryDirectory,
                    '--pattern',
                    $archiveName,
                    '--pattern',
                    'SHA256SUMS',
                    '--clobber'
                )

                if ($downloadResult.ExitCode -ne 0) {
                    Write-Fail "Unable to download GitHub Release assets for checksum verification. $($downloadResult.Output)"
                    $failed = $true
                } elseif (-not (Test-ChecksumFile -Directory $temporaryDirectory -AssetName $archiveName)) {
                    $failed = $true
                }
            } finally {
                if (Test-Path -LiteralPath $temporaryDirectory) {
                    Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force
                }
            }
        }
    }
}

$run = $null
$runSource = ''

if ($commitSha -ne '') {
    $commitRunResult = Invoke-Gh @('run', 'list', '--repo', $Repo, '--commit', $commitSha, '--limit', '1', '--json', 'databaseId,name,status,conclusion,headBranch,headSha,url,createdAt')

    if ($commitRunResult.ExitCode -eq 0) {
        $commitRunsJson = Get-JsonOrNull $commitRunResult.Output
        $commitRuns = @()

        if ($null -ne $commitRunsJson) {
            $commitRuns = @($commitRunsJson)
        }

        if ($commitRuns.Count -gt 0) {
            $run = $commitRuns[0]
            $runSource = "latest run for commit [$commitSha]"
        }
    }
}

if ($null -eq $run) {
    $tagRunResult = Invoke-Gh @('run', 'list', '--repo', $Repo, '--branch', $Version, '--limit', '1', '--json', 'databaseId,name,status,conclusion,headBranch,headSha,url,createdAt')

    if ($tagRunResult.ExitCode -eq 0) {
        $tagRunsJson = Get-JsonOrNull $tagRunResult.Output
        $tagRuns = @()

        if ($null -ne $tagRunsJson) {
            $tagRuns = @($tagRunsJson)
        }

        if ($tagRuns.Count -gt 0) {
            $run = $tagRuns[0]
            $runSource = "latest run for tag/ref [$Version]"
        }
    }
}

if ($null -eq $run) {
    $latestRunResult = Invoke-Gh @('run', 'list', '--repo', $Repo, '--limit', '1', '--json', 'databaseId,name,status,conclusion,headBranch,headSha,url,createdAt')

    if ($latestRunResult.ExitCode -ne 0) {
        Write-Fail "Unable to read GitHub Actions runs for [$Repo]."
        $failed = $true
    } else {
        $latestRunsJson = Get-JsonOrNull $latestRunResult.Output
        $latestRuns = @()

        if ($null -ne $latestRunsJson) {
            $latestRuns = @($latestRunsJson)
        }

        if ($latestRuns.Count -eq 0) {
            Write-Fail "No GitHub Actions runs found for [$Repo]."
            $failed = $true
        } else {
            $run = $latestRuns[0]
            $runSource = 'latest repo run; no exact tag or commit association was found'
        }
    }
}

if ($null -ne $run) {
    $runStatus = [string] $run.status
    $runConclusion = [string] $run.conclusion

    if ($runStatus -eq 'completed' -and $runConclusion -eq 'success') {
        Write-Ok "CI $runSource succeeded: [$($run.name)] [$($run.url)]."
    } else {
        Write-Fail "CI $runSource is not successful: status=[$runStatus] conclusion=[$runConclusion] run=[$($run.url)]."
        $failed = $true
    }
}

if ($failed) {
    exit 1
}

exit 0
