<?php

namespace App\Services\Cash;

use App\Jobs\SendCashSessionMailNotification;
use App\Models\CashSession;
use App\Models\CashSessionMailNotification;
use App\Models\CompanyCashSetting;

class CashSessionMailNotificationService
{
    public function create(CashSession $session, string $type, ?CompanyCashSetting $settings = null): CashSessionMailNotification
    {
        $settings ??= CompanyCashSetting::query()->where('company_id', $session->company_id)->firstOrFail();
        $recipients = collect($settings->closure_email_recipients ?? [])
            ->filter(fn ($email) => is_string($email))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $notification = CashSessionMailNotification::query()->firstOrCreate(
            ['cash_session_id' => $session->id, 'notification_type' => $type],
            [
                'company_id' => $session->company_id,
                'recipients' => $recipients,
                'delivered_recipients' => [],
                'status' => $recipients === [] ? CashSessionMailNotification::STATUS_SKIPPED : CashSessionMailNotification::STATUS_PENDING,
                'available_at' => $recipients === [] ? null : now(),
            ],
        );

        if ($notification->wasRecentlyCreated && $notification->status === CashSessionMailNotification::STATUS_PENDING) {
            SendCashSessionMailNotification::dispatch($notification->id)->afterCommit();
        }

        return $notification;
    }
}
