<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

trait BelongsToCobrosTenant
{
    public static function bootBelongsToCobrosTenant(): void
    {
        static::addGlobalScope('cobros_company', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('company_id'), (int) session('active_company_id', 0));
        });
        static::saving(function ($model) {
            $active = (int) session('active_company_id', 0);
            if (! $model->company_id || ($active && $active !== (int) $model->company_id)
                || ($model->exists && $model->isDirty('company_id'))) {
                throw ValidationException::withMessages(['company_id' => 'La empresa de la ruta no es valida.']);
            }
        });
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        $active = (int) session('active_company_id', 0);

        return $query->withoutGlobalScope('cobros_company')->where(
            $this->qualifyColumn('company_id'), $active && $active !== $companyId ? 0 : $companyId
        );
    }
}
