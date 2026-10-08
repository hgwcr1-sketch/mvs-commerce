<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FOTO DE APERTURA inmutables (append-only) generada al FINALIZAR.
 * Determinista: cliente, totales declarados y fechas ancla. Nunca se edita.
 */
class CobrosInitialPortfolioSnapshot extends Model
{
    protected $table = 'cobros_initial_portfolio_snapshots';

    protected $fillable = ['migration_id', 'company_id', 'collector_user_id', 'captured_at', 'payload'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'payload' => 'array'];
    }

    public function migration(): BelongsTo
    {
        return $this->belongsTo(CobrosInitialPortfolioMigration::class, 'migration_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_user_id');
    }
}
