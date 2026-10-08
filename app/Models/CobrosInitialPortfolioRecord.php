<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque 5B — cliente/crédito cargado manualmente en la migración inicial 5A.
 *
 * Append-only: source=cartera_inicial y verification=informacion_declarada son
 * permanentes; NUNCA pasa a "historial verificado por CobrOS". Conserva quién
 * y cuándo lo migró. Montos en decimal (BCMath), nunca float.
 */
class CobrosInitialPortfolioRecord extends Model
{
    public const SOURCE_INITIAL = 'cartera_inicial';

    public const VERIFICATION_DECLARED = 'informacion_declarada';

    protected $table = 'cobros_initial_portfolio_records';

    protected $fillable = [
        'migration_id', 'company_id', 'customer_id', 'identification_type',
        'source', 'external_reference', 'verification', 'alt_contact_name', 'alt_contact_phone',
        'original_amount', 'balance', 'installment', 'frequency', 'next_charge_date',
        'prior_credits', 'declared_behavior', 'historical_note',
        'customer_reused', 'migrated_by_user_id', 'migrated_at',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'installment' => 'decimal:4',
            'next_charge_date' => 'date',
            'customer_reused' => 'boolean',
            'migrated_at' => 'datetime',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    public function migration(): BelongsTo
    {
        return $this->belongsTo(CobrosInitialPortfolioMigration::class, 'migration_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function migratedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'migrated_by_user_id');
    }
}
