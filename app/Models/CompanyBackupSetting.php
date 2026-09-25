<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyBackupSetting extends Model
{
    public const PLANS = ['basico', 'estandar', 'fe_5_anios', 'personalizado'];

    public const PLAN_LABELS = [
        'basico' => 'Básico',
        'estandar' => 'Estándar',
        'fe_5_anios' => 'FE 5 años',
        'personalizado' => 'Personalizado',
    ];

    public const FREQUENCIES = ['diario', 'semanal', 'mensual'];

    public const FREQUENCY_LABELS = [
        'diario' => 'Diario',
        'semanal' => 'Semanal',
        'mensual' => 'Mensual',
    ];

    public const EXTERNAL_COPIES = ['desactivada', 'preparada', 'activada'];

    public const EXTERNAL_COPY_LABELS = [
        'desactivada' => 'Desactivada',
        'preparada' => 'Preparada / configurable',
        'activada' => 'Activada',
    ];

    public const PLAN_FE_RETENTION_DAYS = 1825;

    protected $fillable = [
        'company_id',
        'is_enabled',
        'plan',
        'frequency',
        'retention_days',
        'manual_backup_allowed',
        'external_copy',
        'encryption_required',
        'next_backup_at',
        'last_backup_at',
        'last_status',
        'last_size_bytes',
        'last_message',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'manual_backup_allowed' => 'boolean',
            'encryption_required' => 'boolean',
            'retention_days' => 'integer',
            'last_size_bytes' => 'integer',
            'next_backup_at' => 'datetime',
            'last_backup_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function records()
    {
        return $this->hasMany(CompanyBackupRecord::class, 'company_id', 'company_id');
    }
}
