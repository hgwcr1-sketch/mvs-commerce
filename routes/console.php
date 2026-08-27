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
