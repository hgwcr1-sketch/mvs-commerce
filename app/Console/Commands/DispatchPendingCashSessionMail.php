<?php

namespace App\Console\Commands;

use App\Jobs\SendCashSessionMailNotification;
use App\Models\CashSessionMailNotification;
use Illuminate\Console\Command;

class DispatchPendingCashSessionMail extends Command
{
    protected $signature = 'cash:notifications:dispatch-pending';
    protected $description = 'Despacha avisos pendientes o fallidos de apertura y cierre de Caja';

    public function handle(): int
    {
        $count = 0;
        CashSessionMailNotification::query()->dispatchable()->orderBy('id')->chunkById(100, function ($notifications) use (&$count) {
            foreach ($notifications as $notification) {
                SendCashSessionMailNotification::dispatch($notification->id);
                $count++;
            }
        });
        $this->info("Avisos despachados: {$count}");
        return self::SUCCESS;
    }
}
