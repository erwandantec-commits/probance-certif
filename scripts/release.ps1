param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseVersion,

    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$migrationDir = Join-Path $repoRoot "db_schema"
$appVersionFile = Join-Path $repoRoot "app\version.txt"

function Invoke-Step {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Label,
        [Parameter(Mandatory = $true)]
        [scriptblock]$Action
    )

    Write-Host "==> $Label"
    & $Action
}

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

function Get-LatestMigrationVersion {
    if (-not (Test-Path -LiteralPath $migrationDir)) {
        throw "Migration directory not found: $migrationDir"
    }

    $versions = Get-ChildItem -LiteralPath $migrationDir -Filter "*.sql" |
        ForEach-Object {
            if ($_.BaseName -match '^(\d+)_') {
                [int]$Matches[1]
            }
        } |
        Sort-Object

    if (-not $versions) {
        return 2
    }

    return $versions[-1]
}

function Get-AppVersion {
    if (-not (Test-Path -LiteralPath $appVersionFile)) {
        throw "Application version file not found: $appVersionFile"
    }

    $version = (Get-Content -LiteralPath $appVersionFile -Raw).Trim()
    if ([string]::IsNullOrWhiteSpace($version)) {
        throw "Application version file is empty: $appVersionFile"
    }

    return $version
}

function Get-DbSchemaVersion {
    $query = "SELECT version FROM schema_version WHERE id = 1;"
    $args = @(
        "exec", "-T", "db",
        "mariadb",
        "-uroot",
        "-proot",
        "certif",
        "-Nse",
        $query
    )

    Write-Host ("docker compose " + ($args -join " "))
    if ($DryRun) {
        return $null
    }

    $output = & docker compose @args
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to read schema_version from database."
    }

    return ($output | Out-String).Trim()
}

Push-Location $repoRoot
try {
    $expectedVersion = Get-LatestMigrationVersion
    $appVersion = Get-AppVersion

    Invoke-Step -Label "Release $ReleaseVersion" -Action {
        Write-Host "Expected schema version: v$expectedVersion"
        Write-Host "Application version: $appVersion"
        if ($appVersion -ne $ReleaseVersion) {
            throw "ReleaseVersion ($ReleaseVersion) does not match app/version.txt ($appVersion)."
        }
    }

    Invoke-Step -Label "Start database and apply migrations" -Action {
        Invoke-DockerCompose -Args @("up", "-d", "db")
    }

    Invoke-Step -Label "Deploy web container" -Action {
        Invoke-DockerCompose -Args @("up", "-d", "--build", "web")
    }

    Invoke-Step -Label "Verify schema version" -Action {
        $actualVersion = Get-DbSchemaVersion
        if (-not $DryRun -and [int]$actualVersion -ne [int]$expectedVersion) {
            throw "Schema version mismatch. Expected v$expectedVersion, got v$actualVersion."
        }

        if (-not $DryRun) {
            Write-Host "Schema version OK: v$actualVersion"
        }
    }

    Invoke-Step -Label "Release completed" -Action {
        Write-Host "Release $ReleaseVersion is ready."
    }
}
finally {
    Pop-Location
}
