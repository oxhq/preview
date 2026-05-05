[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $Version,

    [ValidateNotNullOrEmpty()]
    [string] $Repo = 'oxhq/preview'
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

$releaseResult = Invoke-Gh @('release', 'view', $Version, '--repo', $Repo, '--json', 'tagName,url,isDraft,isPrerelease')

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
