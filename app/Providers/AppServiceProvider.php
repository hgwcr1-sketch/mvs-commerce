<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\Company;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useTailwind();

        Gate::before(function (User $user, string $ability) {

            $company = Company::find(session('active_company_id'));

            if (!$company) {
                return false;
            }

            return $user->hasPermission($ability, $company)
                ? true
                : null;
        });
    }
}