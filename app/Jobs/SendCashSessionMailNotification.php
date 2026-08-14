<?php

namespace App\Jobs;

use App\Mail\CashSessionClosedMail;
use App\Mail\CashSessionOpenedMail;
use App\Models\CashSessionMailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendCashSessionMailNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = DB::transaction(function () {
            $row = CashSessionMailNotification::query()->lockForUpdate()->find($this->notificationId);
            if (! $row || $row->status === CashSessionMailNotification::STATUS_SENT || $row->status === CashSessionMailNotification::STATUS_SKIPPED) return null;
            if ($row->status === CashSessionMailNotification::STATUS_PROCESSING || $row->attempts >= CashSessionMailNotification::MAX_ATTEMPTS || ($row->available_at && $row->available_at->isFuture())) return null;
            if (! in_array($row->status, [CashSessionMailNotification::STATUS_PENDING, CashSessionMailNotification::STATUS_FAILED], true)) return null;
            $row->update(['status' => CashSessionMailNotification::STATUS_PROCESSING, 'attempts' => $row->attempts + 1, 'last_error' => null]);
            return $row->fresh();
        });

        if (! $notification) return;

        try {
            $session = $notification->cashSession()->firstOrFail();
            foreach ($notification->recipients as $recipient) {
                $fresh = $notification->fresh();
                if (in_array($recipient, $fresh->delivered_recipients ?? [], true)) continue;
                $mailable = $notification->notification_type === CashSessionMailNotification::TYPE_OPENED
                    ? new CashSessionOpenedMail($session)
                    : new CashSessionClosedMail($session);
                Mail::to($recipient)->send($mailable);
                $delivered = collect($fresh->delivered_recipients ?? [])->push($recipient)->unique()->values()->all();
                $fresh->update(['delivered_recipients' => $delivered]);
            }

            $notification->fresh()->update([
                'status' => CashSessionMailNotification::STATUS_SENT,
                'last_error' => null,
                'available_at' => null,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $fresh = $notification->fresh();
            $delay = match ($fresh->attempts) { 1 => 60, 2 => 300, 3 => 900, 4 => 3600, default => null };
            $fresh->update([
                'status' => CashSessionMailNotification::STATUS_FAILED,
                'last_error' => $this->sanitize($exception),
                'available_at' => $delay === null ? null : now()->addSeconds($delay),
            ]);
            throw $exception;
        }
    }

    private function sanitize(Throwable $exception): string
    {
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[correo oculto]', $exception->getMessage());
        $message = preg_replace('#(https?://)[^\s/@:]+:[^\s/@]+@#iu', '$1[credenciales ocultas]@', (string) $message);
        $message = preg_replace('/\b(password|passwd|token|secret|api[_-]?key)\s*[=:]\s*[^\s,;]+/iu', '$1=[oculto]', (string) $message);
        $message = preg_replace('/\s+/', ' ', (string) $message);
        return Str::limit(class_basename($exception).': '.$message, 500, '');
    }
}
