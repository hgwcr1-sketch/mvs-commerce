<?php

namespace App\Models;

use Database\Factories\ProfessionalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

class Professional extends Model
{
    /** @use HasFactory<ProfessionalFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'company_id',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Professional $professional): void {
            $hasCompanyAccess = User::query()
                ->whereKey($professional->user_id)
                ->whereHas('companies', fn (Builder $query) => $query->whereKey($professional->company_id))
                ->exists();

            if (! $hasCompanyAccess) {
                throw ValidationException::withMessages([
                    'user_id' => 'El usuario debe pertenecer a la empresa del perfil profesional.',
                ]);
            }
        });
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'professional_branch')
            ->withPivot('company_id')
            ->wherePivot('company_id', $this->company_id)
            ->withTimestamps();
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'professional_specialty')
            ->withPivot('company_id')
            ->wherePivot('company_id', $this->company_id)
            ->withTimestamps();
    }

    public function assignBranch(Branch $branch): void
    {
        $this->assertSameCompany($branch->company_id, 'branch_id');

        $this->branches()->syncWithoutDetaching([
            $branch->getKey() => ['company_id' => $this->company_id],
        ]);
    }

    public function assignSpecialty(Specialty $specialty): void
    {
        $this->assertSameCompany($specialty->company_id, 'specialty_id');

        $this->specialties()->syncWithoutDetaching([
            $specialty->getKey() => ['company_id' => $this->company_id],
        ]);
    }

    private function assertSameCompany(int $relatedCompanyId, string $field): void
    {
        if ((int) $this->company_id !== $relatedCompanyId) {
            throw ValidationException::withMessages([
                $field => 'La relación debe pertenecer a la misma empresa del profesional.',
            ]);
        }
    }
}
