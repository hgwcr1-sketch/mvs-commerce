<?php

namespace App\Services\Cobros;

use App\Models\CobrosBureauIdentity;
use App\Models\CobrosBureauIdentityDocument;
use App\Models\CobrosBureauEvent;
use App\Models\Company;
use App\Models\Country;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosCollectorAuthorityPolicy;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 7B — Fundación Buró (sin scoring): identidad global reutilizable.
 * Clave: country_code + identification_type + normalized_identification.
 * Sin nombre como identidad; documentos privados por origen; eventos append-only.
 */
class CobrosBureauService
{
    /** Tipo canónico -> alias aceptados (internacional-ready, sin regex CR). */
    private const TYPE_ALIASES = [
        'cedula' => 'cedula',
        '01' => 'cedula',
        'dimex' => 'dimex',
        '03' => 'dimex',
        'nit' => 'nit',
        '04' => 'nit',
        'pasaporte' => 'pasaporte',
        'passport' => 'pasaporte',
        'otro' => 'otro',
        'other' => 'otro',
        '05' => 'otro',
    ];

    public function canonicalType(?string $type): string
    {
        $key = strtolower(trim((string) $type));

        return self::TYPE_ALIASES[$key] ?? 'otro';
    }

    public function normalizeIdentification(string $identification): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $identification));
    }

    /** País por company (iso2) -> país por defecto configurado -> 'XX' (sin hardcodear CR). */
    public function countryCodeFor(Company $company): string
    {
        $iso2 = $company->country?->iso2
            ?? Country::query()->where('is_default', true)->value('iso2');

        return strtoupper((string) ($iso2 ?: 'XX'));
    }

    /** Consulta exacta para el flujo legítimo de Nueva solicitud. NO crea identidad. */
    public function findExact(Company $company, ?string $type, string $identification): ?CobrosBureauIdentity
    {
        $normalized = $this->normalizeIdentification($identification);

        if ($normalized === '') {
            return null;
        }

        return CobrosBureauIdentity::query()
            ->where('country_code', $this->countryCodeFor($company))
            ->where('identification_type', $this->canonicalType($type))
            ->where('normalized_identification', $normalized)
            ->first();
    }

    /**
     * Crea o enlaza identidad global dentro de la transacción de la solicitud.
     * Concurrencia: índice único + reintento ante duplicado (dos solicitudes
     * simultáneas terminan con UNA identidad).
     *
     * @return array{identity: CobrosBureauIdentity, created: bool}
     */
    public function resolveIdentity(
        Company $company,
        ?string $type,
        string $identification,
        User $actor,
        ?int $creditRequestId,
        string $purpose
    ): array {
        $countryCode = $this->countryCodeFor($company);
        $canonical = $this->canonicalType($type);
        $normalized = $this->normalizeIdentification($identification);

        $identity = CobrosBureauIdentity::query()
            ->where('country_code', $countryCode)
            ->where('identification_type', $canonical)
            ->where('normalized_identification', $normalized)
            ->lockForUpdate()
            ->first();

        $created = false;

        if ($identity === null) {
            try {
                $identity = CobrosBureauIdentity::create([
                    'country_code' => $countryCode,
                    'identification_type' => $canonical,
                    'normalized_identification' => $normalized,
                ]);
                $created = true;
            } catch (QueryException) {
                // Carrera con otra inserción: reutiliza la identidad ya creada.
                $identity = CobrosBureauIdentity::query()
                    ->where('country_code', $countryCode)
                    ->where('identification_type', $canonical)
                    ->where('normalized_identification', $normalized)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $this->record(
                CobrosBureauEvent::TYPE_IDENTITY_CREATED,
                $identity,
                $company,
                $actor,
                $purpose,
                $creditRequestId
            );
        }

        if ($creditRequestId !== null) {
            $this->record(
                CobrosBureauEvent::TYPE_IDENTITY_LINKED,
                $identity,
                $company,
                $actor,
                $purpose,
                $creditRequestId
            );
        }

        return ['identity' => $identity, 'created' => $created];
    }

    /** Evidencia referenciando el MISMO archivo privado de 7A (sin copia física). */
    public function attachDocument(
        CobrosBureauIdentity $identity,
        CobrosCreditRequest $request,
        User $actor,
        string $documentType,
        string $privatePath
    ): CobrosBureauIdentityDocument {
        $document = CobrosBureauIdentityDocument::updateOrCreate(
            [
                'source_credit_request_id' => $request->id,
                'document_type' => $documentType,
            ],
            [
                'bureau_identity_id' => $identity->id,
                'source_company_id' => $request->company_id,
                'source_collector_user_id' => $actor->id,
                'private_path' => $privatePath,
                'uploaded_at' => $request->submitted_at ?? now(),
            ]
        );

        $this->record(
            CobrosBureauEvent::TYPE_DOCUMENT_ATTACHED,
            $identity,
            null,
            $actor,
            'solicitud_credito',
            $request->id,
            ['document_type' => $documentType, 'source_company_id' => $request->company_id],
            $request->company_id
        );

        return $document;
    }

    /** Acceso a identidad global: SIEMPRE auditable con company/actor/purpose. */
    public function recordAccess(
        CobrosBureauIdentity $identity,
        Company $company,
        User $actor,
        string $purpose,
        ?int $creditRequestId = null
    ): void {
        $this->record(
            CobrosBureauEvent::TYPE_IDENTITY_ACCESSED,
            $identity,
            $company,
            $actor,
            $purpose,
            $creditRequestId
        );
    }

    /**
     * 9C.3 — La MISMA política de autoridad que decide quién aprueba el
     * préstamo decide quién CONSULTA la información del solicitante.
     *  - owner_required: solo oficina/prestamista y SOLO dentro de
     *    solicitudes/expedientes legítimos existentes (nunca búsqueda
     *    arbitraria por cédula).
     *  - collector_authorized: solo el cobrador dueño de esa solicitud con
     *    route/company válidas; otro cobrador/ruta → 403; otro tenant → 404.
     * La política se resuelve server-side (nunca desde el frontend).
     */
    public function guardConsult(Company $company, User $actor, ?string $type, string $identification): void
    {
        $normalized = $this->normalizeIdentification($identification);
        $request = CobrosCreditRequest::forCompany($company->id)
            ->where(function ($query) use ($identification, $normalized) {
                $query->where('identification', $identification)
                    ->orWhere('identification', $normalized);
            })
            ->when($type !== null && $type !== '', function ($query) use ($type) {
                $query->where('identification_type', $type);
            })
            ->orderByDesc('id')
            ->first();

        if ($actor->hasPermission('cobros.portal_dueno', $company)) {
            if ($request === null) {
                abort(403, 'La consulta solo está disponible dentro de una solicitud existente.');
            }

            return;
        }

        if (! CobrosCollectorAuthorityPolicy::requiresOwner((int) $company->id, (int) $actor->id)) {
            if ($request !== null) {
                if ((int) $request->collector_user_id !== (int) $actor->id) {
                    abort(403, 'La solicitud pertenece a otro cobrador.');
                }
                $activeRoute = (int) session('active_cobros_route_id');
                if ($activeRoute > 0 && (int) $request->cobros_route_id !== $activeRoute) {
                    abort(403, 'La solicitud pertenece a otra ruta.');
                }
            }

            return;
        }

        abort(403, 'Este cobrador requiere la autorización del dueño para consultar información.');
    }

    /** Consulta autorizada → hallazgo de existencia + auditoría existente (purpose/versión intactos). */
    public function consultFor(Company $company, User $actor, ?string $type, string $identification): bool
    {
        $this->guardConsult($company, $actor, $type, $identification);

        $identity = $this->findExact($company, $type, $identification);
        if ($identity !== null) {
            $this->recordAccess($identity, $company, $actor, 'evaluacion_solicitud_credito::autorizacion_v1');
        }

        return $identity !== null;
    }

    /** Documento privado: solo la company de ORIGEN obtiene la ruta; nadie más. */
    public function accessDocumentPath(int $documentId, Company $company): ?string
    {
        $document = CobrosBureauIdentityDocument::query()->find($documentId);

        if ($document === null || (int) $document->source_company_id !== (int) $company->id) {
            return null;
        }

        return $document->private_path;
    }

    /** Escritura de bitácora (append-only). Los servicios normales no borran historial. */
    public function record(
        string $eventType,
        CobrosBureauIdentity $identity,
        ?Company $company,
        User $actor,
        string $purpose,
        ?int $creditRequestId = null,
        ?array $metadata = null,
        ?int $sourceCompanyId = null
    ): void {
        CobrosBureauEvent::create([
            'bureau_identity_id' => $identity->id,
            'event_type' => $eventType,
            'source_company_id' => $sourceCompanyId ?? $company?->id,
            'source_credit_request_id' => $creditRequestId,
            'actor_user_id' => $actor->id,
            'purpose' => $purpose,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
