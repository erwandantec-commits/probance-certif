param(
    [string]$VersionFile = (Join-Path (Split-Path -Parent $PSScriptRoot) "app\version.txt")
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $VersionFile)) {
    throw "Version file not found: $VersionFile"
}

$rawVersion = (Get-Content -LiteralPath $VersionFile -Raw).Trim()
if ([string]::IsNullOrWhiteSpace($rawVersion)) {
    throw "Version file is empty: $VersionFile"
}

if ($rawVersion -match '^\d+$') {
    $major = [int]$rawVersion
    $minor = 0
} elseif ($rawVersion -match '^(\d+)\.(\d+)$') {
    $major = [int]$Matches[1]
    $minor = [int]$Matches[2]
} else {
    throw "Unsupported version format '$rawVersion'. Expected 'N' or 'N.N'."
}

$nextVersion = "{0}.{1}" -f $major, ($minor + 1)
Set-Content -LiteralPath $VersionFile -Value ($nextVersion + "`n") -NoNewline

Write-Output $nextVersion
