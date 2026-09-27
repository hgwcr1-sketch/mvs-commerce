<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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
        $this->pinApplicationUrl();

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

    private function pinApplicationUrl(): void
    {
        if (!app()->environment('production')) {
            return;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if ($appUrl === '' || !in_array($scheme, ['http', 'https'], true)) {
            return;
        }

        URL::useOrigin($appUrl);
        URL::forceScheme($scheme);
    }
}