<?php

namespace App\Services;

use App\Models\RouteosAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * R01 MVS RouteOS — Infraestructura de auditoría reutilizable.
 *
 * Registra acciones sensibles (crédito, pedidos, reservas, bodega,
 * facturación, liquidaciones, rutas y visitas en roads futuros) con
 * actor, empresa, sucursal, entidad, valores anteriores/nuevos y metadata.
 *
 * Regla: no registrar información innecesariamente sensible. Los callers
 * deben excluir campos como contraseñas o tokens antes de llamar a log().
 */
class RouteosAuditService
{
    public function log(
        int $companyId,
        ?int $branchId,
        string $action,
        ?Model $entity = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?User $actor = null,
    ): RouteosAuditLog {
        return RouteosAuditLog::create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'actor_id' => $actor?->id ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entity ? $entity::class : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
