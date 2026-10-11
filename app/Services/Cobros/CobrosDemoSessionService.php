<?php

namespace App\Services\Cobros;

use App\Models\CobrosDemoSession;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BLOQUE 21D-4A — sesiones DEMO independientes por visitante.
 *
 * Cada visitante recibe un espacio DEMO AISLADO (empresa propia, datos
 * sintéticos y mínimos) identificado por un token opaco de un solo uso en
 * cliente. En BD solo se persiste el HASH SHA-256 del token. Reutiliza
 * CompanyProvisioner (provisión de empresa/licencia/métodos/rol/sucursal);
 * NO crea otra aplicación ni toca el DEMO single-tenant previo (CobrosDemoScenario
 * / DemoCompanyProvisioner), que quedan intactos.
 *
 * Seguridad:
 *  - Token opaco aleatorio (40 chars); solo se guarda su hash.
 *  - Cada sesión => UNA empresa aislada; el token resuelve a la empresa de SU
 *    visitante. No hay forma de llegar a la empresa de otro con el token propio.
 *  - Vencimiento configurable; al caducar la sesión deja de resolver.
 *  - purgeExpired() elimina SOLO empresas ligadas a sesiones DEMO vencidas
 *    (nunca empresas reales ni el DEMO single-tenant), vía cascade de company_id.
 *  - Sin pagos, activaciones ni llamadas externas; entorno local/testing.
 */
class CobrosDemoSessionService
{
    public function __construct(
        private readonly CompanyProvisioner $provisioner,
        private readonly CobrosDemoScenario $scenario,
    ) {}

    /** Revalidate the persisted visitor identity on every authenticated request. */
    public function allowsOperationalAccess(User $user, string $token, int $companyId): bool
    {
        $session = $this->resolve($token);
        if ($session === null || ! $session->expires_at || ! $session->expires_at->isFuture()
            || (int) $session->company_id !== $companyId) {
            return false;
        }

        $company = $session->company;
        return $company !== null && $company->is_active
            && (int) $company->owner_user_id === (int) $session->owner_user_id
            && $user->is_active
            && $user->companies()->where('companies.id', $companyId)->exists();
    }
    /** TTL de una sesión de visitante, en minutos. */
    public function ttlMinutes(): int
    {
        return (int) config('demo.visitor_session_ttl_minutes', 120);
    }

    /** Cupo máximo de sesiones DEMO vivas (límite de recursos). */
    public function maxLiveSessions(): int
    {
        return (int) config('demo.visitor_max_live_sessions', 50);
    }

    /**
     * Crea una sesión DEMO aislada para un visitante nuevo.
     *
     * @return array{token: string, session: CobrosDemoSession}
     */
    public function start(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Las sesiones DEMO de visitante solo existen en local/testing.');
        }

        $live = CobrosDemoSession::query()->where('expires_at', '>', now())->count();
        if ($live >= $this->maxLiveSessions()) {
            // Límite de recursos: primero limpia vencidas; si no alcanza, no crea más.
            $this->purgeExpired();
            $live = CobrosDemoSession::query()->where('expires_at', '>', now())->count();
            if ($live >= $this->maxLiveSessions()) {
                throw new RuntimeException('Se alcanzó el cupo máximo de sesiones DEMO activas.');
            }
        }

        $token = Str::random(40);
        $suffix = Str::lower(Str::random(8));

        $session = DB::transaction(function () use ($token, $suffix) {
            $owner = $this->provisionVisitorOwner($suffix);
            $company = $this->provisionVisitorCompany($owner, $suffix);

            // DEMO-DATOS-01B.2A: siembra el escenario liviano (2 cobradores,
            // 14 clientes, agenda semanal) SOLO en la empresa del visitante.
            // CobrosDemoScenario ya reconoce "DEMO Visitante*" y es idempotente.
            $this->scenario->seed($company);

            return CobrosDemoSession::create([
                'token_hash' => hash('sha256', $token),
                'company_id' => $company->id,
                'owner_user_id' => $owner->id,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'last_seen_at' => now(),
            ]);
        });

        return ['token' => $token, 'session' => $session];
    }

    /**
     * Resuelve un token opaco a SU sesión (y empresa) de visitante.
     * Devuelve null si no existe o está vencida (acceso cruzado denegado).
     */
    public function resolve(string $token): ?CobrosDemoSession
    {
        if ($token === '') {
            return null;
        }

        $session = CobrosDemoSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($session === null || $session->isExpired()) {
            return null;
        }

        $session->forceFill(['last_seen_at' => now()])->save();

        return $session;
    }

    /**
     * Elimina SOLO las sesiones vencidas y sus empresas-demo (cascade).
     * Nunca toca empresas reales ni el DEMO single-tenant.
     *
     * @return int sesiones eliminadas
     */
    public function purgeExpired(): int
    {
        $expiredIds = CobrosDemoSession::query()
            ->where('expires_at', '<=', now())
            ->pluck('id');

        if ($expiredIds->isEmpty()) {
            return 0;
        }

        // El borrado de la sesión NO borra la empresa; borramos la empresa demo
        // explícitamente SOLO si sigue ligada a una sesión vencida (aislado).
        $companyIds = CobrosDemoSession::query()
            ->whereIn('id', $expiredIds)
            ->pluck('company_id')
            ->unique();

        $removed = CobrosDemoSession::query()->whereIn('id', $expiredIds)->delete();

        foreach ($companyIds as $companyId) {
            // Refuerzo: solo empresas que NACieron como demo de visitante.
            $stillLive = CobrosDemoSession::query()->where('company_id', $companyId)
                ->where('expires_at', '>', now())->exists();
            if (! $stillLive) {
                $this->purgeVisitorCompany((int) $companyId);
            }
        }

        return $removed;
    }

    /** Usuario sintético del visitante (email aleatorio, no real). */
    private function provisionVisitorOwner(string $suffix): User
    {
        return User::create([
            'name' => 'Visitante DEMO',
            'email' => "visitante.{$suffix}@demo-visitante.test",
            'password' => Hash::make(Str::random(32)),
            'is_active' => true,
        ]);
    }

    /** Empresa-demo aislada y mínima del visitante (reutiliza CompanyProvisioner). */
    private function provisionVisitorCompany(User $owner, string $suffix): Company
    {
        $company = $this->provisioner->provision(
            $owner,
            [
                'trade_name' => 'DEMO Visitante '.Str::upper($suffix),
                'currency' => 'CRC',
                'timezone' => 'America/Costa_Rica',
                'is_active' => true,
            ],
            'Principal',
            'VP-'.$suffix,
            1,
            [],
            ['cobros'],
        );

        return $company;
    }

    /**
     * Limpieza exclusiva de la empresa-demo del visitante (nada productivo).
     * Desactiva y renombra (no hard-delete) para no violar FKs de datos
     * provisionados; el acceso queda bloqueado y queda marcada como purgada.
     */
    private function purgeVisitorCompany(int $companyId): void
    {
        $company = Company::find($companyId);
        if ($company === null) {
            return;
        }

        // Refuerzo de seguridad: jamás el DEMO single-tenant ni una empresa sin
        // el marcador de visitante.
        if ($company->trade_name !== null && str_starts_with($company->trade_name, 'DEMO Visitante')) {
            $company->update([
                'is_active' => false,
                'trade_name' => 'DEMO Visitante PURGADA '.$company->id,
            ]);
        }
    }

    /** Marca la empresa como demo-visitante para auditoría (sin exponer token). */
    public function isVisitorDemoCompany(Company $company): bool
    {
        return (bool) ($company->trade_name !== null && str_starts_with($company->trade_name, 'DEMO Visitante'));
    }

    /** Permisos mínimos que el visitante puede tener (control de recursos). */
    public function cobrosPermissionNames(): array
    {
        return Permission::query()
            ->where('is_active', true)
            ->where('name', 'like', 'cobros.%')
            ->pluck('name')
            ->all();
    }
}
