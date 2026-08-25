<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $attributes = [
        'preparation_minutes' => 0,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'duration_minutes',
        'price',
        'estimated_cost',
        'preparation_minutes',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:4',
            'estimated_cost' => 'decimal:4',
            'preparation_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'service_specialty')
            ->withPivot('company_id')
            ->wherePivot('company_id', $this->company_id)
            ->withTimestamps();
    }

    public function assignSpecialty(Specialty $specialty): void
    {
        if ((int) $this->company_id !== (int) $specialty->company_id) {
            throw ValidationException::withMessages([
                'specialty_id' => 'La especialidad debe pertenecer a la misma empresa del servicio.',
            ]);
        }

        $this->specialties()->syncWithoutDetaching([
            $specialty->getKey() => ['company_id' => $this->company_id],
        ]);
    }
}
