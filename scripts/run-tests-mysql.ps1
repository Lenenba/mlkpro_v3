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
$testDbName = if ($env:TEST_DB_DATABASE) { $env:TEST_DB_DATABASE } else { "${dbName}_test" }

$mysqlExe = Resolve-ExecutablePath -Name 'mysql'
$phpExe = Resolve-ExecutablePath -Name 'php'
$pestBinary = Join-Path $projectRoot 'vendor\bin\pest'

if (-not $Command -or $Command.Count -eq 0) {
    $Command = @($phpExe, '-d', 'memory_limit=512M', $pestBinary)
} elseif ($Command[0].StartsWith('-') -or $Command[0].StartsWith('tests') -or $Command[0].EndsWith('.php')) {
    $Command = @($phpExe, '-d', 'memory_limit=512M', $pestBinary) + $Command
} elseif ($Command[0] -eq 'php') {
    $Command[0] = $phpExe
}

$mysqlArgs = @(
    '--protocol=TCP',
    '--host', $dbHost,
    '--port', $dbPort,
    '--user', $dbUser
)

$sqlCreate = "DROP DATABASE IF EXISTS ``$testDbName``; CREATE DATABASE ``$testDbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$sqlDrop = "DROP DATABASE IF EXISTS ``$testDbName``;"
$testConfigCache = 'bootstrap/cache/testing-config.php'
$testRoutesCache = 'bootstrap/cache/testing-routes.php'
$testEventsCache = 'bootstrap/cache/testing-events.php'
$testPackagesCache = 'bootstrap/cache/testing-packages.php'
$testServicesCache = 'bootstrap/cache/testing-services.php'

$envOverrides = @{
    'APP_ENV' = 'testing'
    'APP_MAINTENANCE_DRIVER' = 'file'
    'APP_CONFIG_CACHE' = $testConfigCache
    'APP_ROUTES_CACHE' = $testRoutesCache
    'APP_EVENTS_CACHE' = $testEventsCache
    'APP_PACKAGES_CACHE' = $testPackagesCache
    'APP_SERVICES_CACHE' = $testServicesCache
    'BCRYPT_ROUNDS' = '4'
    'CACHE_STORE' = 'array'
    'DB_CONNECTION' = 'mysql'
    'DB_HOST' = $dbHost
    'DB_PORT' = $dbPort
    'DB_DATABASE' = $testDbName
    'DB_USERNAME' = $dbUser
    'DB_PASSWORD' = $dbPassword
    'MAIL_MAILER' = 'array'
    'PULSE_ENABLED' = 'false'
    'QUEUE_CONNECTION' = 'sync'
    'SESSION_DRIVER' = 'array'
    'TELESCOPE_ENABLED' = 'false'
}

$previousEnv = @{}
foreach ($key in $envOverrides.Keys) {
    $hadValue = Test-Path "Env:$key"
    $previousEnv[$key] = @{
        Present = $hadValue
        Value = if ($hadValue) { (Get-Item "Env:$key").Value } else { $null }
    }

    if ([string]::IsNullOrEmpty($envOverrides[$key])) {
        Remove-Item "Env:$key" -ErrorAction SilentlyContinue
    } else {
        Set-Item -Path "Env:$key" -Value $envOverrides[$key]
    }
}

$hadMysqlPwd = Test-Path Env:MYSQL_PWD
$previousMysqlPwd = if ($hadMysqlPwd) { $env:MYSQL_PWD } else { $null }
if ([string]::IsNullOrEmpty($dbPassword)) {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
} else {
    $env:MYSQL_PWD = $dbPassword
}

$exitCode = 0

try {
    foreach ($cacheFile in @($testConfigCache, $testRoutesCache, $testEventsCache, $testPackagesCache, $testServicesCache)) {
        Remove-Item -Path (Join-Path $projectRoot $cacheFile) -ErrorAction SilentlyContinue
    }

    Write-Host "[mysql-test] Reset isolated database '$testDbName'"
    & $mysqlExe @mysqlArgs '--execute' $sqlCreate
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to create isolated test database '$testDbName'. mysql exited with code $LASTEXITCODE."
    }

    Write-Host "[mysql-test] Run command: $($Command -join ' ')"
    & $Command[0] @($Command | Select-Object -Skip 1)
    $exitCode = $LASTEXITCODE
}
finally {
    try {
        Write-Host "[mysql-test] Drop isolated database '$testDbName'"
        & $mysqlExe @mysqlArgs '--execute' $sqlDrop | Out-Null
    } finally {
        foreach ($cacheFile in @($testConfigCache, $testRoutesCache, $testEventsCache, $testPackagesCache, $testServicesCache)) {
            Remove-Item -Path (Join-Path $projectRoot $cacheFile) -ErrorAction SilentlyContinue
        }

        foreach ($key in $previousEnv.Keys) {
            $snapshot = $previousEnv[$key]
            if ($snapshot.Present) {
                Set-Item -Path "Env:$key" -Value $snapshot.Value
            } else {
                Remove-Item "Env:$key" -ErrorAction SilentlyContinue
            }
        }

        if ($hadMysqlPwd) {
            $env:MYSQL_PWD = $previousMysqlPwd
        } else {
            Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
        }
    }
}

exit $exitCode
