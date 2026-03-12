param(
    [Parameter(Mandatory = $true)]
    [string]$Name
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$migrationDir = Join-Path $repoRoot "db_schema"

if (-not (Test-Path -LiteralPath $migrationDir)) {
    throw "Migration directory not found: $migrationDir"
}

$files = Get-ChildItem -LiteralPath $migrationDir -Filter "*.sql" | Sort-Object Name
$maxVersion = 2

foreach ($file in $files) {
    if ($file.BaseName -match '^(\d+)_') {
        $version = [int]$Matches[1]
        if ($version -gt $maxVersion) {
            $maxVersion = $version
        }
    }
}

$nextVersion = $maxVersion + 1
$safeName = $Name.ToLowerInvariant() -replace '[^a-z0-9]+', '_' -replace '^_+|_+$', ''

if ([string]::IsNullOrWhiteSpace($safeName)) {
    throw "Name must contain at least one alphanumeric character."
}

$filename = "{0:D2}_{1}.sql" -f $nextVersion, $safeName
$path = Join-Path $migrationDir $filename

if (Test-Path -LiteralPath $path) {
    throw "Migration already exists: $path"
}

$template = @"
-- Migration v$nextVersion
-- Release:
-- Purpose:

-- Write an idempotent migration here.
"@

Set-Content -LiteralPath $path -Value $template -Encoding ascii

Write-Host "Created migration: $path"
