<?php

namespace App\Services\Notifications;

use App\Models\Alert;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceService
{
    /**
     * Devuelve preferencias para una empresa y tipo, ordenadas por especificidad:
     * usuario > rol > empresa (user_id/role_id nulos).
     */
    public function preferencesForType(Company $company, string $type): Collection
    {
        return NotificationPreference::query()
            ->where('company_id', $company->id)
            ->where('type', $type)
            ->orderByRaw('CASE
                WHEN user_id IS NOT NULL THEN 1
                WHEN role_id IS NOT NULL THEN 2
                ELSE 3
            END')
            ->get();
    }

    public function effectivePreference(User $user, Company $company, ?Role $role, string $type): ?NotificationPreference
    {
        $preferences = $this->preferencesForType($company, $type);

        $userPreference = $preferences->first(fn (NotificationPreference $preference) => (int) $preference->user_id === (int) $user->id);
        if ($userPreference) {
            return $userPreference;
        }

        if ($role) {
            $rolePreference = $preferences->first(fn (NotificationPreference $preference) => $preference->user_id === null && (int) $preference->role_id === (int) $role->id);
            if ($rolePreference) {
                return $rolePreference;
            }
        }

        return $preferences->first(fn (NotificationPreference $preference) => $preference->user_id === null && $preference->role_id === null);
    }

    /**
     * Determina si un usuario recibe una alerta de un tipo dado considerando
     * preferencias por usuario, rol y empresa. Una preferencia nunca concede
     * acceso; solo filtra dentro de lo ya autorizado.
     */
    public function isEnabledForUser(User $user, Company $company, ?Role $role, string $type): bool
    {
        $preference = $this->effectivePreference($user, $company, $role, $type);

        return $preference?->enabled ?? true;
    }

    public function allowsAlert(User $user, Company $company, ?Role $role, string $type, string $severity): bool
    {
        $preference = $this->effectivePreference($user, $company, $role, $type);
        if ($preference === null) {
            return true;
        }

        if (! $preference->enabled) {
            return false;
        }

        return $this->severityAllows($preference->severity_min, $severity);
    }

    public function severityAllows(string $configuredMin, string $alertSeverity): bool
    {
        $rank = [
            Alert::SEVERITY_INFO => 1,
            Alert::SEVERITY_ATTENTION => 2,
            Alert::SEVERITY_CRITICAL => 3,
        ];

        return ($rank[$alertSeverity] ?? 0) >= ($rank[$configuredMin] ?? 1);
    }

    public function ensureCompanyDefaults(Company $company): void
    {
        foreach (array_keys(AlertTypeRegistry::all()) as $type) {
            $exists = NotificationPreference::query()
                ->where('company_id', $company->id)
                ->where('type', $type)
                ->whereNull('role_id')
                ->whereNull('user_id')
                ->exists();

            if (! $exists) {
                $this->setPreference($company, $type, true, Alert::SEVERITY_INFO);
            }
        }
    }

    public function setPreference(Company $company, string $type, bool $enabled, ?string $severityMin = null, ?Role $role = null, ?User $user = null, ?array $channels = null): NotificationPreference
    {
        if (AlertTypeRegistry::forType($type) === null) {
            throw ValidationException::withMessages(['type' => 'Tipo de alerta no válido.']);
        }

        $severityMin = $severityMin ?? Alert::SEVERITY_INFO;
        if (! in_array($severityMin, Alert::SEVERITIES, true)) {
            throw ValidationException::withMessages(['severity_min' => 'Severidad no válida.']);
        }

        if ($user !== null) {
            $belongs = $company->users()->where('users.id', $user->id)->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['user_id' => 'El usuario no pertenece a esta empresa.']);
            }
            $role = null;
        } elseif ($role !== null && (int) $role->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['role_id' => 'El rol no pertenece a esta empresa.']);
        }

        $values = [
            'enabled' => $enabled,
            'severity_min' => $severityMin,
            'channels' => $channels,
        ];

        return DB::transaction(function () use ($company, $type, $role, $user, $values) {
            $query = NotificationPreference::query()
                ->where('company_id', $company->id)
                ->where('type', $type);

            if ($user) {
                $query->where('user_id', $user->id);
            } else {
                $query->whereNull('user_id');
            }

            if ($role) {
                $query->where('role_id', $role->id);
            } else {
                $query->whereNull('role_id');
            }

            $preference = $query->lockForUpdate()->first();
            if ($preference) {
                $preference->update($values);

                return $preference->fresh();
            }

            return NotificationPreference::query()->create([
                'company_id' => $company->id,
                'type' => $type,
                'role_id' => $role?->id,
                'user_id' => $user?->id,
                ...$values,
            ]);
        });
    }
}
