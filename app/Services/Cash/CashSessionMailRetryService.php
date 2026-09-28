<?php

namespace App\Services\Cash;

use App\Jobs\SendCashSessionMailNotification;
use App\Models\CashSessionEvent;
use App\Models\CashSessionMailNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashSessionMailRetryService
{
    public function retry(int $companyId, int $sessionId, int $notificationId, User $user): CashSessionMailNotification
    {
        $notification = DB::transaction(function () use ($companyId, $sessionId, $notificationId, $user) {
            $row = CashSessionMailNotification::query()->lockForUpdate()->findOrFail($notificationId);
            abort_unless($row->company_id === $companyId && $row->cash_session_id === $sessionId, 404);

            if (! $row->isAdministrativelyRetriable()) {
                throw ValidationException::withMessages(['notification' => 'Esta notificación no está disponible para reintento.']);
            }

            $previousStatus = $row->status;
            $row->update(['status' => CashSessionMailNotification::STATUS_PENDING, 'available_at' => now()]);
            CashSessionEvent::query()->create([
                'cash_session_id' => $sessionId,
                'event_type' => CashSessionEvent::TYPE_MAIL_RETRY_REQUESTED,
                'user_id' => $user->id,
                'payload' => ['notification_id' => $row->id, 'notification_type' => $row->notification_type, 'previous_status' => $previousStatus, 'attempts' => $row->attempts],
                'occurred_at' => now(),
            ]);

            return $row->fresh();
        });

        SendCashSessionMailNotification::dispatch($notification->id)->afterCommit();

        return $notification;
    }
}
