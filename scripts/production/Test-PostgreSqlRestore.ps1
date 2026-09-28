param(
    [Parameter(Mandatory)][string]$BackupDirectory,
    [Parameter(Mandatory)][string]$TargetDatabase,
    [Parameter(Mandatory)][string]$HostName,
    [int]$Port = 5432,
    [Parameter(Mandatory)][string]$Username,
    [string]$RestoreUploadsRoot
)

. (Join-Path $PSScriptRoot 'Common.ps1')

if ($TargetDatabase -notmatch '_restore_test$') { throw 'TargetDatabase debe terminar en _restore_test.' }
foreach ($command in @('psql', 'pg_restore')) { if (-not (Get-Command $command -ErrorAction SilentlyContinue)) { throw "No está disponible $command." } }

$backup = Resolve-MvsPath $BackupDirectory
$manifest = Get-Content -Raw -LiteralPath (Join-Path $backup 'manifest.json') | ConvertFrom-Json
if ($manifest.database_driver -ne 'pgsql') { throw 'El manifiesto no corresponde a PostgreSQL.' }
$dump = Join-Path $backup $manifest.database_file
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $dump).Hash -ne $manifest.database_sha256) { throw 'Hash del dump inválido.' }
$uploads = Join-Path $backup 'uploads.zip'
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $uploads).Hash -ne $manifest.uploads_sha256) { throw 'Hash de uploads inválido.' }
if ($RestoreUploadsRoot) {
    $uploadsTarget = Resolve-MvsPath $RestoreUploadsRoot
    if (Test-Path -LiteralPath $uploadsTarget) { throw 'RestoreUploadsRoot ya existe; se rechaza sobrescribirlo.' }
    Expand-Archive -LiteralPath $uploads -DestinationPath $uploadsTarget
}

$tableCount = (& psql --no-password --tuples-only --no-align --host=$HostName --port=$Port --username=$Username --dbname=$TargetDatabase --command="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';").Trim()
if ($LASTEXITCODE -ne 0) { throw 'No se pudo inspeccionar la base de restore.' }
if ($tableCount -ne '0') { throw 'La base de restore no está vacía; se rechaza sobrescribirla.' }

& pg_restore --no-owner --no-acl --exit-on-error --host=$HostName --port=$Port --username=$Username --dbname=$TargetDatabase $dump
if ($LASTEXITCODE -ne 0) { throw 'Falló el restore PostgreSQL.' }

$migrationCount = (& psql --no-password --tuples-only --no-align --host=$HostName --port=$Port --username=$Username --dbname=$TargetDatabase --command='SELECT COUNT(*) FROM migrations;').Trim()
if ($LASTEXITCODE -ne 0 -or [int]$migrationCount -lt 1) { throw 'Restore sin migraciones verificables.' }
Write-Output "Restore PostgreSQL aislado verificado en $TargetDatabase. La base se conserva para inspección manual."
