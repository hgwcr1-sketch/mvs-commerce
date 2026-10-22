<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\Company;
use App\Contracts\Fiscal\FiscalTaxpayerLookupInterface;
use App\Services\Fiscal\Hacienda\HaciendaClient;
use App\Services\Fiscal\Hacienda\HaciendaTaxpayerLookupService;
use App\Services\Fiscal\Hacienda\HaciendaTaxpayerNormalizer;
use App\Contracts\Cobros\MaestroReferralCredentialProvider;
use App\Services\Cobros\MaestroJwsCredentialProvider;
use App\Contracts\Cobros\MaestroAccreditationEvidenceVerifier;
use App\Services\Cobros\UnavailableMaestroAccreditationEvidenceVerifier;
use Mvs\MaestroClient\MaestroPlatformClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaestroReferralCredentialProvider::class, MaestroJwsCredentialProvider::class);
        $this->app->bind(MaestroAccreditationEvidenceVerifier::class, UnavailableMaestroAccreditationEvidenceVerifier::class);
        $this->app->bind(MaestroPlatformClient::class, function ($app) {
            if (! (bool) $app['config']->get('maestro.enabled', false)) {
                throw new \LogicException('Integración Maestro deshabilitada.');
            }
            return new MaestroPlatformClient(
                (string) $app['config']->get('maestro.url', ''),
                (string) $app['config']->get('maestro.token', ''),
                (string) $app['config']->get('maestro.event_secret', ''),
                (float) $app['config']->get('maestro.timeout', 5.0),
            );
        });
        // MF03 (portado de mvs-fiscal): consulta de contribuyentes de Hacienda.
        $this->app->bind(HaciendaClient::class, fn () => HaciendaClient::fromConfig());
        $this->app->bind(HaciendaTaxpayerNormalizer::class, fn () => new HaciendaTaxpayerNormalizer());
        $this->app->bind(HaciendaTaxpayerLookupService::class, fn ($app) => new HaciendaTaxpayerLookupService(
            $app->make(HaciendaClient::class),
            $app->make(HaciendaTaxpayerNormalizer::class),
        ));
        $this->app->bind(FiscalTaxpayerLookupInterface::class, fn ($app) => $app->make(HaciendaTaxpayerLookupService::class));
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
