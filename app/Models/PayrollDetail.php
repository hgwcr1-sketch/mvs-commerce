<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'payroll_id',
    'employee_id',
    'base_salary',
    'gross_salary',
    'employer_subsidy',
    'ccss_subsidy',
    'disability_subsidy',
    'total_earnings',
    'total_income',
    'total_deductions',
    'net_salary',
    'worked_salary',
    'vacation_earnings',
    'worked_days',
    'vacation_days',
    'vacation_data_status',
])]

class PayrollDetail extends Model
{
    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'gross_salary' => 'decimal:2',
            'employer_subsidy' => 'decimal:2',
            'ccss_subsidy' => 'decimal:2',
            'disability_subsidy' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_income' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'worked_salary' => 'decimal:2',
            'vacation_earnings' => 'decimal:2',
            'worked_days' => 'decimal:2',
            'vacation_days' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
