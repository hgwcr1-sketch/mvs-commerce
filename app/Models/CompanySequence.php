<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CompanySequence extends Model
{
    public const POS_SALE = 'pos_sale';

    public const DOCUMENT_POS_SUSPENSION = 'pos_suspension';

    public const POS = self::POS_SALE;

    public const CASH_SESSION = 'cash_session';

    public const SALE_RETURN = 'sale_return';

    public const QUOTE = 'quote';
    public const LAYAWAY = 'layaway';
    public const ORDER = 'order';
    public const PURCHASE_ORDER = 'purchase_order';

    protected $fillable = [
        'company_id',
        'name',
        'current_value',
    ];

    protected function casts(): array
    {
        return [
            'current_value' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function nextValue(int $companyId, string $name): int
    {
        return DB::transaction(function () use ($companyId, $name): int {
            static::query()->insertOrIgnore([
                'company_id' => $companyId,
                'name' => $name,
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = static::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->current_value++;
            $sequence->save();

            return $sequence->current_value;
        });
    }

    public static function nextPosNumber(int $companyId): string
    {
        return sprintf('POS-%08d', static::nextValue($companyId, static::POS));
    }

    public static function nextSuspensionNumber(int $companyId): string
    {
        return sprintf('SUSP-%06d', static::nextValue($companyId, static::DOCUMENT_POS_SUSPENSION));
    }

    public static function nextCashSessionNumber(int $companyId): string
    {
        return sprintf('CAJA-%08d', static::nextValue($companyId, static::CASH_SESSION));
    }

    public static function nextSaleReturnNumber(int $companyId): string
    {
        return sprintf('DEV-%08d', static::nextValue($companyId, static::SALE_RETURN));
    }

    public static function nextQuoteNumber(int $companyId): string
    {
        return sprintf('COT-%08d', static::nextValue($companyId, static::QUOTE));
    }
    public static function nextLayawayNumber(int $companyId): string { return sprintf('APT-%08d', static::nextValue($companyId, static::LAYAWAY)); }

    public static function nextOrderNumber(int $companyId): string
    {
        return sprintf('PED-%08d', static::nextValue($companyId, static::ORDER));
    }

    public static function nextPurchaseOrderNumber(int $companyId): string
    {
        return sprintf('OC-%08d', static::nextValue($companyId, static::PURCHASE_ORDER));
    }
}
