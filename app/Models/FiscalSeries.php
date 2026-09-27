<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalSeries extends Model
{
    protected $fillable = [
        'company_id',
        'environment',
        'branch_code',
        'terminal_code',
        'document_type',
        'last_sequence',
        'last_consecutivo',
        'last_document_id',
    ];

    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function lastDocument()
    {
        return $this->belongsTo(ElectronicDocument::class, 'last_document_id');
    }
}
