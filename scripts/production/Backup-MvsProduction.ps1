param(
    [Parameter(Mandatory)][string]$ApplicationPath,
    [Parameter(Mandatory)][string]$BackupRoot,
    [ValidateRange(1, 3650)][int]$RetentionDays = 30
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$application = Resolve-MvsPath $ApplicationPath
$backupRootPath = Resolve-MvsPath $BackupRoot
$environment = Get-MvsEnvironment $application
Assert-MvsProductionEnvironment $environment
if ($environment['DB_CONNECTION'] -ne 'sqlite') { throw 'Backup-MvsProduction P08 soporta únicamente SQLite.' }

$database = Resolve-MvsPath $environment['DB_DATABASE']
if (-not (Test-Path -LiteralPath $database -PathType Leaf)) { throw "No existe la base: $database" }
if ($backupRootPath.StartsWith($application, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'BackupRoot debe estar fuera del checkout activo.'
}

New-Item -ItemType Directory -Path $backupRootPath -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$finalDirectory = Join-Path $backupRootPath "mvs-$stamp"
$workingDirectory = Join-Path $backupRootPath ".mvs-$stamp-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Path $workingDirectory | Out-Null

try {
    $databaseCopy = Join-Path $workingDirectory 'database.sqlite'
    $sqliteHelper = Join-Path $PSScriptRoot 'sqlite-backup.php'
    & php $sqliteHelper backup $database $databaseCopy
    if ($LASTEXITCODE -ne 0) { throw 'Falló la copia consistente de SQLite.' }

    & php $sqliteHelper integrity $databaseCopy
    if ($LASTEXITCODE -ne 0) { throw 'La copia SQLite no superó integrity_check.' }

    $uploads = Join-Path $application 'storage/app/public'
    if (-not (Test-Path -LiteralPath $uploads -PathType Container)) { throw "No existe el directorio de uploads: $uploads" }
    Compress-Archive -LiteralPath $uploads -DestinationPath (Join-Path $workingDirectory 'uploads.zip') -CompressionLevel Optimal

    $gitCommit = (& git -C $application rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or -not $gitCommit) { throw 'No se pudo identificar el commit del checkout productivo.' }

    $manifest = [ordered]@{
        format = 1
        created_at = (Get-Date).ToUniversalTime().ToString('o')
        database_driver = 'sqlite'
        database_sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $databaseCopy).Hash
        uploads_sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $workingDirectory 'uploads.zip')).Hash
        git_commit = $gitCommit.Trim()
    }
    $manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $workingDirectory 'manifest.json') -Encoding UTF8
    Move-Item -LiteralPath $workingDirectory -Destination $finalDirectory

    $cutoff = (Get-Date).AddDays(-$RetentionDays)
    Get-ChildItem -LiteralPath $backupRootPath -Directory -Filter 'mvs-*' |
        Where-Object { $_.LastWriteTime -lt $cutoff -and (Test-Path -LiteralPath (Join-Path $_.FullName 'manifest.json')) } |
        Remove-Item -Recurse -Force

    Write-Output "Backup verificado: $finalDirectory"
} catch {
    if (Test-Path -LiteralPath $workingDirectory) { Remove-Item -LiteralPath $workingDirectory -Recurse -Force }
    throw
}
