<?php

namespace App\Services\Cobros;

use App\Models\CobrosAssignment;
use App\Models\CobrosAssignmentLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortfolioAssignmentService
{
    /** $routeId: ruta activa del cobrador para migraciones 5B (null = sin ruta, legado). */
    public function assign(Company $company, Customer $customer, User $collector, User $actor, ?string $reason = null, ?int $routeId = null): CobrosAssignment
    {
        $this->assertSameCompany($company, $customer);
        $this->assertValidCollector($company, $collector);

        return DB::transaction(function () use ($company, $customer, $collector, $actor, $reason, $routeId) {
            $existing = CobrosAssignment::query()
                ->forCompany($company->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->collector_user_id === (int) $collector->id) {
                    // Migración 5B: completa la ruta solo si la asignación no la tenía.
                    if ($routeId !== null && $existing->cobros_route_id === null) {
                        $existing->update(['cobros_route_id' => $routeId]);
                    }

                    return $existing;
                }

                $from = (int) $existing->collector_user_id;
                $existing->update([
                    'collector_user_id' => $collector->id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);

                // 2B: al cambiar de cobrador, las visitas pendientes NO se transfieren
                // silenciosamente; se cierran como reprogramadas preservando el historial.
                app(VisitService::class)->closePendingForCustomer($customer, $actor, 'Cartera reasignada a otro cobrador');

                $this->log($company, $customer, $from, $collector->id, CobrosAssignmentLog::ACTION_REASSIGN, $actor, $reason);

                return $existing->fresh();
            }

            try {
                $assignment = CobrosAssignment::create([
                    'company_id' => $company->id,
                    'cobros_route_id' => $routeId,
                    'customer_id' => $customer->id,
                    'collector_user_id' => $collector->id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            } catch (QueryException $e) {
                throw ValidationException::withMessages([
                    'customer_id' => 'El cliente ya tiene una asignación activa. Use reasignar.',
                ]);
            }

            $this->log($company, $customer, null, $collector->id, CobrosAssignmentLog::ACTION_ASSIGN, $actor, $reason);

            return $assignment;
        });
    }

    public function reassign(Company $company, Customer $customer, User $collector, User $actor, ?string $reason = null): CobrosAssignment
    {
        return $this->assign($company, $customer, $collector, $actor, $reason);
    }

    public function unassign(Company $company, Customer $customer, User $actor, ?string $reason = null): void
    {
        $this->assertSameCompany($company, $customer);

        DB::transaction(function () use ($company, $customer, $actor, $reason) {
            $existing = CobrosAssignment::query()
                ->forCompany($company->id)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                throw ValidationException::withMessages([
                    'customer_id' => 'El cliente no tiene una asignación activa.',
                ]);
            }

            $from = (int) $existing->collector_user_id;
            $existing->delete();

            // 2B: desasignar también cierra visitas pendientes sin transferirlas.
            app(VisitService::class)->closePendingForCustomer($customer, $actor, 'Cliente desasignado de la cartera');

            $this->log($company, $customer, $from, null, CobrosAssignmentLog::ACTION_UNASSIGN, $actor, $reason);
        });
    }

    public function assertValidCollector(Company $company, User $collector): void
    {
        $isMember = $collector->companies()
            ->where('companies.id', $company->id)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'collector_user_id' => 'El cobrador no pertenece a esta empresa.',
            ]);
        }

        if (! $collector->is_active) {
            throw ValidationException::withMessages([
                'collector_user_id' => 'El cobrador debe estar activo.',
            ]);
        }

        $role = $collector->roleInCompany($company);
        $hasCobrar = $role !== null
            && $role->is_active
            && ! $role->is_super_admin
            && $role->permissions()
                ->where('permissions.name', 'cobros.cobrar')
                ->where('permissions.is_active', true)
                ->exists();

        if (! $hasCobrar) {
            throw ValidationException::withMessages([
                'collector_user_id' => 'El usuario no es un cobrador CobrOS válido en esta empresa.',
            ]);
        }
    }

    private function assertSameCompany(Company $company, Customer $customer): void
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'customer_id' => 'El cliente no pertenece a esta empresa.',
            ]);
        }
    }

    private function log(Company $company, Customer $customer, ?int $from, ?int $to, string $action, User $actor, ?string $reason): void
    {
        CobrosAssignmentLog::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'from_collector_user_id' => $from,
            'to_collector_user_id' => $to,
            'action' => $action,
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
