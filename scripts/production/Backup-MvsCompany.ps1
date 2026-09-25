param(
    [Parameter(Mandatory)][string]$ApplicationPath,
    [Parameter(Mandatory)][ValidateRange(1, [int]::MaxValue)][int]$CompanyId,
    [Parameter(Mandatory)][string]$BackupRoot,
    [ValidateRange(1, 3650)][int]$RetentionDays = 1825,
    [switch]$AllowLiveDb
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$application = Resolve-MvsPath $ApplicationPath
$backupRootPath = Resolve-MvsPath $BackupRoot
$environment = Get-MvsEnvironment $application
Assert-MvsProductionEnvironment $environment
if ($environment['DB_CONNECTION'] -ne 'pgsql') { throw 'El backup por empresa requiere DB_CONNECTION=pgsql.' }
if ($backupRootPath.StartsWith($application, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'BackupRoot debe estar fuera del checkout activo.'
}
$exportScript = Join-Path $PSScriptRoot 'export-company.sh'
if (-not (Test-Path -LiteralPath $exportScript -PathType Leaf)) { throw "No existe $exportScript." }
if (-not (Get-Command 'bash' -ErrorAction SilentlyContinue)) { throw 'bash no está disponible.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$companyRoot = Join-Path $backupRootPath "per-company\$CompanyId"
$finalDirectory = Join-Path $companyRoot "company-$CompanyId-$stamp"
$workingDirectory = Join-Path $companyRoot ".work-$CompanyId-$stamp-$([guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory -Path $workingDirectory -Force | Out-Null

$previousPassword = $env:PGPASSWORD
try {
    $env:PGPASSWORD = $environment['DB_PASSWORD']
    $arguments = @(
        $exportScript,
        '--company-id', $CompanyId,
        '--db', $environment['DB_DATABASE'],
        '--host', $environment['DB_HOST'],
        '--port', $environment['DB_PORT'],
        '--user', $environment['DB_USERNAME'],
        '--out', $workingDirectory
    )
    if ($AllowLiveDb) { $arguments += '--allow-live-db' }
    & bash @arguments
    if ($LASTEXITCODE -ne 0) { throw 'export-company.sh falló.' }
} finally {
    if ($null -eq $previousPassword) { Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue } else { $env:PGPASSWORD = $previousPassword }
}

try {
    $manifestPath = Join-Path $workingDirectory 'manifest.json'
    if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) { throw 'El export no generó manifest.json.' }
    $manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
    if ([int]$manifest.company_id -ne $CompanyId) { throw 'El manifiesto corresponde a otra empresa.' }
    if ([int]$manifest.format -ne 1) { throw 'Formato de manifiesto no soportado.' }
    foreach ($entry in $manifest.tables) {
        $file = Join-Path $workingDirectory $entry.file
        if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Falta el archivo del manifiesto: $($entry.file)." }
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash
        if ($hash -ine [string]$entry.sha256) { throw "SHA256 inválido en $($entry.file)." }
    }

    Move-Item -LiteralPath $workingDirectory -Destination $finalDirectory
} catch {
    if (Test-Path -LiteralPath $workingDirectory) { Remove-Item -LiteralPath $workingDirectory -Recurse -Force }
    throw
}

$cutoff = (Get-Date).AddDays(-$RetentionDays)
if (Test-Path -LiteralPath $companyRoot -PathType Container) {
    Get-ChildItem -LiteralPath $companyRoot -Directory |
        Where-Object { $_.FullName -ne $finalDirectory -and $_.LastWriteTime -lt $cutoff -and (Test-Path -LiteralPath (Join-Path $_.FullName 'manifest.json')) } |
        Remove-Item -Recurse -Force
}

Write-Output "Backup por empresa $CompanyId verificado: $finalDirectory"
