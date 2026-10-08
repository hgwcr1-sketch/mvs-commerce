<?php

namespace App\Services\Cobros;

use App\Models\CobrosBureauEvent;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosCreditRequestLog;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 7A — alta de solicitud de crédito.
 * Transaccional: bloquea duplicado ABIERTO (misma empresa + identificación)
 * con lockForUpdate, crea la solicitud pendiente y su bitácora inicial.
 * 7B: resuelve/crea identidad Buró global y registra evidencias/eventos.
 * NUNCA crea Customer, CxC, crédito, cuotas, pagos ni asignaciones.
 */
class CobrosCreditRequestService
{
    public function __construct(private readonly CobrosBureauService $bureau)
    {
    }

    public function submit(
        Company $company,
        CobrosRoute $route,
        User $collector,
        array $data,
        string $frontPath,
        string $backPath
    ): CobrosCreditRequest {
        $request = DB::transaction(function () use ($company, $route, $collector, $data, $frontPath, $backPath): CobrosCreditRequest {
            $identification = trim((string) ($data['identification'] ?? ''));

            $open = CobrosCreditRequest::forCompany($company->id)
                ->open()
                ->where('identification', $identification)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                throw ValidationException::withMessages([
                    'identification' => 'Ya existe una solicitud abierta para esta identificación. No puede enviar otra hasta que sea resuelta.',
                ]);
            }

            // Solo el tipo "existente" queda vinculado a un Customer ya validado
            // contra la empresa activa; "nuevo" jamás crea ni duplica personas.
            $customer = null;
            if (($data['applicant_type'] ?? 'nuevo') === 'existente' && ! empty($data['customer_id'])) {
                $customer = Customer::query()
                    ->where('company_id', $company->id)
                    ->whereKey($data['customer_id'])
                    ->whereNull('deleted_at')
                    ->first();
                if ($customer === null) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'El cliente seleccionado no pertenece a esta empresa.',
                    ]);
                }
            }

            // 7B: identidad global Buró (misma clave → misma identidad, sin nombre).
            $identity = $this->bureau->resolveIdentity(
                $company,
                $data['identification_type'] ?? null,
                $identification,
                $collector,
                null,
                'solicitud_credito'
            )['identity'];

            $request = CobrosCreditRequest::create([
                'company_id' => $company->id,
                'cobros_route_id' => $route->id,
                'collector_user_id' => $collector->id,
                'customer_id' => $customer?->id,
                'bureau_identity_id' => $identity->id,
                'applicant_type' => $data['applicant_type'] ?? 'nuevo',
                'status' => CobrosCreditRequest::STATUS_PENDING,
                'identification_type' => $data['identification_type'] ?? null,
                'identification' => $identification,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'amount_requested' => $data['amount_requested'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'notes' => $data['notes'] ?? null,
                'id_front_path' => $frontPath,
                'id_back_path' => $backPath,
                'submitted_at' => now(),
            ]);

            CobrosCreditRequestLog::create([
                'company_id' => $company->id,
                'credit_request_id' => $request->id,
                'actor_user_id' => $collector->id,
                'from_status' => null,
                'to_status' => CobrosCreditRequest::STATUS_PENDING,
                'notes' => 'Solicitud enviada por el cobrador.',
            ]);

            // 7B: eventos append-only + evidencias que REFERENCIAN la misma
            // ruta privada de 7A (sin copia física; conserva origen y fecha).
            $this->bureau->record(
                CobrosBureauEvent::TYPE_IDENTITY_LINKED,
                $identity,
                $company,
                $collector,
                'solicitud_credito',
                $request->id
            );
            $this->bureau->record(
                CobrosBureauEvent::TYPE_REQUEST_SUBMITTED,
                $identity,
                $company,
                $collector,
                'solicitud_credito',
                $request->id
            );
            $this->bureau->attachDocument($identity, $request, $collector, 'front', $frontPath);
            $this->bureau->attachDocument($identity, $request, $collector, 'back', $backPath);

            return $request;
        });

        // BLOQUE 8 — campana CobrOS (Core AlertDispatcher): nueva solicitud.
        // 10) owner_required: es el pendiente accionable del dueño.
        // collector_authorized: NO existe pendiente de aprobación para el
        // dueño (nada que autorizar); solo se le informará al final si aplica.
        if (\App\Models\CobrosCollectorAuthorityPolicy::requiresOwner(
            (int) $request->company_id,
            (int) $request->collector_user_id
        )) {
            $this->dispatchBell(
                $request,
                $collector,
                'Nueva solicitud de crédito · '.$request->name.$this->routeSuffix($request),
                'nueva'
            );
        }

        return $request;
    }

    /** BLOQUE 8 — verifica una solicitud pendiente (lista para decisión Bloque 9). */
    public function verify(Company $company, CobrosCreditRequest $request, User $actor, string $motivo): CobrosCreditRequest
    {
        return $this->transition($company, $request, $actor, CobrosCreditRequest::STATUS_VERIFIED, [CobrosCreditRequest::STATUS_PENDING], $motivo, CobrosBureauEvent::TYPE_REQUEST_VERIFIED);
    }

    /** BLOQUE 8 — solicita corrección al cobrador (motivo obligatorio). */
    public function requestCorrection(Company $company, CobrosCreditRequest $request, User $actor, string $motivo): CobrosCreditRequest
    {
        return $this->transition($company, $request, $actor, CobrosCreditRequest::STATUS_CORRECTION, [CobrosCreditRequest::STATUS_PENDING], $motivo, CobrosBureauEvent::TYPE_REQUEST_CORRECTION_REQUESTED);
    }

    /** BLOQUE 8 — rechazo terminal (motivo obligatorio). NO marca la identidad Buró. */
    public function reject(Company $company, CobrosCreditRequest $request, User $actor, string $motivo, ?array $fromStatuses = null): CobrosCreditRequest
    {
        return $this->transition($company, $request, $actor, CobrosCreditRequest::STATUS_REJECTED, $fromStatuses ?? [CobrosCreditRequest::STATUS_PENDING, CobrosCreditRequest::STATUS_CORRECTION], $motivo, CobrosBureauEvent::TYPE_REQUEST_REJECTED);
    }

    /** BLOQUE 9 — solicitud VERIFICADA → APROBADA (condiciones definidas, sin desembolsar). */
    public function approve(Company $company, CobrosCreditRequest $request, User $actor, string $motivo): CobrosCreditRequest
    {
        return $this->transition($company, $request, $actor, CobrosCreditRequest::STATUS_APPROVED, [CobrosCreditRequest::STATUS_VERIFIED], $motivo, CobrosBureauEvent::TYPE_CREDIT_APPROVED);
    }

    /**
     * Transición de dueño: valida estados de origen, actualiza bajo lock,
     * escribe bitácora append-only (actor/from/to/motivo) y evento Buró.
     * NUNCA crea crédito, CxC, cuotas, saldo ni pagos.
     */
    private function transition(
        Company $company,
        CobrosCreditRequest $request,
        User $actor,
        string $toStatus,
        array $fromStatuses,
        string $motivo,
        string $bureauEvent
    ): CobrosCreditRequest {
        $result = DB::transaction(function () use ($company, $request, $actor, $toStatus, $fromStatuses, $motivo, $bureauEvent): CobrosCreditRequest {
            $locked = CobrosCreditRequest::forCompany($company->id)->whereKey($request->id)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, $fromStatuses, true)) {
                throw ValidationException::withMessages([
                    'transition' => 'La solicitud ya no está en un estado que permita esta acción.',
                ]);
            }

            $from = $locked->status;
            $locked->status = $toStatus;
            $locked->save();

            CobrosCreditRequestLog::create([
                'company_id' => $company->id,
                'credit_request_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $toStatus,
                'notes' => $motivo,
            ]);

            if ($locked->bureau_identity_id !== null) {
                $identity = $locked->bureauIdentity()->lockForUpdate()->first();
                if ($identity !== null) {
                    // Solo evento append-only a nivel solicitud: la identidad
                    // Buró jamás se marca como "mal cliente" por un rechazo.
                    $this->bureau->record($bureauEvent, $identity, $company, $actor, 'solicitud_verificacion', $locked->id);
                }
            }

            return $locked;
        });

        // BLOQUE 8 — campana al cobrador dueño de la solicitud (destinatario
        // exacto garantizado con ensureRecipientId; el dueño de la empresa
        // también queda por source_permissions del tipo).
        $text = match ($toStatus) {
            CobrosCreditRequest::STATUS_VERIFIED => 'Solicitud verificada · '.$result->name,
            CobrosCreditRequest::STATUS_CORRECTION => 'Solicitud requiere corrección · '.$result->name,
            // 10) owner_required: al dueño "autorizado" (era su pendiente);
            // collector_authorized: informativa "Nuevo préstamo realizado".
            CobrosCreditRequest::STATUS_APPROVED => (
                \App\Models\CobrosCollectorAuthorityPolicy::requiresOwner(
                    (int) $result->company_id,
                    (int) $result->collector_user_id
                ) ? 'Préstamo autorizado · ' : 'Nuevo préstamo realizado · '
            ).$result->name,
            default => 'Solicitud no autorizada · '.$result->name,
        };
        $link = $toStatus === CobrosCreditRequest::STATUS_CORRECTION
            ? route('cobros.collector.credit-requests.create', ['resume' => $result->id])
            : ($toStatus === CobrosCreditRequest::STATUS_APPROVED
                ? route('cobros.owner.solicitudes.show', $result->id)
                : route('cobros.collector.credit-requests.index'));
        $this->dispatchBell(
            $result,
            $actor,
            $text,
            'transicion-'.$toStatus.'-'.now()->format('YmdHisu'),
            $result->collector_user_id,
            $link
        );

        return $result;
    }

    /**
     * BLOQUE 8 — reenvío de solicitud corregida por el cobrador.
     * Reutiliza la MISMA fila (historial y evidencias físicas previas se
     * conservan), valida que siga en corrección y vuelve a pendiente.
     */
    public function resubmit(
        Company $company,
        CobrosRoute $route,
        User $collector,
        CobrosCreditRequest $request,
        array $data,
        ?string $frontPath,
        ?string $backPath
    ): CobrosCreditRequest {
        $request = DB::transaction(function () use ($company, $route, $collector, $request, $data, $frontPath, $backPath): CobrosCreditRequest {
            $locked = CobrosCreditRequest::forCompany($company->id)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->collector_user_id !== $collector->id
                || $locked->cobros_route_id !== $route->id
                || $locked->status !== CobrosCreditRequest::STATUS_CORRECTION) {
                throw ValidationException::withMessages([
                    'resume_id' => 'Esta solicitud no está disponible para corrección.',
                ]);
            }

            $customer = null;
            if (($data['applicant_type'] ?? 'nuevo') === 'existente' && ! empty($data['customer_id'])) {
                $customer = Customer::query()
                    ->where('company_id', $company->id)
                    ->whereKey($data['customer_id'])
                    ->whereNull('deleted_at')
                    ->first();
                if ($customer === null) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'El cliente seleccionado no pertenece a esta empresa.',
                    ]);
                }
            }

            $locked->update([
                'customer_id' => $customer?->id,
                'applicant_type' => $data['applicant_type'] ?? 'nuevo',
                'identification_type' => $data['identification_type'] ?? null,
                'identification' => trim((string) ($data['identification'] ?? '')),
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'amount_requested' => $data['amount_requested'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'notes' => $data['notes'] ?? null,
                'id_front_path' => $frontPath ?? $locked->id_front_path,
                'id_back_path' => $backPath ?? $locked->id_back_path,
                'status' => CobrosCreditRequest::STATUS_PENDING,
                'submitted_at' => now(),
            ]);

            CobrosCreditRequestLog::create([
                'company_id' => $company->id,
                'credit_request_id' => $locked->id,
                'actor_user_id' => $collector->id,
                'from_status' => CobrosCreditRequest::STATUS_CORRECTION,
                'to_status' => CobrosCreditRequest::STATUS_PENDING,
                'notes' => 'Corrección reenviada por el cobrador.',
            ]);

            return $locked->refresh();
        });

        // BLOQUE 8 — campana CobrOS: solicitud corregida/re-enviada (alerta nueva).
        $this->dispatchBell(
            $request,
            $collector,
            'Solicitud corregida · '.$request->name.$this->routeSuffix($request),
            'reenvio-'.now()->format('YmdHisu')
        );

        return $request;
    }

    /** Sufijo " · {ruta}" para textos de campana (dirección no era la ruta). */
    private function routeSuffix(CobrosCreditRequest $request): string
    {
        $ruta = $request->route()->value('name');

        return $ruta ? ' · '.$ruta : '';
    }

    /**
     * BLOQUE 8 — notificación Core (AlertDispatcher + alert_recipients):
     * solo canal CobrOS de la company, resuelto por permisos (sin destinatarios
     * ajenos ni ruido Commerce). Nunca interrumpe el envío si falla.
     * $ensureRecipientId: destinatario EXACTO (ej. cobrador dueño de la
     * solicitud) que el resolver masivo no cubre por permisos.
     * $link: destino del click (detalle del dueño / corregir del cobrador).
     */
    private function dispatchBell(
        CobrosCreditRequest $request,
        User $actor,
        string $text,
        string $dedupeKey,
        ?int $ensureRecipientId = null,
        ?string $link = null
    ): void {
        try {
            $alert = app(\App\Services\Notifications\AlertDispatcher::class)->dispatch(
                \App\Services\Notifications\AlertTypeRegistry::TYPE_COBROS_CREDIT_REQUEST,
                [
                    'company_id' => $request->company_id,
                    'actor_id' => $actor->id,
                    'entity_type' => 'cobros_credit_request',
                    'entity_id' => $request->id,
                    'link' => $link ?? route('cobros.owner.solicitudes.show', $request->id),
                    'dedupe_key' => $dedupeKey,
                    'metadata' => ['bell_text' => $text, 'credit_request_id' => $request->id],
                ]
            );

            if ($alert !== null && $ensureRecipientId !== null
                && ! $alert->recipients()->where('user_id', $ensureRecipientId)->exists()) {
                $alert->recipients()->create(['user_id' => $ensureRecipientId]);
                \App\Models\User::find($ensureRecipientId)
                    ?->notify(new \App\Notifications\MvsAlertNotification($alert));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Campana solicitud de crédito no dispatchada', [
                'credit_request_id' => $request->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
