<?php

namespace App\Services;

use App\Models\Payroll;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function list(): Collection
    {
        $context = PayrollTenantContext::resolve();

        return Payroll::where('company_id', $context->companyId())
            ->get();
    }

    public function create(array $data): Payroll
    {
        $context = PayrollTenantContext::resolve();

        $validator = Validator::make($data, [
            'payroll_number' => ['required', 'string', 'max:255', 'unique:payrolls,payroll_number'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'frequency' => ['required', 'string', 'in:semanal,quincenal,mensual'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $validated['company_id'] = $context->companyId();
        $validated['status'] = 'borrador';

        return Payroll::create($validated);
    }
}
