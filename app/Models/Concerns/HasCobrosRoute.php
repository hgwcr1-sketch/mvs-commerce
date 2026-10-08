<?php

namespace App\Models\Concerns;

use App\Models\AccountReceivable;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteUser;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait HasCobrosRoute
{
    public static function bootHasCobrosRoute(): void
    {
        static::saving(function ($model) {
            if ($model->cobros_route_id === null) {
                return;
            }
            if (! CobrosRoute::forCompany((int) $model->company_id)->whereKey($model->cobros_route_id)->exists()) {
                throw ValidationException::withMessages(['cobros_route_id' => 'La ruta no pertenece a la empresa activa.']);
            }
            if ($model->customer_id && ! Customer::where('company_id', $model->company_id)->whereKey($model->customer_id)->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'El cliente no pertenece a la empresa de la ruta.']);
            }
            if ($model->collector_user_id && ! CobrosRouteUser::forCompany((int) $model->company_id)
                ->where('cobros_route_id', $model->cobros_route_id)->where('user_id', $model->collector_user_id)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['collector_user_id' => 'El cobrador no esta vinculado a esta ruta.']);
            }
            if ($model->account_receivable_id && ! AccountReceivable::where('company_id', $model->company_id)
                ->where('cobros_route_id', $model->cobros_route_id)->whereKey($model->account_receivable_id)->exists()) {
                throw ValidationException::withMessages(['account_receivable_id' => 'La cuenta no pertenece a esta ruta.']);
            }
        });
    }

    public function cobrosRoute(): BelongsTo
    {
        $relation = $this->belongsTo(CobrosRoute::class);

        return $this->company_id ? $relation->forCompany((int) $this->company_id) : $relation;
    }

    public function scopeForRoute(Builder $query, CobrosRoute $route): Builder
    {
        $active = (int) session('active_company_id', 0);

        return $query->where($this->qualifyColumn('company_id'), $active && $active !== (int) $route->company_id ? 0 : $route->company_id)
            ->where($this->qualifyColumn('cobros_route_id'), $route->id);
    }
}
