<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CompanySequence extends Model
{
    public const POS = 'pos';

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
}
