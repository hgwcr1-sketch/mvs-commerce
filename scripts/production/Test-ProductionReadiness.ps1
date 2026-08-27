param(
    [string]$ApplicationPath = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path,
    [switch]$AllowPendingMail
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$application = Resolve-MvsPath $ApplicationPath
$environment = Get-MvsEnvironment $application
Assert-MvsProductionEnvironment $environment

if ($environment['DB_CONNECTION'] -ne 'sqlite') {
    throw 'Este preflight P08 solo valida la configuración provisional SQLite documentada.'
}

$database = Resolve-MvsPath $environment['DB_DATABASE']
if (-not [System.IO.Path]::IsPathRooted($environment['DB_DATABASE'])) {
    throw 'DB_DATABASE debe ser una ruta absoluta y exclusiva de Producción.'
}
if (-not (Test-Path -LiteralPath $database -PathType Leaf)) { throw "No existe la base de Producción: $database" }
if ((Resolve-MvsPath $database).StartsWith((Resolve-MvsPath (Join-Path $application 'database')), [StringComparison]::OrdinalIgnoreCase)) {
    throw 'La base productiva no puede vivir dentro de database/ del checkout.'
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
    (Join-Path $application 'bootstrap/cache'),
    (Split-Path -Parent $database)
)
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
