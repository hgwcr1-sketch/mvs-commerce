param(
    [string]$ApplicationPath = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path,
    [switch]$AllowPendingMail
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$application = Resolve-MvsPath $ApplicationPath
$environment = Get-MvsEnvironment $application
Assert-MvsProductionEnvironment $environment

if ($environment['DB_CONNECTION'] -notin @('sqlite', 'pgsql')) {
    throw 'P08 admite únicamente SQLite provisional o PostgreSQL cloud.'
}

$databaseDirectory = $null
if ($environment['DB_CONNECTION'] -eq 'sqlite') {
    $database = Resolve-MvsPath $environment['DB_DATABASE']
    if (-not [System.IO.Path]::IsPathRooted($environment['DB_DATABASE'])) { throw 'DB_DATABASE debe ser una ruta absoluta y exclusiva de Producción.' }
    if (-not (Test-Path -LiteralPath $database -PathType Leaf)) { throw "No existe la base de Producción: $database" }
    if ($database.StartsWith((Resolve-MvsPath (Join-Path $application 'database')), [StringComparison]::OrdinalIgnoreCase)) { throw 'La base productiva no puede vivir dentro de database/ del checkout.' }
    $databaseDirectory = Split-Path -Parent $database
} else {
    foreach ($key in @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME')) {
        if (-not $environment[$key]) { throw "Falta $key para PostgreSQL." }
    }
    if (-not (Get-Command 'pg_dump' -ErrorAction SilentlyContinue) -or -not (Get-Command 'pg_restore' -ErrorAction SilentlyContinue)) {
        throw 'PostgreSQL requiere pg_dump y pg_restore en PATH.'
    }
}
if (-not $AllowPendingMail -and $environment['MAIL_MAILER'] -eq 'log') {
    throw 'MAIL_MAILER continúa en log. Configure SMTP o ejecute con -AllowPendingMail para una activación sin correo real.'
}

$requiredCommands = @('php', 'composer', 'git', 'npm.cmd')
foreach ($command in $requiredCommands) {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) { throw "No está disponible el comando requerido: $command" }
}

$writablePaths = @(
    (Join-Path $application 'storage'),
    (Join-Path $application 'bootstrap/cache')
)
if ($databaseDirectory) { $writablePaths += $databaseDirectory }
foreach ($path in $writablePaths) {
    if (-not (Test-Path -LiteralPath $path -PathType Container)) { throw "No existe el directorio requerido: $path" }
    $probe = Join-Path $path ('.mvs-write-test-' + [guid]::NewGuid().ToString('N'))
    try { [IO.File]::WriteAllText($probe, 'ok') } finally { if (Test-Path -LiteralPath $probe) { Remove-Item -LiteralPath $probe -Force } }
}

Push-Location $application
try {
    & php artisan about --only=environment,drivers
    if ($LASTEXITCODE -ne 0) { throw 'Falló artisan about.' }
    & php artisan migrate:status
    if ($LASTEXITCODE -ne 0) { throw 'Falló migrate:status.' }
    & php artisan schedule:list
    if ($LASTEXITCODE -ne 0) { throw 'Falló schedule:list.' }
    & php artisan storage:link
    if ($LASTEXITCODE -ne 0) { throw 'Falló storage:link.' }
} finally {
    Pop-Location
}

Write-Output 'Preflight productivo correcto. No se modificaron datos de negocio.'
