param(
    [string]$ApplicationPath = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path,
    [string]$EnvironmentName = 'postgresql.testing'
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$application = Resolve-MvsPath $ApplicationPath
$envFile = Join-Path $application ".env.$EnvironmentName"
if (-not (Test-Path -LiteralPath $envFile -PathType Leaf)) { throw "Cree $envFile desde docs/produccion/env.postgresql.testing.example, sin versionarlo." }
$environment = Get-MvsEnvironment $application
$testEnvironment = @{}
foreach ($line in Get-Content -LiteralPath $envFile) {
    $trimmed = $line.Trim()
    if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) { continue }
    $parts = $trimmed.Split('=', 2)
    $testEnvironment[$parts[0].Trim()] = $parts[1].Trim().Trim('"').Trim("'")
}
if ($testEnvironment['DB_CONNECTION'] -ne 'pgsql') { throw 'El entorno de prueba debe usar pgsql.' }
if ($testEnvironment['DB_DATABASE'] -notmatch '_test$') { throw 'DB_DATABASE debe terminar en _test.' }
if (-not (Get-Command 'psql' -ErrorAction SilentlyContinue)) { throw 'psql no está disponible.' }

$previousPassword = $env:PGPASSWORD
try {
    $env:PGPASSWORD = $testEnvironment['DB_PASSWORD']
    $count = (& psql --tuples-only --no-align --host=$($testEnvironment['DB_HOST']) --port=$($testEnvironment['DB_PORT']) --username=$($testEnvironment['DB_USERNAME']) --dbname=$($testEnvironment['DB_DATABASE']) --command="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';").Trim()
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo inspeccionar PostgreSQL.' }
    if ($count -ne '0') { throw 'La base de compatibilidad no está vacía. El script no borra ni reinicia bases existentes.' }

    Push-Location $application
    try {
        & php artisan migrate --env=$EnvironmentName --force
        if ($LASTEXITCODE -ne 0) { throw 'Las migraciones PostgreSQL fallaron.' }
        & php artisan migrate:status --env=$EnvironmentName
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo verificar el estado de migraciones.' }
    } finally { Pop-Location }
} finally {
    if ($null -eq $previousPassword) { Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue } else { $env:PGPASSWORD = $previousPassword }
}

Write-Output 'Migraciones PostgreSQL verificadas. La base *_test se conserva; este script nunca la elimina.'
