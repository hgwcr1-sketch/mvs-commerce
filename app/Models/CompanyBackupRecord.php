<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyBackupRecord extends Model
{
    public const KINDS = ['manual', 'scheduled', 'restore_test'];

    public const STATUSES = ['running', 'success', 'error'];

    protected $fillable = [
        'company_id',
        'kind',
        'status',
        'path',
        'size_bytes',
        'started_at',
        'finished_at',
        'message',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
