<?php

namespace App\Http\Requests;

use App\Models\Company;

/**
 * Fase 6B — alta de cliente desde CobrOS (dueño/administrador o cartera del cobrador).
 *
 * Hereda de StoreCustomerRequest la normalización de teléfonos
 * (prepareForValidation), la unicidad por empresa de identification y
 * customer_code, y los mensajes de validación en español.
 *
 * A diferencia de Commerce, el formulario CobrOS solo recibe la ficha
 * operativa: no envía crédito, nivel de precio, portal, geolocalización ni
 * datos fiscales. Esos campos siguen administrándose desde Commerce y R01
 * (routeos.credito.administrar) queda intacto: CobrOS nunca escribe
 * credit_limit / credit_days / price_level.
 */
class StoreCobrosCustomerRequest extends StoreCustomerRequest
{
    /**
     * Únicos campos que este formulario admite; el resto de reglas del
     * formulario de Commerce se descartan deliberadamente.
     */
    public const FIELDS = [
        'customer_type',
        'identification_type',
        'identification',
        'name',
        'commercial_name',
        'phone_country_code',
        'phone',
        'mobile',
        'email',
        'address',
        'notes',
        'latitude',
        'longitude',
    ];

    public function rules(): array
    {
        $rules = array_intersect_key(parent::rules(), array_flip(self::FIELDS));
        if (! $this->routeIs('cobros.collector.customers.store')) {
            return $rules;
        }

        $company = Company::query()->find(session('active_company_id'));
        $mayAssignCredit = $company?->allow_collector_credit_on_customer_create
            && $this->user()?->hasPermission('cobros.cobrar', $company);

        if (! $mayAssignCredit) {
            $rules['credit_limit'] = ['prohibited'];
            $rules['credit_days'] = ['prohibited'];

            return $rules;
        }

        $rules['credit_limit'] = [
            'required',
            'string',
            'regex:/^\d{1,13}(?:\.\d{1,2})?$/D',
            function ($attribute, $value, $fail) use ($company) {
                if (! is_string($value) || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
                    return;
                }
                if (bccomp($value, '0', 2) < 0) {
                    $fail('El límite de crédito no puede ser negativo.');
                } elseif (bccomp($value, (string) $company->collector_customer_credit_limit_max, 2) > 0) {
                    $fail('El límite de crédito supera el máximo autorizado por el dueño.');
                }
            },
        ];
        $creditLimit = $this->input('credit_limit');
        $hasPositiveCredit = is_string($creditLimit)
            && preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $creditLimit)
            && bccomp($creditLimit, '0', 2) > 0;
        $rules['credit_days'] = $hasPositiveCredit
            ? ['required', 'integer', 'min:1']
            : ['nullable', 'integer', 'min:0'];

        return $rules;
    }
}
