<?php

namespace App\Services\Notifications;

use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Notifications\MvsAlertNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlertDispatcher
{
    public function __construct(
        private AlertRecipientResolver $resolver,
    ) {}

    /**
     * Crea o actualiza una alerta y distribuye notificaciones a destinatarios.
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $type, array $data): ?Alert
    {
        $definition = AlertTypeRegistry::forType($type);
        if ($definition === null) {
            return null;
        }

        $companyId = $data['company_id'] ?? null;
        $company = Company::query()->find($companyId);
        if (! $company) {
            return null;
        }

        $branchId = $data['branch_id'] ?? null;
        $severity = $data['severity'] ?? Alert::SEVERITY_INFO;
        $actorId = $data['actor_id'] ?? null;
        $responsibleUserId = $data['responsible_user_id'] ?? null;
        $entityType = $data['entity_type'] ?? null;
        $entityId = $data['entity_id'] ?? null;
        $link = $data['link'] ?? null;
        $metadata = $data['metadata'] ?? [];
        $notes = $data['notes'] ?? null;
        $dedupeKey = $data['dedupe_key'] ?? null;

        $dedupeHash = $this->dedupeHash($type, $companyId, $branchId, $entityType, $entityId, $dedupeKey);

        return DB::transaction(function () use ($type, $company, $branchId, $severity, $actorId, $responsibleUserId, $entityType, $entityId, $link, $metadata, $notes, $dedupeHash) {
            $existing = Alert::query()
                ->where('dedupe_hash', $dedupeHash)
                ->where('company_id', $company->id)
                ->whereIn('status', [Alert::STATUS_NEW, Alert::STATUS_VIEWED, Alert::STATUS_IN_PROGRESS])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'severity' => $severity,
                    'metadata' => array_merge($existing->metadata ?? [], $metadata),
                    'occurred_at' => now(),
                ]);

                $this->distribute($existing, $company);

                return $existing;
            }

            $alert = Alert::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branchId,
                'type' => $type,
                'severity' => $severity,
                'status' => Alert::STATUS_NEW,
                'actor_id' => $actorId,
                'responsible_user_id' => $responsibleUserId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'link' => $link,
                'dedupe_hash' => $dedupeHash,
                'occurred_at' => now(),
                'metadata' => $metadata,
                'notes' => $notes,
            ]);

            $this->distribute($alert, $company);

            return $alert;
        });
    }

    /**
     * Resuelve y persiste destinatarios, luego envía notificación Laravel por database/email.
     */
    private function distribute(Alert $alert, Company $company): void
    {
        try {
            $recipients = $this->resolver->resolve($alert, $company);

            $existingUserIds = $alert->recipients()->pluck('user_id')->all();
            $newRecipients = $recipients->reject(fn (User $user) => in_array($user->id, $existingUserIds, true));

            foreach ($newRecipients as $user) {
                $alert->recipients()->create(['user_id' => $user->id]);
                $user->notify(new MvsAlertNotification($alert));
            }
        } catch (\Throwable $e) {
            Log::error('Error distribuyendo alerta', [
                'alert_id' => $alert->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function dedupeHash(string $type, int $companyId, ?int $branchId, ?string $entityType, ?int $entityId, ?string $dedupeKey): string
    {
        $payload = implode('|', [
            $type,
            $companyId,
            $branchId ?? 'null',
            $entityType ?? 'null',
            $entityId ?? 'null',
            $dedupeKey ?? 'null',
        ]);

        return hash('sha256', $payload);
    }
}
