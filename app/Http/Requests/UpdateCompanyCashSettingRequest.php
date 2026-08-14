<?php

namespace App\Http\Requests;

use App\Models\CompanyCashSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyCashSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('active_company_id');
    }

    protected function prepareForValidation(): void
    {
        $acceptsUsd = $this->boolean('accepts_usd');
        $emails = collect($this->input('closure_email_recipients', []))
            ->filter(fn ($email) => is_string($email))
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->merge([
            'allow_multiple_registers' => $this->boolean('allow_multiple_registers'),
            'require_open_session' => $this->boolean('require_open_session'),
            'blind_closing' => $this->boolean('blind_closing'),
            'accepts_usd' => $acceptsUsd,
            'require_difference_authorization' => $this->boolean('require_difference_authorization'),
            'auto_print_closure' => $this->boolean('auto_print_closure'),
            'usd_exchange_rate_min' => $acceptsUsd ? $this->input('usd_exchange_rate_min') : null,
            'usd_exchange_rate_max' => $acceptsUsd ? $this->input('usd_exchange_rate_max') : null,
            'usd_change_policy' => $acceptsUsd
                ? $this->input('usd_change_policy', CompanyCashSetting::USD_CHANGE_CRC_ONLY)
                : CompanyCashSetting::USD_CHANGE_CRC_ONLY,
            'closure_email_recipients' => $emails === [] ? null : $emails,
        ]);
    }

    public function rules(): array
    {
        return [
            'allow_multiple_registers' => ['boolean'],
            'require_open_session' => ['boolean'],
            'session_mode' => ['required', Rule::in([
                CompanyCashSetting::SESSION_MODE_INDIVIDUAL,
                CompanyCashSetting::SESSION_MODE_SHARED,
            ])],
            'blind_closing' => ['boolean'],
            'accepts_usd' => ['boolean'],
            'usd_exchange_rate_min' => ['nullable', 'numeric', 'gt:0'],
            'usd_exchange_rate_max' => ['nullable', 'numeric', 'gt:0', 'gte:usd_exchange_rate_min'],
            'usd_change_policy' => ['required', Rule::in([
                CompanyCashSetting::USD_CHANGE_CRC_ONLY,
                CompanyCashSetting::USD_CHANGE_USD_ONLY,
                CompanyCashSetting::USD_CHANGE_EITHER,
            ])],
            'difference_tolerance' => ['required', 'numeric', 'min:0'],
            'require_difference_authorization' => ['boolean'],
            'auto_print_closure' => ['boolean'],
            'closure_email_recipients' => ['nullable', 'array', 'max:10'],
            'closure_email_recipients.*' => ['email', 'max:150', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'session_mode.required' => 'Debe seleccionar el modo de sesión.',
            'session_mode.in' => 'El modo de sesión seleccionado no es válido.',
            'usd_exchange_rate_min.numeric' => 'El tipo de cambio mínimo debe ser numérico.',
            'usd_exchange_rate_min.gt' => 'El tipo de cambio mínimo debe ser mayor que cero.',
            'usd_exchange_rate_max.numeric' => 'El tipo de cambio máximo debe ser numérico.',
            'usd_exchange_rate_max.gt' => 'El tipo de cambio máximo debe ser mayor que cero.',
            'usd_exchange_rate_max.gte' => 'El tipo de cambio máximo debe ser mayor o igual al mínimo.',
            'usd_change_policy.required' => 'Debe seleccionar la política de vuelto para dólares.',
            'usd_change_policy.in' => 'La política de vuelto para dólares no es válida.',
            'difference_tolerance.required' => 'Debe indicar la tolerancia de diferencia.',
            'difference_tolerance.numeric' => 'La tolerancia debe ser numérica.',
            'difference_tolerance.min' => 'La tolerancia no puede ser negativa.',
            'closure_email_recipients.array' => 'Los destinatarios de cierre no son válidos.',
            'closure_email_recipients.max' => 'Puede configurar como máximo 10 correos.',
            'closure_email_recipients.*.email' => 'Cada destinatario debe ser un correo válido.',
            'closure_email_recipients.*.max' => 'Cada correo puede tener como máximo 150 caracteres.',
            'closure_email_recipients.*.distinct' => 'No puede repetir destinatarios de cierre.',
        ];
    }
}
