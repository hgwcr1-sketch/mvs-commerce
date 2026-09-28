<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

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
}
