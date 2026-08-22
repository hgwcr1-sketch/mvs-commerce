<?php

namespace App\Http\Requests;

use App\Services\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWhatsAppSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phoneNumbers = app(PhoneNumberService::class);

        $this->merge([
            'default_phone_country_code' => $phoneNumbers->normalizeCountryCode($this->input('default_phone_country_code')),
            'whatsapp_phone_country_code' => $phoneNumbers->normalizeCountryCode($this->input('whatsapp_phone_country_code')),
            'whatsapp_phone' => $phoneNumbers->normalizePhone($this->input('whatsapp_phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'whatsapp_enabled' => ['required', 'boolean'],
            'default_phone_country_code' => ['nullable', 'regex:/^\+[1-9]\d{0,3}$/'],
            'whatsapp_phone_country_code' => ['nullable', 'required_with:whatsapp_phone', 'regex:/^\+[1-9]\d{0,3}$/'],
            'whatsapp_phone' => ['nullable', 'required_if:whatsapp_enabled,1', 'regex:/^\d{4,15}$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $normalized = app(PhoneNumberService::class)->forWhatsApp(
                $this->input('whatsapp_phone_country_code'),
                $this->input('whatsapp_phone'),
            );

            if ($normalized !== null && strlen($normalized) > 15) {
                $validator->errors()->add('whatsapp_phone', 'El número internacional no puede superar 15 dígitos.');
            }
        }];
    }
}
