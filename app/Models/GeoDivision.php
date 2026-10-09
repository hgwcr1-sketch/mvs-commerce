<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class GeoDivision extends Model
{
    protected $fillable = [
        'country_id',
        'parent_id',
        'level',
        'code',
        'name',
        'catalog_version',
        'is_active',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (GeoDivision $division) {
            $division->code = trim((string) $division->code);
            $division->name = trim((string) $division->name);

            if ($division->code === '' || $division->name === '') {
                throw ValidationException::withMessages([
                    'code' => 'El código oficial y el nombre son obligatorios.',
                ]);
            }

            if (! Country::query()->whereKey($division->country_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'country_id' => 'El país no está configurado o está inactivo.',
                ]);
            }

            if (! GeoCatalogLevel::query()->where('country_id', $division->country_id)
                ->where('level', $division->level)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'level' => 'El nivel territorial no está configurado para este país.',
                ]);
            }

            if ($division->parent_id) {
                $parent = static::query()->find($division->parent_id);

                if (! $parent || (int) $parent->country_id !== (int) $division->country_id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'El padre debe pertenecer al mismo país.',
                    ]);
                }

                if ((int) $parent->level !== (int) $division->level - 1) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'El padre debe corresponder al nivel inmediato superior.',
                    ]);
                }
            } elseif ((int) $division->level !== 1) {
                throw ValidationException::withMessages([
                    'level' => 'Una división sin padre debe ser de nivel 1.',
                ]);
            }
        });
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function parent()
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCountry(Builder $query, string $iso2): Builder
    {
        return $query->whereHas('country', fn (Builder $q) => $q->where('iso2', $iso2));
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
