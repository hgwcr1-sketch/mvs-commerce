<?php

namespace App\Services\Cobros;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditAcceptance;
use App\Models\CobrosCreditRequestLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 9A.2 — entrega del préstamo con firma del cliente.
 *
 * La firma es PRECONDICIÓN de la entrega: sin ella no hay aceptación ni
 * desembolso. La aceptación guarda las condiciones EXACTAS aceptadas
 * (snapshot + SHA-256) y la firma en almacenamiento PRIVADO; una vez
 * confirmada es inmutable. El desembolso reutiliza CobrosCreditService
 * (transaccional e idempotente): nunca duplica CxC, cuotas ni asignación.
 */
class CobrosCreditDeliveryService
{
    /** Texto de aceptación mostrado al cliente (idéntico en pantalla y PDF). */
    public const ACCEPTANCE_TEXT = 'He revisado las condiciones de este préstamo y confirmo '
        .'que recibí el monto indicado. Acepto el monto total, '
        .'las fechas de pago y las condiciones mostradas.';

    public function __construct(private CobrosCreditService $credits) {}

    /** Confirmar entrega: firma válida → aceptación → desembolso 9A. Idempotente. */
    public function confirm(Company $company, CobrosCredit $credit, User $actor, ?string $signature): CobrosCreditAcceptance
    {
        // Reintento rápido: ya aceptado → devuelve la evidencia sin tocar nada.
        $already = CobrosCreditAcceptance::forCompany($company->id)->where('credit_id', $credit->id)->first();
        if ($already !== null) {
            return $already;
        }

        $png = $this->decodeSignature($signature);

        // Archivo privado ANTES de la transacción: si algo falla, cleanup (sin huérfanos).
        $path = 'cobros/credit-acceptance/'.$credit->id.'/'.Str::uuid()->toString().'.png';
        Storage::disk('local')->put($path, $png);
        $wroteFile = true;

        try {
            $acceptance = DB::transaction(function () use ($company, $credit, $actor, $path, &$wroteFile): CobrosCreditAcceptance {
                $locked = CobrosCredit::forCompany($company->id)->whereKey($credit->id)->lockForUpdate()->first();
                if ($locked === null) {
                    abort(404);
                }

                // Doble chequeo bajo lock: otra petición pudo confirmar primero.
                $existing = CobrosCreditAcceptance::forCompany($company->id)->where('credit_id', $locked->id)->first();
                if ($existing !== null) {
                    $wroteFile = false;

                    return $existing;
                }

                if ($locked->status !== CobrosCredit::STATUS_APPROVED) {
                    throw ValidationException::withMessages([
                        'status' => 'Este préstamo ya no puede entregarse.',
                    ]);
                }

                $snapshot = $this->acceptanceSnapshot($company, $locked);
                $hash = $this->hashSnapshot($snapshot);

                $acceptance = CobrosCreditAcceptance::create([
                    'company_id' => $company->id,
                    'credit_id' => $locked->id,
                    'customer_id' => $locked->customer_id,
                    'delivered_by' => $actor->id,
                    'collector_user_id' => $locked->collector_user_id,
                    'cobros_route_id' => $locked->cobros_route_id,
                    'accepted_at' => now(),
                    'signature_path' => $path,
                    'reference' => 'AC-'.strtoupper(substr($hash,0,8)),
                    'terms_snapshot' => $snapshot,
                    'terms_hash' => $hash,
                ]);

                // CxC + calendario + cartera + Buró: servicio 9A existente, todo o nada.
                $disbursed = $this->credits->disburse($company, $locked, $actor);

                // El cliente se resuelve/crea en el desembolso: se enlaza la evidencia.
                $acceptance->update(['customer_id' => $disbursed->customer_id]);

                $this->log($locked, $actor, 'Aceptación firmada por el cliente (referencia '.$acceptance->reference.').');
                $this->log($locked, $actor, 'Entrega del préstamo confirmada.');
                $this->log($locked, $actor, 'Comprobante del préstamo disponible.');

                return $acceptance->fresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        if (! $wroteFile) {
            Storage::disk('local')->delete($path); // intento descartado: no deja archivos
        }

        return $acceptance;
    }

    /** Condiciones EXACTAS aceptadas: término del crédito + datos operativos del momento. */
    public function acceptanceSnapshot(Company $company, CobrosCredit $credit): array
    {
        $request = $credit->request;
        $customer = $credit->customer;

        return $this->canonicalize([
            'credit_id' => $credit->id,
            'credit_request_id' => $credit->credit_request_id,
            'company' => [
                'id' => $company->id,
                'trade_name' => $company->trade_name,
                'currency' => $company->currency,
            ],
            'terms' => $credit->terms_snapshot,
            'customer' => [
                'name' => (string) ($customer?->name ?? $request?->name),
                'identification_type' => (string) ($customer?->identification_type ?? $request?->identification_type),
                'identification' => (string) ($customer?->identification ?? $request?->identification),
            ],
            'route' => ['id' => $credit->cobros_route_id, 'name' => (string) $credit->route?->name],
            'collector' => ['id' => $credit->collector_user_id, 'name' => (string) $credit->collector?->name],
            'accepted_at' => now()->toDateTimeString(),
        ]);
    }

    /** SHA-256 estable del snapshot canónico (comprobable en cualquier momento). */
    public function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** PNG válido y con contenido; un lienzo vacío o basura no es firma. */
    private function decodeSignature(?string $signature): string
    {
        $raw = trim((string) $signature);
        if ($raw === '') {
            throw ValidationException::withMessages([
                'signature' => 'El cliente debe firmar para confirmar la entrega.',
            ]);
        }

        $raw = preg_replace('/^data:image\/png;base64,/i', '', $raw) ?? '';
        $binary = base64_decode($raw, true);
        $info = $binary === false ? false : @getimagesizefromstring($binary);

        if ($binary === false || $info === false || ($info[2] ?? 0) !== IMAGETYPE_PNG || strlen($binary) < 400) {
            throw ValidationException::withMessages([
                'signature' => 'La firma no es válida. Use "Limpiar firma" y vuelva a firmar.',
            ]);
        }

        return $binary;
    }

    /** Bitácora append-only existente de la solicitud (sin borrar historial). */
    private function log(CobrosCredit $credit, User $actor, string $notes): void
    {
        CobrosCreditRequestLog::create([
            'company_id' => $credit->company_id,
            'credit_request_id' => $credit->credit_request_id,
            'actor_user_id' => $actor->id,
            'from_status' => $credit->request?->status,
            'to_status' => $credit->request?->status ?? 'approved',
            'notes' => substr($notes, 0, 255),
        ]);
    }

    /** Orden determinista de claves (recursivo) para un hash estable. */
    private function canonicalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonicalize($value);
            }
        }
        ksort($data);

        return $data;
    }
}
