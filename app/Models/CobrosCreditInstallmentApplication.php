<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 9B.1 - aplicación de un recibo a una cuota. Un recibo = una fila (única). */
class CobrosCreditInstallmentApplication extends Model
{
    use BelongsToCobrosTenant;

    protected $fillable = [
        'company_id',
        'credit_id',
        'installment_id',
        'account_receivable_id',
        'cobros_receipt_id',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(CobrosCredit::class, 'credit_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(CobrosCreditInstallment::class, 'installment_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CobrosReceipt::class, 'cobros_receipt_id');
    }
}
