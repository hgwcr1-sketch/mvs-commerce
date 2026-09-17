<?php

use App\Models\CompanyLicense;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cash:notifications:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('layaways:process')->hourly()->withoutOverlapping();
Schedule::command('payables:alerts')->hourly()->withoutOverlapping();
Schedule::command('loyalty:expire-points')->daily()->withoutOverlapping();

Artisan::command('licenses:refresh', function (CompanyLicenseService $licenses) {
    CompanyLicense::query()->each(fn (CompanyLicense $license) => $licenses->refresh($license));
})->purpose('Actualiza estados de licencia según sus fechas');

Schedule::command('licenses:refresh')->daily()->withoutOverlapping();

Schedule::command('demo:company --reset --force')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/demo-reset.log'));

/*
 * CHECKLIST PRODUCCIÓN - Reset Demo
 * ---------------------------------
 * 1. Cron debe ejecutar `php artisan schedule:run` cada minuto.
 *    Ejemplo: * * * * * cd /ruta/app && php artisan schedule:run >> /dev/null 2>&1
 * 2. APP_TIMEZONE debe coincidir con la hora local del servidor
 *    (ej. America/Costa_Rica) para que dailyAt('02:00') sea la madrugada correcta.
 * 3. El scheduler utiliza `withoutOverlapping()` y `onOneServer()`;
 *    en un solo servidor `onOneServer()` no afecta, pero si hay múltiples
 *    instancias se requiere cache driver centralizada (redis/database).
 * 4. Revisar `storage/logs/demo-reset.log` para confirmar ejecución y errores.
 */
