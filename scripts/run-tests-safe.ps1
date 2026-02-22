param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Command = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-DotEnvValue {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [string]$Key
    )

    if (!(Test-Path -Path $Path)) {
        throw "Unable to find .env file at: $Path"
    }

    $prefix = "$Key="
    foreach ($line in Get-Content -Path $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#')) {
            continue
        }
        if (!$trimmed.StartsWith($prefix)) {
            continue
        }

        $value = $trimmed.Substring($prefix.Length)
        if ($value.Length -ge 2) {
            $first = $value[0]
            $last = $value[$value.Length - 1]
            if (($first -eq '"' -and $last -eq '"') -or ($first -eq "'" -and $last -eq "'")) {
                $value = $value.Substring(1, $value.Length - 2)
            }
        }

        return $value
    }

    return ''
}

function Resolve-ExecutablePath {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Name
    )

    $cmd = Get-Command -Name $Name -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    $cmdExe = Get-Command -Name ($Name + '.exe') -ErrorAction SilentlyContinue
    if ($cmdExe) {
        return $cmdExe.Source
    }

    throw "Executable not found in PATH: $Name"
}

function Quote-CmdArg {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Value
    )

    if ($Value -match '[\s"]') {
        return '"' + ($Value -replace '"', '\"') + '"'
    }

    return $Value
}

function Enforce-BackupRetention {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [int]$MaxFiles,
        [string]$KeepFile = ''
    )

    if ($MaxFiles -lt 1) {
        throw 'MaxFiles must be at least 1.'
    }

    if (!(Test-Path -Path $Path)) {
        return
    }

    $keepPath = ''
    if (-not [string]::IsNullOrWhiteSpace($KeepFile)) {
        try {
            $keepPath = (Resolve-Path -Path $KeepFile -ErrorAction Stop).Path
        } catch {
            $keepPath = ''
        }
    }

    $backups = @(Get-ChildItem -Path $Path -File -Filter 'pre_test_*.sql' | Sort-Object LastWriteTimeUtc, Name)
    if ($backups.Count -le $MaxFiles) {
        return
    }

    $toRemoveCount = $backups.Count - $MaxFiles
    $candidates = if ($keepPath) {
        @($backups | Where-Object { $_.FullName -ne $keepPath })
    } else {
        $backups
    }

    $toRemove = @($candidates | Select-Object -First $toRemoveCount)
    foreach ($file in $toRemove) {
        Write-Host "[safe-test] Delete old backup: $($file.FullName)"
        Remove-Item -Path $file.FullName -Force
    }
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envPath = Join-Path $projectRoot '.env'

$dbConnection = (Get-DotEnvValue -Path $envPath -Key 'DB_CONNECTION').ToLowerInvariant()
if ($dbConnection -ne 'mysql') {
    throw "This script only supports DB_CONNECTION=mysql. Current: '$dbConnection'"
}

$dbHost = Get-DotEnvValue -Path $envPath -Key 'DB_HOST'
if ([string]::IsNullOrWhiteSpace($dbHost)) {
    $dbHost = '127.0.0.1'
}

$dbPort = Get-DotEnvValue -Path $envPath -Key 'DB_PORT'
if ([string]::IsNullOrWhiteSpace($dbPort)) {
    $dbPort = '3306'
}

$dbName = Get-DotEnvValue -Path $envPath -Key 'DB_DATABASE'
if ([string]::IsNullOrWhiteSpace($dbName)) {
    throw 'DB_DATABASE is required in .env'
}

$dbUser = Get-DotEnvValue -Path $envPath -Key 'DB_USERNAME'
if ([string]::IsNullOrWhiteSpace($dbUser)) {
    throw 'DB_USERNAME is required in .env'
}

$dbPassword = Get-DotEnvValue -Path $envPath -Key 'DB_PASSWORD'

$mysqldumpExe = Resolve-ExecutablePath -Name 'mysqldump'
$mysqlExe = Resolve-ExecutablePath -Name 'mysql'

$backupDir = Join-Path $projectRoot 'storage\backups'
$maxBackupFiles = 3
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$backupFile = Join-Path $backupDir ("pre_test_{0}_{1}.sql" -f $dbName, $timestamp)

$mysqlArgs = @(
    '--protocol=TCP',
    '--host', $dbHost,
    '--port', $dbPort,
    '--user', $dbUser
)

if (-not $Command -or $Command.Count -eq 0) {
    $Command = @('php', 'artisan', 'test')
} elseif ($Command[0].StartsWith('-')) {
    $Command = @('php', 'artisan', 'test') + $Command
}

$backupCompleted = $false
$restoreCompleted = $false
$testsExitCode = 0
$testsCommandFailed = $false

$hadMysqlPwd = Test-Path Env:MYSQL_PWD
$previousMysqlPwd = if ($hadMysqlPwd) { $env:MYSQL_PWD } else { $null }

if (-not [string]::IsNullOrEmpty($dbPassword)) {
    $env:MYSQL_PWD = $dbPassword
} else {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}

try {
    Write-Host "[safe-test] Backup '$dbName' -> $backupFile"
    $dumpArgs = @()
    $dumpArgs += $mysqlArgs
    $dumpArgs += @(
        '--single-transaction',
        '--skip-lock-tables',
        '--routines',
        '--triggers',
        '--set-gtid-purged=OFF',
        "--result-file=$backupFile",
        $dbName
    )

    & $mysqldumpExe @dumpArgs
    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump failed with exit code $LASTEXITCODE."
    }

    $backupCompleted = $true

    Write-Host "[safe-test] Run tests: $($Command -join ' ')"
    & $Command[0] @($Command | Select-Object -Skip 1)
    $testsExitCode = $LASTEXITCODE
}
catch {
    $testsCommandFailed = $true
    if ($testsExitCode -eq 0) {
        $testsExitCode = 1
    }
    Write-Warning "[safe-test] Execution failed: $($_.Exception.Message)"
}
finally {
    try {
        if ($backupCompleted) {
            Write-Host "[safe-test] Restore '$dbName' from backup"

            $dropCreateSql = "DROP DATABASE IF EXISTS ``$dbName``; CREATE DATABASE ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            & $mysqlExe @mysqlArgs '--execute' $dropCreateSql
            if ($LASTEXITCODE -ne 0) {
                throw "mysql drop/create failed with exit code $LASTEXITCODE."
            }

            $importArgs = @()
            $importArgs += $mysqlArgs
            $importArgs += @($dbName)

            $importCmd = '"' + $mysqlExe + '" ' + (($importArgs | ForEach-Object { Quote-CmdArg -Value $_ }) -join ' ') + ' < "' + $backupFile + '"'
            cmd.exe /d /c $importCmd | Out-Null
            if ($LASTEXITCODE -ne 0) {
                throw "mysql restore import failed with exit code $LASTEXITCODE."
            }

            $restoreCompleted = $true
            Write-Host "[safe-test] Restore complete"

            Enforce-BackupRetention -Path $backupDir -MaxFiles $maxBackupFiles -KeepFile $backupFile
        } else {
            Write-Warning '[safe-test] Restore skipped because backup did not complete.'
        }
    }
    finally {
        if ($hadMysqlPwd) {
            $env:MYSQL_PWD = $previousMysqlPwd
        } else {
            Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
        }
    }
}

if (-not $restoreCompleted) {
    Write-Error '[safe-test] Database restore did not complete. Your database may not be in its original state.'
    exit 1
}

if ($testsCommandFailed) {
    exit $testsExitCode
}

exit $testsExitCode
