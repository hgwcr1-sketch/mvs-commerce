param(
    [Parameter(Mandatory)][string]$ApplicationPath,
    [Parameter(Mandatory)][string]$BackupRoot,
    [string]$Branch = 'feature/pos'
)

. (Join-Path $PSScriptRoot 'Common.ps1')
$application = Resolve-MvsPath $ApplicationPath
$environment = Get-MvsEnvironment $application
Assert-MvsProductionEnvironment $environment

Push-Location $application
try {
    if ((git status --porcelain)) { throw 'El checkout productivo tiene cambios locales.' }
    if ((git branch --show-current).Trim() -ne $Branch) { throw "La rama productiva no es $Branch." }

    & (Join-Path $PSScriptRoot 'Backup-MvsProduction.ps1') -ApplicationPath $application -BackupRoot $BackupRoot
    if ($LASTEXITCODE -ne 0) { throw 'El backup previo falló.' }

    git fetch origin $Branch
    if ($LASTEXITCODE -ne 0) { throw 'Falló git fetch.' }
    $local = (git rev-parse HEAD).Trim()
    $remote = (git rev-parse "origin/$Branch").Trim()
    git merge-base --is-ancestor $local $remote
    if ($LASTEXITCODE -ne 0) { throw 'Producción no puede avanzar por fast-forward seguro.' }

    php artisan down --retry=30
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo activar mantenimiento; se cancela el despliegue.' }
    git merge --ff-only "origin/$Branch"
    if ($LASTEXITCODE -ne 0) { throw 'Falló el fast-forward; la aplicación permanece en mantenimiento.' }
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Falló Composer; la aplicación permanece en mantenimiento.' }
    npm.cmd ci
    if ($LASTEXITCODE -ne 0) { throw 'Falló npm ci; la aplicación permanece en mantenimiento.' }
    npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'Falló el build; la aplicación permanece en mantenimiento.' }
    php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Fallaron las migraciones; la aplicación permanece en mantenimiento.' }
    php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { throw 'Falló optimize:clear; la aplicación permanece en mantenimiento.' }
    php artisan config:cache
    if ($LASTEXITCODE -ne 0) { throw 'Falló config:cache; la aplicación permanece en mantenimiento.' }
    php artisan event:cache
    if ($LASTEXITCODE -ne 0) { throw 'Falló event:cache; la aplicación permanece en mantenimiento.' }
    php artisan route:cache
    if ($LASTEXITCODE -ne 0) { throw 'Falló route:cache; la aplicación permanece en mantenimiento.' }
    php artisan view:cache
    if ($LASTEXITCODE -ne 0) { throw 'Falló view:cache; la aplicación permanece en mantenimiento.' }
    php artisan storage:link
    if ($LASTEXITCODE -ne 0) { throw 'Falló storage:link; la aplicación permanece en mantenimiento.' }
    php artisan queue:restart
    if ($LASTEXITCODE -ne 0) { throw 'Falló queue:restart; la aplicación permanece en mantenimiento.' }
    php artisan up
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo sacar la aplicación de mantenimiento.' }
    Write-Output "Despliegue completado en $((git rev-parse HEAD).Trim())."
} finally {
    Pop-Location
}
