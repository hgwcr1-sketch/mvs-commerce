<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * BLOQUE 7A — formulario de nueva solicitud de crédito (revisión incluida).
 * - Tipo de solicitante: nuevo (captura manual + lookup de nombre) o
 *   existente (customer_id validado contra la empresa activa).
 * - Evidencias FREnte/REVERSO de identidad: obligatorias al REVISAR;
 *   al ENVIAR se acepta el token de revisión que las respalda.
 * - Identificación como string abierto (internacional-ready): sin regex CR.
 * - Monto DECIMAL(19,4) exacto con regex + bccomp (sin floats).
 */
class StoreCobrosCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isStore = $this->routeIs('cobros.collector.credit-requests.store');
        $companyId = (int) session('active_company_id');

        $rules = [
            'resume_id' => ['nullable', 'integer'],
            'applicant_type' => ['required', 'string', 'in:nuevo,existente'],
            'customer_id' => ['nullable', 'integer', function ($attribute, $value, $fail) use ($companyId) {
                if ($value === null || $value === '') {
                    return;
                }
                $belongs = Customer::query()
                    ->where('company_id', $companyId)
                    ->whereKey($value)
                    ->whereNull('deleted_at')
                    ->exists();
                if (! $belongs) {
                    $fail('El cliente seleccionado no pertenece a esta empresa.');
                }
            }],
            'identification_type' => ['nullable', 'string', 'in:cedula,dimex,nit,pasaporte,passport,other'],
            'identification' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:250'],
            'amount_requested' => [
                'required',
                'string',
                'max:32',
                'regex:/^\d{1,13}(?:\.\d{1,4})?$/D',
                function ($attribute, $value, $fail) {
                    if (! is_string($value) || ! preg_match('/^\d{1,13}(?:\.\d{1,4})?$/D', $value)) {
                        return;
                    }
                    if (bccomp($value, '0', 4) <= 0) {
                        $fail('El monto solicitado debe ser mayor que cero.');
                    }
                },
            ],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];

        $rules['customer_id'][] = 'required_if:applicant_type,existente';

        if ($isStore) {
            // Enviada: las fotos fueron validadas y temporalizadas en revisión.
            $rules['review_token'] = ['required', 'string', 'max:64'];
        } else {
            // Archivo con error de subida de PHP (p. ej. > upload_max_filesize):
            // el validador corta con la regla 'uploaded' ANTES de estas reglas;
            // el mensaje claro para ese caso vive en messages() ('*.uploaded').
            // BLOQUE 8 — corrección (resume_id): la foto NO es obligatoria
            // (se conserva la existente); si SÍ se sube, valida igual de estricto.
            $hasResume = ! empty($this->input('resume_id'));
            // 4) CLIENTE EXISTENTE: si su expediente YA tiene la foto de este
            //    lado en ESTA empresa, no se exige volver a subirla (si falta,
            //    solo se pide lo que falta).
            $hasFront = $hasResume;
            $hasBack = $hasResume;
            if (! $hasResume
                && $this->input('applicant_type') === 'existente'
                && $this->filled('customer_id')) {
                $prior = \App\Models\CobrosCreditRequest::query()
                    ->forCompany((int) session('active_company_id', 0))
                    ->where('customer_id', (int) $this->input('customer_id'))
                    ->orderByDesc('id')
                    ->get(['id_front_path', 'id_back_path'])
                    ->first();
                $hasFront = $prior !== null && $prior->id_front_path !== null;
                $hasBack = $prior !== null && $prior->id_back_path !== null;
            }
            $optional = ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'];
            $required = ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'];
            $rules['id_front'] = $hasFront ? $optional : $required;
            $rules['id_back'] = $hasBack ? $optional : $required;
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'applicant_type.required' => 'Indique si el solicitante es nuevo o existente.',
            'applicant_type.in' => 'Tipo de solicitante no válido.',
            'customer_id.required_if' => 'Debe seleccionar el cliente existente.',
            'identification.required' => 'La identificación es obligatoria.',
            'identification.max' => 'La identificación no puede superar los 50 caracteres.',
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',
            'amount_requested.required' => 'El monto solicitado es obligatorio.',
            'amount_requested.regex' => 'El monto solicitado no es válido (máximo 4 decimales).',
            'latitude.between' => 'La latitud no es válida.',
            'longitude.between' => 'La longitud no es válida.',
            'id_front.required' => 'La foto del frente de la identificación es obligatoria.',
            'id_back.required' => 'La foto del reverso de la identificación es obligatoria.',
            'id_front.image' => 'El frente debe ser una imagen.',
            'id_back.image' => 'El reverso debe ser una imagen.',
            'id_front.mimes' => 'Formato de imagen no permitido (jpeg, jpg, png o webp).',
            'id_back.mimes' => 'Formato de imagen no permitido (jpeg, jpg, png o webp).',
            'id_front.max' => 'La imagen del frente no puede superar 5 MB.',
            'id_back.max' => 'La imagen del reverso no puede superar 5 MB.',
            // Error de subida de PHP (UPLOAD_ERR_INI_SIZE u otro): decir el límite
            // real en lugar de "debe ser una imagen".
            'id_front.uploaded' => 'No se pudo subir la imagen: supera el límite de subida del servidor o falló la transferencia (máximo permitido: 5 MB). Reduzca el tamaño o aumente upload_max_filesize.',
            'id_back.uploaded' => 'No se pudo subir la imagen: supera el límite de subida del servidor o falló la transferencia (máximo permitido: 5 MB). Reduzca el tamaño o aumente upload_max_filesize.',
            'review_token.required' => 'Debe revisar la solicitud antes de enviarla.',
        ];
    }
}
