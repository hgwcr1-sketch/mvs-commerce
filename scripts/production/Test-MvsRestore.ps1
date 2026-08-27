param(
    [Parameter(Mandatory)][string]$BackupDirectory,
    [string]$RestoreRoot = (Join-Path ([IO.Path]::GetTempPath()) ('mvs-restore-' + [guid]::NewGuid().ToString('N'))),
    [switch]$KeepRestore
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$backup = Resolve-MvsPath $BackupDirectory
$restore = Resolve-MvsPath $RestoreRoot
$manifestPath = Join-Path $backup 'manifest.json'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) { throw 'El backup no contiene manifest.json.' }
if (Test-Path -LiteralPath $restore) { throw 'RestoreRoot ya existe; se rechaza sobrescribirlo.' }

$manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
$databaseSource = Join-Path $backup 'database.sqlite'
$uploadsSource = Join-Path $backup 'uploads.zip'
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $databaseSource).Hash -ne $manifest.database_sha256) { throw 'Hash de base de datos inválido.' }
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $uploadsSource).Hash -ne $manifest.uploads_sha256) { throw 'Hash de uploads inválido.' }

New-Item -ItemType Directory -Path $restore | Out-Null
try {
    Copy-Item -LiteralPath $databaseSource -Destination (Join-Path $restore 'database.sqlite')
    Expand-Archive -LiteralPath $uploadsSource -DestinationPath (Join-Path $restore 'uploads')
    & php (Join-Path $PSScriptRoot 'sqlite-backup.php') integrity (Join-Path $restore 'database.sqlite')
    if ($LASTEXITCODE -ne 0) { throw 'La restauración no superó integrity_check.' }
    Write-Output "Restore aislado verificado: $restore"
} finally {
    if (-not $KeepRestore -and (Test-Path -LiteralPath $restore)) { Remove-Item -LiteralPath $restore -Recurse -Force }
}
