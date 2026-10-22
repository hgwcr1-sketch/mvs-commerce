<?php

declare(strict_types=1);

namespace App\Services\Cobros;

use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosCreditRequestLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REN-03D1 — Aprobación, rechazo y consentimiento firmado de renovaciones.
 *
 * NO activa créditos, NO cierra deudas, NO crea pagos ni desembolsos.
 * La firma es PRECONDICIÓN de la fase posterior de activación y queda
 * vinculada por SHA-256 a los términos EXACTOS congelados de la renovación.
 */
class CreditRenewalConsentService
{
    public const CONSENT_TEXT = 'He revisado las condiciones de esta renovación de crédito y confirmo '
        .'la deuda anterior, el nuevo capital, los intereses, el total consolidado '
        .'y el calendario de pagos mostrados. Acepto renovar bajo estas condiciones.';

    public function approve(CobrosCreditRenewalRequest $renewal, User $actor): CobrosCreditRenewalRequest
    {
        if ($renewal->status !== CobrosCreditRenewalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'renewal' => 'Solo se pueden aprobar renovaciones pendientes.',
            ]);
        }

        return DB::transaction(function () use ($renewal, $actor): CobrosCreditRenewalRequest {
            $locked = CobrosCreditRenewalRequest::query()
                ->where('company_id', $renewal->company_id)
                ->whereKey($renewal->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status !== CobrosCreditRenewalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'renewal' => 'La renovación ya fue resuelta.',
                ]);
            }

            $locked->update([
                'status' => CobrosCreditRenewalRequest::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->log($locked, $actor, 'Renovación aprobada por el prestamista.');

            return $locked->fresh();
        });
    }

    public function reject(CobrosCreditRenewalRequest $renewal, User $actor, ?string $reason): CobrosCreditRenewalRequest
    {
        if ($renewal->status !== CobrosCreditRenewalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'renewal' => 'Solo se pueden rechazar renovaciones pendientes.',
            ]);
        }

        return DB::transaction(function () use ($renewal, $actor, $reason): CobrosCreditRenewalRequest {
            $locked = CobrosCreditRenewalRequest::query()
                ->where('company_id', $renewal->company_id)
                ->whereKey($renewal->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status !== CobrosCreditRenewalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'renewal' => 'La renovación ya fue resuelta.',
                ]);
            }

            $locked->update([
                'status' => CobrosCreditRenewalRequest::STATUS_REJECTED,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'reject_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            ]);

            $this->log($locked, $actor, 'Renovación rechazada.');

            return $locked->fresh();
        });
    }

    /**
     * Consentimiento firmado del cliente. Exige renovación aprobada y firma
     * NUEVA; vincula la firma a los términos exactos por hash SHA-256.
     */
    public function consent(CobrosCreditRenewalRequest $renewal, User $actor, ?string $signature, ?string $postedTermsHash): CobrosCreditRenewalRequest
    {
        $png = $this->decodeSignature($signature);

        $snapshot = $this->consentSnapshot($renewal);
        $hash = $this->hashSnapshot($snapshot);

        // Términos manipulados en el navegador: el hash publicado debe coincidir
        // con el que el servidor recalcula desde los valores congelados.
        if ($postedTermsHash !== null && ! hash_equals($hash, trim($postedTermsHash))) {
            throw ValidationException::withMessages([
                'terms_hash' => 'Los términos mostrados no coinciden con los registrados. Refresque e intente de nuevo.',
            ]);
        }

        // Reutilización: ya consentida → error de firma, no genérico de estado.
        if ($renewal->status === CobrosCreditRenewalRequest::STATUS_CONSENTED) {
            throw ValidationException::withMessages([
                'signature' => 'Esta renovación ya tiene consentimiento firmado; no se admite reutilizar la firma.',
            ]);
        }

        if ($renewal->status !== CobrosCreditRenewalRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'renewal' => 'La renovación debe estar aprobada antes del consentimiento del cliente.',
            ]);
        }

        $path = 'cobros/renewal-consent/'.$renewal->id.'/'.Str::uuid()->toString().'.png';
        Storage::disk('local')->put($path, $png);
        $wroteFile = true;

        try {
            return DB::transaction(function () use ($renewal, $actor, $path, $snapshot, $hash, &$wroteFile): CobrosCreditRenewalRequest {
                $locked = CobrosCreditRenewalRequest::query()
                    ->where('company_id', $renewal->company_id)
                    ->whereKey($renewal->id)
                    ->lockForUpdate()
                    ->first();
                if ($locked === null) {
                    abort(404);
                }

                if ($locked->status === CobrosCreditRenewalRequest::STATUS_CONSENTED) {
                    $wroteFile = false;
                    throw ValidationException::withMessages([
                        'signature' => 'Esta renovación ya tiene consentimiento firmado; no se admite reutilizar la firma.',
                    ]);
                }
                if ($locked->status !== CobrosCreditRenewalRequest::STATUS_APPROVED) {
                    $wroteFile = false;
                    throw ValidationException::withMessages([
                        'renewal' => 'La renovación debe estar aprobada antes del consentimiento del cliente.',
                    ]);
                }

                $locked->update([
                    'status' => CobrosCreditRenewalRequest::STATUS_CONSENTED,
                    'consented_at' => now(),
                    'signature_path' => $path,
                    'terms_hash' => $hash,
                    'consent_snapshot' => $snapshot,
                ]);

                $this->log($locked, $actor, 'Consentimiento firmado por el cliente (términos '.$hash.').');

                return $locked->fresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        } finally {
            if (! $wroteFile) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /** Términos EXACTOS de la renovación (congelados al solicitar). */
    public function consentSnapshot(CobrosCreditRenewalRequest $renewal): array
    {
        $partial = (string) ($renewal->modality ?? '') === 'partial_payment'
            ? ['modalidad_abono' => 'partial_payment', 'recibo_abono_id' => (int) $renewal->partial_payment_receipt_id]
            : [];
        return $this->canonicalize([
            ...$partial,
            'renewal_id' => $renewal->id,
            'credit_id' => $renewal->credit_id,
            'company_id' => $renewal->company_id,
            'deuda_anterior' => [
                'total' => (string) $renewal->previous_pending,
                'capital' => (string) $renewal->previous_principal,
                'interes' => (string) $renewal->previous_interest,
            ],
            'capital_nuevo' => (string) $renewal->new_capital,
            'interes_nuevo' => (string) $renewal->new_interest,
            'total_consolidado' => (string) $renewal->consolidated_total,
            'desembolso' => (string) $renewal->disbursement,
            'calendario' => [
                'cuotas' => $renewal->installment_count,
                'frecuencia' => $renewal->frequency,
                'primera_cuota' => (string) $renewal->first_due_date,
                'filas' => $renewal->schedule_snapshot,
            ],
            'texto_consentimiento' => self::CONSENT_TEXT,
        ]);
    }

    public function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function decodeSignature(?string $signature): string
    {
        $raw = trim((string) $signature);
        if ($raw === '') {
            throw ValidationException::withMessages([
                'signature' => 'El cliente debe firmar para dar su consentimiento.',
            ]);
        }

        if (! str_starts_with($raw, 'data:image/png;base64,')) {
            throw ValidationException::withMessages([
                'signature' => 'La firma no es válida. Use "Limpiar firma" y vuelva a firmar.',
            ]);
        }

        $png = base64_decode(substr($raw, 22), true);
        if ($png === false || strlen($png) < 128 || ! str_starts_with($png, "\x89PNG")) {
            throw ValidationException::withMessages([
                'signature' => 'La firma no es válida. Use "Limpiar firma" y vuelva a firmar.',
            ]);
        }

        return $png;
    }

    private function log(CobrosCreditRenewalRequest $renewal, User $actor, string $notes): void
    {
        $credit = $renewal->credit;
        $requestId = $credit?->credit_request_id;
        if ($requestId === null) {
            return;
        }

        CobrosCreditRequestLog::create([
            'company_id' => $renewal->company_id,
            'credit_request_id' => $requestId,
            'actor_user_id' => $actor->id,
            'from_status' => $credit?->status,
            'to_status' => $credit?->status,
            'notes' => substr('[renovación #'.$renewal->id.'] '.$notes, 0, 255),
        ]);
    }

    /** Orden determinista de claves (recursivo) para un hash estable. */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_numeric($value) && ! is_string($value) ? (string) $value : $value;
        }

        $isList = array_is_list($value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->canonicalize($item);
        }
        if (! $isList) {
            ksort($normalized);
        }

        return $normalized;
    }
}
