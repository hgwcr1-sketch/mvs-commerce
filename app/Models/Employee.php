<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'employee_code',
    'identification',
    'identification_type',
    'first_name',
    'last_name',
    'birth_date',
    'email',
    'phone',
    'address',
    'position',
    'department',
    'hire_date',
    'base_salary',
    'payment_method',
    'payment_frequency',
    'weekly_work_days',
    'status',
    'termination_date',
    'bank_name',
    'bank_account',
    'iban',
])]

class Employee extends Model
{
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'base_salary' => 'decimal:2',
            'weekly_work_days' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
