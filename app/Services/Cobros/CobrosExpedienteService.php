<?php

namespace App\Services\Cobros;

use App\Models\CobrosCreditExpediente;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosCreditRequestLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 10A - persistencia del expediente PERMANENTE DEL CLIENTE
 * (company_id + customer_id): trabajo/ingresos, señas, referencias,
 * fiador opcional y documentos adicionales. Existe con o sin solicitud;
 * rechazar/cancelar una solicitud no lo toca. No altera Customer, la
 * solicitud, el crédito, la cuenta ni los pagos, y no aplica scoring.
 */
class CobrosExpedienteService
{
    /** Tipos de documento adicional (1 archivo por tipo, reemplazo = borrar el anterior). */
    public const DOC_TYPES = ['income', 'address', 'other'];

    private const GUARANTOR_FIELDS = [
        'guarantor_name',
        'guarantor_identification',
        'guarantor_phone',
        'guarantor_address',
        'guarantor_occupation',
        'guarantor_income',
    ];

    /**
     * Guarda (upsert) el expediente del CLIENTE.
     *
     * Semántica de edición: solo las claves presentes en $data se actualizan;
     * una clave con cadena vacía borra el valor; $files reemplaza por tipo.
     * $fromRequest solo enlaza el histórico (vínculo con la solicitud que lo
     * originó); los datos contractuales del préstamo viven en el crédito y
     * jamás se sobrescriben desde aquí.
     *
     * @param array<string, mixed> $data datos ya validados (sin archivos)
     * @param array<string, UploadedFile> $files archivos por tipo (income|address|other)
     */
    public function save(Company $company, Customer $customer, User $actor, array $data, array $files, ?CobrosCreditRequest $fromRequest = null): CobrosCreditExpediente
    {
        return DB::transaction(function () use ($company, $customer, $actor, $data, $files, $fromRequest) {
            $expediente = CobrosCreditExpediente::query()->forCompany((int) $company->id)
                ->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($expediente === null) {
                $expediente = new CobrosCreditExpediente([
                    'company_id' => (int) $company->id,
                    'customer_id' => (int) $customer->id,
                    'collector_user_id' => (int) $actor->id,
                ]);
            }

            $payload = array_intersect_key($data, array_flip([
                'occupation',
                'workplace',
                'income_amount',
                'income_frequency',
                'activity_time',
                'address_reference',
                ...self::GUARANTOR_FIELDS,
            ]));

            if (array_key_exists('has_guarantor', $data)) {
                $payload['has_guarantor'] = (bool) $data['has_guarantor'];
                if (! $payload['has_guarantor']) {
                    // Fiador NO: se oculta en la UI y no se conserva PII del fiador.
                    foreach (self::GUARANTOR_FIELDS as $field) {
                        $payload[$field] = null;
                    }
                }
            }

            // Vínculo histórico: solo la primera vez que se captura desde una solicitud.
            if ($fromRequest !== null && $expediente->credit_request_id === null) {
                $payload['credit_request_id'] = (int) $fromRequest->id;
            }
            $payload['last_edited_by'] = (int) $actor->id;
            $payload['last_edited_at'] = now();

            $expediente->fill($payload);
            if ($expediente->isDirty() || $expediente->exists === false) {
                $expediente->save();
            }

            // Referencias: solo se reemplazan si viene el grupo completo (hasta 2).
            if (array_key_exists('references', $data) && is_array($data['references'])) {
                $expediente->references()->delete();
                foreach ($data['references'] as $reference) {
                    $expediente->references()->create([
                        'company_id' => (int) $company->id,
                        'name' => $reference['name'],
                        'phone' => $reference['phone'],
                        'relationship' => $reference['relationship'],
                    ]);
                }
            }

            // Documentos adicionales: privados en disco local, 1 por tipo.
            foreach ($files as $type => $file) {
                $existing = $expediente->documents()->where('doc_type', $type)->first();
                if ($existing !== null) {
                    Storage::disk('local')->delete($existing->path);
                    $existing->delete();
                }
                $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');
                $extension = in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) ? $extension : 'pdf';
                $path = $file->storeAs(
                    'cobros/credit_expedientes/'.$company->id.'/customer_'.$customer->id,
                    $type.'.'.$extension,
                    'local'
                );
                $expediente->documents()->create([
                    'company_id' => (int) $company->id,
                    'doc_type' => $type,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by' => (int) $actor->id,
                ]);
            }

            // Auditoría: bitácora de solicitud solo cuando existe el vínculo.
            if ($fromRequest !== null) {
                CobrosCreditRequestLog::create([
                    'company_id' => (int) $company->id,
                    'credit_request_id' => (int) $fromRequest->id,
                    'actor_user_id' => (int) $actor->id,
                    'from_status' => $fromRequest->status,
                    'to_status' => $fromRequest->status,
                    'notes' => 'Expediente 10A guardado.',
                ]);
            }

            return $expediente->load(['references', 'documents']);
        });
    }
}
