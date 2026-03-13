param(
    [int]$TargetVersion = 0,
    [switch]$Force,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$migrationDir = Join-Path $repoRoot "db_schema"

function Invoke-DockerCompose {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Args
    )

    Write-Host ("docker compose " + ($Args -join " "))
    if (-not $DryRun) {
        & docker compose @Args
        if ($LASTEXITCODE -ne 0) {
            throw "docker compose command failed."
        }
    }
}

function Invoke-DbQuery {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Query
    )

    $args = @(
        "exec", "-T", "db",
        "mariadb",
        "-uroot",
        "-proot",
        "certif",
        "-Nse",
        $Query
    )

    Write-Host ("docker compose " + ($args -join " "))
    if ($DryRun) {
        return ""
    }

    $output = & docker compose @args
    if ($LASTEXITCODE -ne 0) {
        throw "Database query failed."
    }

    return ($output | Out-String).Trim()
}

function Get-MigrationFiles {
    if (-not (Test-Path -LiteralPath $migrationDir)) {
        throw "Migration directory not found: $migrationDir"
    }

    $items = foreach ($file in (Get-ChildItem -LiteralPath $migrationDir -Filter "*.sql" | Sort-Object Name)) {
        if ($file.BaseName -match '^(\d+)_') {
            [pscustomobject]@{
                Version = [int]$Matches[1]
                Name    = $file.Name
            }
        }
    }

    return @($items)
}

$migrationFiles = Get-MigrationFiles
$latestVersion = if ($migrationFiles.Count -gt 0) { ($migrationFiles | Select-Object -Last 1).Version } else { 2 }

if ($TargetVersion -le 0) {
    $TargetVersion = $latestVersion
}

if ($TargetVersion -lt 2) {
    throw "TargetVersion must be >= 2."
}

if ($TargetVersion -gt $latestVersion) {
    throw "TargetVersion v$TargetVersion is greater than latest migration v$latestVersion."
}

$expectedVersion = 2
foreach ($migration in $migrationFiles | Where-Object { $_.Version -le $TargetVersion }) {
    if ($migration.Version -ne ($expectedVersion + 1)) {
        throw "Missing migration between v$expectedVersion and v$($migration.Version)."
    }
    $expectedVersion = $migration.Version
}

Write-Host "Imported DB baseline target version: v$TargetVersion"

Invoke-DockerCompose -Args @("up", "-d", "db")

$schemaVersionRow = Invoke-DbQuery -Query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_version';"
$schemaMigrationsRow = Invoke-DbQuery -Query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations';"

if ([string]::IsNullOrWhiteSpace($schemaVersionRow)) {
    $schemaVersionRow = "0"
}
if ([string]::IsNullOrWhiteSpace($schemaMigrationsRow)) {
    $schemaMigrationsRow = "0"
}

$hasSchemaVersionTable = ([int]$schemaVersionRow) -gt 0
$hasSchemaMigrationsTable = ([int]$schemaMigrationsRow) -gt 0

$existingSchemaVersion = if ($hasSchemaVersionTable) {
    Invoke-DbQuery -Query "SELECT COALESCE(MAX(version), 0) FROM schema_version;"
} else {
    "0"
}

$existingMigrationRows = if ($hasSchemaMigrationsTable) {
    Invoke-DbQuery -Query "SELECT COUNT(*) FROM schema_migrations;"
} else {
    "0"
}

if (-not $Force -and (([int]$existingSchemaVersion -gt 0) -or ([int]$existingMigrationRows -gt 0))) {
    throw "Schema metadata already exists (schema_version=$existingSchemaVersion, schema_migrations rows=$existingMigrationRows). Use -Force only if you intentionally want to restamp migration metadata."
}

$migrationRows = @()
foreach ($migration in $migrationFiles | Where-Object { $_.Version -le $TargetVersion }) {
    $safeName = $migration.Name.Replace("'", "''")
    $migrationRows += "($($migration.Version), '$safeName')"
}

$migrationInsert = if ($migrationRows.Count -gt 0) {
    "INSERT INTO schema_migrations (version, script_name) VALUES " + ($migrationRows -join ", ") + ";"
} else {
    ""
}

$sql = @"
CREATE TABLE IF NOT EXISTS schema_version (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  version INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_schema_version_id CHECK (id = 1)
);

CREATE TABLE IF NOT EXISTS schema_migrations (
  version INT NOT NULL PRIMARY KEY,
  script_name VARCHAR(255) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DELETE FROM schema_migrations;
DELETE FROM schema_version WHERE id = 1;
$migrationInsert
INSERT INTO schema_version (id, version) VALUES (1, $TargetVersion);
"@

Invoke-DbQuery -Query $sql | Out-Null

Write-Host "Baseline applied. Imported DB is now stamped at schema version v$TargetVersion."
Write-Host "Future releases can now use scripts/release.ps1 normally."
