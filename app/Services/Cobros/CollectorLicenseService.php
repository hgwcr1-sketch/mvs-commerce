<?php

namespace App\Services\Cobros;

use App\Models\CobrosCollectorLicense;
use App\Models\CobrosRoute;
use App\Models\CobrosRouteLicense;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyLicenseService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Licenciamiento individual de CobrOS.
 *
 * Reutiliza el contrato existente (`company_modules.license_mode`,
 * `admin_license_charged` y `CompanyLicenseService::getCobrosContract`) y
 * solo añade la entidad mínima de licencia por cobrador que la modalidad
 * `individual` exige. En `company_cupo` no se crean licencias: manda el
 * contrato de empresa y el cupo.
 */
class CollectorLicenseService
{
    /** Route licenses are independent of the number of collectors or branches. */
    public function licenseForRoute(CobrosRoute $route, Company $company): ?CobrosRouteLicense
    {
        if ((int) $route->company_id !== (int) $company->id) {
            return null;
        }

        return CobrosRouteLicense::forCompany($company->id)->where('cobros_route_id', $route->id)->first();
    }

    public function hasValidRouteLicense(CobrosRoute $route, Company $company): bool
    {
        if ((int) $route->company_id !== (int) $company->id || ! $company->is_active
            || ! $company->modules()->where('module_key', 'cobros')->where('is_enabled', true)->exists()
            || ! CobrosRoute::forCompany($company->id)->whereKey($route->id)->where('active', true)->exists()) {
            return false;
        }

        $companyLicense = $company->license()->first();
        if (! $companyLicense || ! $this->licenseService->refresh($companyLicense)->isOperable()) {
            return false;
        }

        // Neither company_cupo nor a free owner exempts a route from its license.
        return $this->stateOf($this->licenseForRoute($route, $company), $company) === CobrosCollectorLicense::STATE_ACTIVE;
    }

    /** Explicit commercial grant/renewal; never issued automatically on route creation. */
    public function renewForRoute(CobrosRoute $route, Company $company, User $actor, CarbonInterface $expiresAt): CobrosRouteLicense
    {
        abort_unless($actor->isPlatformAdmin(), 403);
        if (! CobrosRoute::forCompany($company->id)->whereKey($route->id)->exists()
            || (int) $route->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['cobros_route_id' => 'La ruta no pertenece a la empresa activa.']);
        }
        $now = $this->now($company);
        if ($expiresAt->lte($now)) {
            throw ValidationException::withMessages(['expires_at' => 'La nueva vigencia debe ser posterior a hoy.']);
        }

        return DB::transaction(function () use ($route, $company, $actor, $expiresAt, $now) {
            CobrosRoute::forCompany($company->id)->whereKey($route->id)->lockForUpdate()->firstOrFail();

            $license = CobrosRouteLicense::forCompany($company->id)->updateOrCreate(
                ['company_id' => $company->id, 'cobros_route_id' => $route->id],
                ['status' => CobrosCollectorLicense::STATUS_ACTIVE, 'starts_at' => $now,
                    'expires_at' => $expiresAt, 'renewed_at' => $now,
                    'suspended_at' => null, 'suspended_by_user_id' => null]
            );
            $this->licenseService->ensure($company)->events()->create([
                'company_id' => $company->id, 'actor_id' => $actor->id,
                'action' => 'cobros_route_renewal', 'to_status' => 'active',
                'snapshot' => $license->only(['cobros_route_id', 'status', 'starts_at', 'expires_at']),
                'notes' => 'Licencia independiente por ruta CobrOS.',
            ]);

            return $license;
        });
    }

    /** Vigencia por defecto al alta de un cobrador individual. */
    public const DEFAULT_TERM_DAYS = 365;

    public function __construct(
        private readonly CompanyLicenseService $licenseService,
    ) {}

    public function contract(Company $company): ?array
    {
        return $this->licenseService->getCobrosContract($company);
    }

    public function isIndividual(Company $company): bool
    {
        return ($this->contract($company)['license_mode'] ?? null) === 'individual';
    }

    public function adminCharged(Company $company): bool
    {
        return (bool) ($this->contract($company)['admin_license_charged'] ?? false);
    }

    /**
     * En modalidad individual: todo cobrador requiere licencia propia; el
     * dueño/admin solo si su licencia está configurada como cobrada.
     * Sin contrato individual nadie requiere licencia (company_cupo/gratis).
     */
    public function requiresLicense(User $user, Company $company): bool
    {
        if (! $this->isIndividual($company)) {
            return false;
        }

        if ($user->hasPermission('cobros.portal_dueno', $company)) {
            return $this->adminCharged($company);
        }

        return $user->hasPermission('cobros.cobrar', $company);
    }

    public function licenseFor(User $user, Company $company): ?CobrosCollectorLicense
    {
        return CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /** @return array<int, CobrosCollectorLicense> licencias de la empresa indexadas por usuario */
    public function licensesByUser(Company $company): array
    {
        return CobrosCollectorLicense::query()
            ->where('company_id', $company->id)
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    public function now(Company $company): CarbonInterface
    {
        return CarbonImmutable::now($company->timezone ?: config('app.timezone'));
    }

    public function stateOf(CobrosCollectorLicense|CobrosRouteLicense|null $license, Company $company): string
    {
        if ($license === null) {
            return CobrosCollectorLicense::STATE_NONE;
        }

        return $license->effectiveState($this->now($company));
    }

    public function hasValidLicense(User $user, Company $company): bool
    {
        if (! $this->requiresLicense($user, $company)) {
            return true;
        }

        return $this->stateOf($this->licenseFor($user, $company), $company) === CobrosCollectorLicense::STATE_ACTIVE;
    }

    public function denyMessage(User $user, Company $company): string
    {
        $license = $this->licenseFor($user, $company);
        $state = $this->stateOf($license, $company);

        return match ($state) {
            CobrosCollectorLicense::STATE_SUSPENDED => 'Su licencia CobrOS individual está suspendida. Contacte al administrador.',
            CobrosCollectorLicense::STATE_EXPIRED => 'Su licencia CobrOS individual venció el '.$license->expires_at->format('d/m/Y').'. Renueve para continuar operando.',
            CobrosCollectorLicense::STATE_PENDING => 'Su licencia CobrOS individual inicia el '.$license->starts_at->format('d/m/Y').'.',
            default => 'No tiene una licencia CobrOS individual activa. Contacte al administrador.',
        };
    }

    /** Lanza el error de validación cuando el usuario no puede operar CobrOS. */
    public function assertCanOperate(User $user, Company $company): void
    {
        if (! $this->hasValidLicense($user, $company)) {
            throw ValidationException::withMessages(['license' => $this->denyMessage($user, $company)]);
        }
    }

    /**
     * Alta de licencia si no existe. Nunca renueva ni reactiva por sí solo:
     * vencidas o suspendidas se atienden con `renew`/`resume` explícitos.
     */
    public function grantIfMissing(User $user, Company $company): ?CobrosCollectorLicense
    {
        if (! $this->isIndividual($company)) {
            return null;
        }

        $existing = $this->licenseFor($user, $company);
        if ($existing !== null) {
            return $existing;
        }

        $now = $this->now($company);

        return CobrosCollectorLicense::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'status' => CobrosCollectorLicense::STATUS_ACTIVE,
            'starts_at' => $now,
            'expires_at' => $now->copy()->addDays(self::DEFAULT_TERM_DAYS),
            'notes' => 'Alta automática al crear el cobrador.',
        ]);
    }

    /** Renovación: nueva vigencia y estado activo (limpia suspensión). */
    public function renew(User $user, Company $company, CarbonInterface $expiresAt): CobrosCollectorLicense
    {
        $license = $this->licenseFor($user, $company);
        $now = $this->now($company);

        if ($expiresAt->lte($now)) {
            throw ValidationException::withMessages(['expires_at' => 'La nueva vigencia debe ser posterior a hoy.']);
        }

        if ($license === null) {
            return CobrosCollectorLicense::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'status' => CobrosCollectorLicense::STATUS_ACTIVE,
                'starts_at' => $now,
                'expires_at' => $expiresAt,
                'renewed_at' => $now,
            ]);
        }

        $license->update([
            'status' => CobrosCollectorLicense::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
            'suspended_at' => null,
            'suspended_by_user_id' => null,
            'renewed_at' => $now,
        ]);

        return $license->refresh();
    }

    public function suspend(User $user, Company $company, User $actor): CobrosCollectorLicense
    {
        $license = $this->requireLicense($user, $company);

        $license->update([
            'status' => CobrosCollectorLicense::STATUS_SUSPENDED,
            'suspended_at' => $this->now($company),
            'suspended_by_user_id' => $actor->id,
        ]);

        return $license->refresh();
    }

    public function resume(User $user, Company $company): CobrosCollectorLicense
    {
        $license = $this->requireLicense($user, $company);

        $license->update([
            'status' => CobrosCollectorLicense::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspended_by_user_id' => null,
        ]);

        return $license->refresh();
    }

    private function requireLicense(User $user, Company $company): CobrosCollectorLicense
    {
        $license = $this->licenseFor($user, $company);

        if ($license === null) {
            throw ValidationException::withMessages(['license' => 'Este usuario no tiene licencia individual registrada.']);
        }

        return $license;
    }
}
