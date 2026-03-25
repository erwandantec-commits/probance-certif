param(
    [switch]$IncludeDataSnapshot,
    [switch]$StopDbForDataSnapshot,
    [int]$KeepCount = 3,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$backupDir = Join-Path $repoRoot "backups"
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$archiveFile = Join-Path $backupDir ("certif-app-{0}.zip" -f $timestamp)
$dbDumpFile = Join-Path $backupDir ("certif-db-{0}.sql" -f $timestamp)
$dataArchiveFile = Join-Path $backupDir ("certif-data-{0}.zip" -f $timestamp)
$projectEntries = @(
    "app",
    "docs",
    "db",
    "db_schema",
    "initdb",
    "scripts",
    "docker-compose.yml",
    "Dockerfile",
    "README.md",
    ".gitignore"
)

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

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Label,
        [Parameter(Mandatory = $true)]
        [scriptblock]$Action
    )

    Write-Host "==> $Label"
    & $Action
}

function Remove-OldBackups {
    if ($KeepCount -lt 0) {
        throw "KeepCount must be >= 0."
    }

    $patterns = @(
        "certif-app-*.zip",
        "certif-db-*.sql",
        "certif-data-*.zip"
    )

    foreach ($pattern in $patterns) {
        $oldFiles = Get-ChildItem -LiteralPath $backupDir -Filter $pattern -File -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -Skip $KeepCount

        foreach ($file in $oldFiles) {
            Write-Host ("Remove old backup: " + $file.FullName)
            if (-not $DryRun) {
                Remove-Item -LiteralPath $file.FullName -Force
            }
        }
    }
}

function New-DatabaseDump {
    $command = "docker compose exec -T db mariadb-dump -uroot -proot certif > `"$dbDumpFile`""
    Write-Host $command

    if (-not $DryRun) {
        & powershell -NoProfile -Command $command
        if ($LASTEXITCODE -ne 0) {
            throw "Database backup failed."
        }
    }
}

function New-ProjectArchive {
    $paths = foreach ($entry in $projectEntries) {
        $fullPath = Join-Path $repoRoot $entry
        if (Test-Path -LiteralPath $fullPath) {
            $fullPath
        }
    }

    if ($paths.Count -eq 0) {
        throw "No project files found to archive."
    }

    Write-Host ("Archive project to " + $archiveFile)
    if (-not $DryRun) {
        Compress-Archive -Path $paths -DestinationPath $archiveFile -CompressionLevel Optimal -Force
    }
}

function New-DataArchive {
    $dataDir = Join-Path $repoRoot "data"
    if (-not (Test-Path -LiteralPath $dataDir)) {
        throw "Data directory not found: $dataDir"
    }

    if ($StopDbForDataSnapshot) {
        Invoke-DockerCompose -Args @("stop", "db")
    }

    try {
        Write-Host ("Archive data directory to " + $dataArchiveFile)
        if (-not $DryRun) {
            Compress-Archive -Path $dataDir -DestinationPath $dataArchiveFile -CompressionLevel Optimal -Force
        }
    }
    finally {
        if ($StopDbForDataSnapshot) {
            Invoke-DockerCompose -Args @("up", "-d", "db")
        }
    }
}

Push-Location $repoRoot
try {
    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

    Invoke-CheckedCommand -Label "Ensure database container is running" -Action {
        Invoke-DockerCompose -Args @("up", "-d", "db")
    }

    Invoke-CheckedCommand -Label "Dump database" -Action {
        New-DatabaseDump
    }

    Invoke-CheckedCommand -Label "Archive project files" -Action {
        New-ProjectArchive
    }

    if ($IncludeDataSnapshot) {
        Invoke-CheckedCommand -Label "Archive MariaDB data directory" -Action {
            New-DataArchive
        }
    }

    Invoke-CheckedCommand -Label "Purge old backups" -Action {
        Remove-OldBackups
    }

    Write-Host "Backup completed."
    Write-Host ("Database dump: " + $dbDumpFile)
    Write-Host ("Project archive: " + $archiveFile)
    if ($IncludeDataSnapshot) {
        Write-Host ("Data snapshot: " + $dataArchiveFile)
    }
}
finally {
    Pop-Location
}
