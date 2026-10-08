<?php

namespace App\Services\Cobros;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CobrosQuickNavService
{
    public const MAX = 4;

    /** Catálogo CobrOS en orden: solo rutas CobrOS existentes (sin Commerce/Fidelización). */
    public function catalog(): array
    {
        return [
            'cobros.visits.today' => ['permission' => 'cobros.cobrar', 'label' => 'Agenda hoy'],
            'cobros.collector.index' => ['permission' => 'cobros.cobrar', 'label' => 'Mi cartera'],
            'cobros.collector.my-customers' => ['permission' => 'cobros.cobrar', 'label' => 'Mis clientes'],
            'cobros.collector.payments.history' => ['permission' => 'cobros.cobrar', 'label' => 'Mis recibos'],
            'cobros.collector.credit-requests.index' => ['permission' => 'cobros.cobrar', 'label' => 'Mis solicitudes'],
            'cobros.collector.credits.index' => ['permission' => 'cobros.cobrar', 'label' => 'Mis préstamos'],
            'cobros.closing.show' => ['permission' => 'cobros.cobrar', 'label' => 'Cierre del día'],
            'cobros.routes.select' => ['permission' => 'cobros.cobrar', 'label' => 'Cambiar ruta'],
            'cobros.owner.dashboard' => ['permission' => 'cobros.portal_dueno', 'label' => 'Inicio / Cartera'],
            'cobros.owner.collectors' => ['permission' => 'cobros.portal_dueno', 'label' => 'Cobradores'],
            'cobros.owner.assignments' => ['permission' => 'cobros.asignar', 'label' => 'Asignar cartera'],
            'cobros.owner.customers' => ['permission' => 'cobros.portal_dueno', 'label' => 'Clientes'],
            'cobros.owner.solicitudes.index' => ['permission' => 'cobros.portal_dueno', 'label' => 'Solicitudes'],
            'cobros.supervisor.index' => ['permission' => 'cobros.supervisar', 'label' => 'Supervisión'],
            'cobros.owner.audit' => ['permission' => 'cobros.portal_dueno', 'label' => 'Auditoría'],
            'cobros.owner.reports' => ['permission' => 'cobros.portal_dueno', 'label' => 'Resultados'],
            'cobros.owner.alerts' => ['permission' => 'cobros.portal_dueno', 'label' => 'Alertas'],
            'cobros.owner.routes' => ['permission' => 'cobros.portal_dueno', 'label' => 'Rutas'],
        ];
    }

    /** Rutas autorizadas del usuario en orden de catálogo. */
    public function allowedRoutes(User $user, Company $company): array
    {
        $catalog = $this->catalog();
        $out = [];
        foreach ($catalog as $route => $meta) {
            if ($route === 'cobros.routes.select') {
                if (! $user->hasPermission('cobros.cobrar', $company)) {
                    continue;
                }
                try {
                    if (app(CobrosRouteContext::class)->available($user)->count() < 2) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
                $out[] = $route;
                continue;
            }
            if ($user->hasPermission($meta['permission'], $company)) {
                $out[] = $route;
            }
        }

        return $out;
    }

    public function saved(User $user): array
    {
        try {
            if (! Schema::hasTable('cobros_quick_nav_preferences')) {
                return [];
            }
            $row = DB::table('cobros_quick_nav_preferences')->where('user_id', $user->id)->first();
            $decoded = json_decode((string) ($row->shortcuts ?? '[]'), true);

            return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Barra: preferencia guardada (solo autorizadas, sin duplicar) + relleno en orden de catálogo. */
    public function barRoutes(User $user, array $allowedRoutes): array
    {
        $allowed = array_values(array_unique($allowedRoutes));
        $bar = array_values(array_unique(array_intersect($this->saved($user), $allowed)));
        foreach ($allowed as $route) {
            if (count($bar) >= self::MAX) {
                break;
            }
            if (! in_array($route, $bar, true)) {
                $bar[] = $route;
            }
        }

        return array_slice($bar, 0, min(self::MAX, count($allowed)));
    }

    public function save(User $user, array $routes): void
    {
        $routes = array_values(array_unique(array_map('strval', $routes)));
        DB::table('cobros_quick_nav_preferences')->updateOrInsert(
            ['user_id' => $user->id],
            ['shortcuts' => json_encode($routes), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
