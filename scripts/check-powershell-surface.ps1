[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Ok {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Host "OK   $Message"
}

function Write-Fail {
    param([Parameter(Mandatory = $true)][string] $Message)

    Write-Host "FAIL $Message"
}

$scriptRoot = if ($PSScriptRoot -ne '') {
    $PSScriptRoot
} else {
    Split-Path -Parent $MyInvocation.MyCommand.Path
}

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $scriptRoot '..'))
$failed = $false

$scriptFiles = @(Get-ChildItem -LiteralPath (Join-Path $repoRoot 'scripts') -Filter '*.ps1' -File | Sort-Object FullName)

if ($scriptFiles.Count -eq 0) {
    Write-Fail 'No scripts/*.ps1 files were found.'
    exit 1
}

foreach ($scriptFile in $scriptFiles) {
    $fullPath = $scriptFile.FullName
    $repositoryPath = $scriptFile.FullName.Substring($repoRoot.Length).TrimStart('\', '/') -replace '\\', '/'

    $tokens = $null
    $parseErrors = $null
    [System.Management.Automation.Language.Parser]::ParseFile($fullPath, [ref] $tokens, [ref] $parseErrors) | Out-Null

    if ($parseErrors.Count -eq 0) {
        Write-Ok "$repositoryPath parses as PowerShell."
        continue
    }

    Write-Fail "$repositoryPath has $($parseErrors.Count) PowerShell syntax error(s)."
    foreach ($parseError in $parseErrors) {
        $extent = $parseError.Extent
        Write-Host "FAIL ${repositoryPath}:$($extent.StartLineNumber):$($extent.StartColumnNumber) $($parseError.Message)"
    }

    $failed = $true
}

if ($failed) {
    exit 1
}

Write-Ok "Parsed $($scriptFiles.Count) scripts/*.ps1 file(s)."
exit 0
