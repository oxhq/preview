param(
    [Parameter(Mandatory = $true)]
    [string] $Version,

    [switch] $CreateTag,

    [switch] $PushTag,

    [string] $Repo = 'oxhq/preview'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Ok {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Host "OK: $Message"
}

function Write-Fail {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Host "FAIL: $Message" -ForegroundColor Red
}

function Fail {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Fail $Message
    exit 1
}

function Invoke-Checked {
    param(
        [Parameter(Mandatory = $true)][string] $Label,
        [Parameter(Mandatory = $true)][scriptblock] $Command
    )

    try {
        & $Command
        if ($LASTEXITCODE -ne 0) {
            Fail "$Label failed with exit code $LASTEXITCODE."
        }
    } catch {
        Fail "$Label failed: $($_.Exception.Message)"
    }

    Write-Ok $Label
}

function Invoke-Captured {
    param(
        [Parameter(Mandatory = $true)][string] $Label,
        [Parameter(Mandatory = $true)][scriptblock] $Command
    )

    try {
        $output = & $Command 2>&1
        if ($LASTEXITCODE -ne 0) {
            $detail = ($output | Out-String).Trim()
            if ($detail.Length -gt 0) {
                Fail "$Label failed with exit code $LASTEXITCODE. $detail"
            }

            Fail "$Label failed with exit code $LASTEXITCODE."
        }

        return $output
    } catch {
        Fail "$Label failed: $($_.Exception.Message)"
    }
}

if ($Version -notmatch '^v\d+\.\d+\.\d+$') {
    Fail "Version must look like vMAJOR.MINOR.PATCH, for example v1.2.3."
}
Write-Ok "Version format validated ($Version)."

if ($PushTag.IsPresent) {
    $CreateTag = $true
}

$insideWorkTree = (Invoke-Captured 'git worktree check' { git rev-parse --is-inside-work-tree }) -join ''
if ($insideWorkTree.Trim() -ne 'true') {
    Fail 'Current directory is not inside a git worktree.'
}
Write-Ok 'Git worktree detected.'

$branch = ((Invoke-Captured 'git branch check' { git branch --show-current }) -join '').Trim()
if ($branch.Length -eq 0) {
    Fail 'Current HEAD is detached; release prep requires a named branch.'
}

$head = ((Invoke-Captured 'git HEAD check' { git rev-parse HEAD }) -join '').Trim()
if ($head -notmatch '^[0-9a-f]{40}$') {
    Fail "Could not resolve a valid HEAD SHA. Got: $head"
}
Write-Ok "Current branch is $branch at $head."

$status = Invoke-Captured 'git status check' { git status --porcelain }
if (($status | Measure-Object).Count -gt 0) {
    Fail 'Git worktree is not clean. Commit, stash, or remove local changes before release prep.'
}
Write-Ok 'Git worktree is clean.'

Invoke-Checked 'composer ci' { composer ci }

$ghCommand = Get-Command gh -ErrorAction SilentlyContinue
if ($null -eq $ghCommand) {
    Fail 'GitHub CLI (gh) is not available on PATH; cannot verify GitHub CI for HEAD.'
}
Write-Ok "GitHub CLI found at $($ghCommand.Source)."

Invoke-Checked 'gh authentication check' { gh auth status --hostname github.com }

$runsJson = (Invoke-Captured 'gh CI run lookup' {
    gh run list --repo $Repo --commit $head --json databaseId,status,conclusion,headSha,createdAt,url --limit 20
}) -join "`n"

if ($runsJson.Trim().Length -eq 0) {
    Fail "GitHub CLI returned no workflow run data for $Repo at $head."
}

try {
    $runs = $runsJson | ConvertFrom-Json
} catch {
    Fail "Could not parse GitHub CI JSON: $($_.Exception.Message)"
}

if ($null -eq $runs -or ($runs | Measure-Object).Count -eq 0) {
    Fail "No GitHub workflow runs found for $Repo at $head."
}

$latestRun = $runs | Sort-Object { [datetime] $_.createdAt } -Descending | Select-Object -First 1
if ($latestRun.status -ne 'completed') {
    Fail "Latest GitHub CI run for $head is not completed. Status: $($latestRun.status). URL: $($latestRun.url)"
}

if ($latestRun.conclusion -ne 'success') {
    Fail "Latest GitHub CI run for $head did not succeed. Conclusion: $($latestRun.conclusion). URL: $($latestRun.url)"
}
Write-Ok "Latest GitHub CI run succeeded for $head. URL: $($latestRun.url)"

$tagRef = "refs/tags/$Version"
$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    $null = & git rev-parse --verify --quiet $tagRef 2>$null
    $tagLookupExitCode = $LASTEXITCODE
} finally {
    $ErrorActionPreference = $previousErrorActionPreference
}

if ($tagLookupExitCode -ne 0 -and $tagLookupExitCode -ne 1) {
    Fail "Could not check tag $Version. git rev-parse exited with code $tagLookupExitCode."
}

$tagExists = $tagLookupExitCode -eq 0

if ($tagExists) {
    $existingTagTarget = ((Invoke-Captured "resolve tag $Version" { git rev-parse "$Version^{commit}" }) -join '').Trim()
    if ($existingTagTarget -ne $head) {
        Fail "Tag $Version already exists but points to $existingTagTarget, not current HEAD $head."
    }

    Write-Ok "Tag $Version already exists and points to current HEAD."
} elseif ($CreateTag.IsPresent) {
    Invoke-Checked "create annotated tag $Version" { git tag -a $Version -m "Release $Version" $head }
} else {
    Write-Ok "Tag $Version does not exist locally; CreateTag not passed, so no tag was created."
    Write-Host "NEXT: composer release:prepare -- -Version $Version -CreateTag"
}

if ($PushTag.IsPresent) {
    Invoke-Checked "push tag $Version" { git push origin $Version }
} else {
    Write-Ok 'PushTag not passed; tag was not pushed.'
    if ($CreateTag.IsPresent -or $tagExists) {
        Write-Host "NEXT: composer release:prepare -- -Version $Version -PushTag"
    }
}

Write-Ok "Release prep completed for $Repo $Version at $head."
