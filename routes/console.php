<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cash:notifications:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('layaways:process')->hourly()->withoutOverlapping();
Schedule::command('payables:alerts')->hourly()->withoutOverlapping();
