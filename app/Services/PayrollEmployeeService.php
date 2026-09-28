<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayrollEmployeeService
{
    public function list(): Collection
    {
        $context = PayrollTenantContext::resolve();

        return Employee::where('company_id', $context->companyId())
            ->get();
    }

    public function find(int $id): Employee
    {
        $context = PayrollTenantContext::resolve();

        return Employee::where('company_id', $context->companyId())
            ->where('id', $id)
            ->firstOrFail();
    }

    public function create(array $data): Employee
    {
        $context = PayrollTenantContext::resolve();

        $validator = Validator::make($data, [
            'employee_code' => ['required', 'string', 'max:50'],
            'identification' => ['required', 'string', 'max:50'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:100'],
            'hire_date' => ['required', 'date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return Employee::create([
            ...$validator->validated(),
            'company_id' => $context->companyId(),
        ]);
    }
}
