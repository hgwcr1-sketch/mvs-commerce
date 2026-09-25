param(
    [Parameter(Mandatory)][string]$BackupDirectory,
    [Parameter(Mandatory)][string]$TargetDatabase,
    [Parameter(Mandatory)][string]$HostName,
    [int]$Port = 5432,
    [Parameter(Mandatory)][string]$Username,
    [string]$ReportPath
)

. (Join-Path $PSScriptRoot 'Common.ps1')

$backup = Resolve-MvsPath $BackupDirectory
if (-not (Test-Path -LiteralPath (Join-Path $backup 'manifest.json') -PathType Leaf)) {
    throw 'El backup no contiene manifest.json; use un export válido de V1.'
}
if ($TargetDatabase -notmatch '_company_restore_test$') {
    throw 'TargetDatabase debe terminar en _company_restore_test.'
}
if ($TargetDatabase -eq 'mvscommerce_production') { throw 'Nunca se restaura en mvscommerce_production.' }

$restoreScript = Join-Path $PSScriptRoot 'restore-company.sh'
if (-not (Test-Path -LiteralPath $restoreScript -PathType Leaf)) { throw "No existe $restoreScript." }
if (-not (Get-Command 'bash' -ErrorAction SilentlyContinue)) { throw 'bash no está disponible.' }

$arguments = @(
    $restoreScript,
    '--dir', $backup,
    '--db', $TargetDatabase,
    '--host', $HostName,
    '--port', $Port,
    '--user', $Username
)
if ($ReportPath) { $arguments += @('--report', $ReportPath) }

& bash @arguments
if ($LASTEXITCODE -ne 0) { throw 'restore-company.sh falló; revise la salida anterior.' }

$manifest = Get-Content -Raw -LiteralPath (Join-Path $backup 'manifest.json') | ConvertFrom-Json
if ($ReportPath) {
    if (-not (Test-Path -LiteralPath $ReportPath -PathType Leaf)) { throw 'No se generó el reporte de restore.' }
    $report = Get-Content -Raw -LiteralPath $ReportPath | ConvertFrom-Json
    if ($report.status -ne 'ok') { throw "Estado del reporte inválido: $($report.status)." }
    if ([int]$report.company_id -ne [int]$manifest.company_id) { throw 'El reporte corresponde a otra empresa.' }
    if ([int]$report.cross_company_rows -ne 0) { throw 'El reporte detectó filas de otra empresa.' }
}

Write-Output "Restore por empresa $($manifest.company_id) verificado en $TargetDatabase (BD conservada para inspección)."
