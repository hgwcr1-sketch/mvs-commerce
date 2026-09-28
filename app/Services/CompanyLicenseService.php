<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\LicensePlan;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyLicenseService
{
    public function ensure(Company $company): CompanyLicense
    {
        return $company->license()->firstOrCreate([], [
            'status' => 'trial', 'plan' => 'Prueba', 'starts_at' => now(),
            'expires_at' => now()->addDays(30), 'grace_until' => now()->addDays(37),
        ]);
    }

    public function refresh(CompanyLicense $license): CompanyLicense
    {
        if (in_array($license->status, ['suspended', 'cancelled'], true)) {
            return $license;
        }
        $status = $license->status;
        if ($license->expires_at?->isPast()) {
            $status = $license->grace_until?->isFuture() ? 'grace' : 'expired';
        } elseif ($status === 'grace' || $status === 'expired') {
            $status = $license->plan === 'Prueba' ? 'trial' : 'active';
        }
        if ($status !== $license->status) {
            $this->transition($license, $status, null, 'Actualización automática por vigencia.', 'automatic');
        }

        return $license->refresh();
    }

    private function transition(CompanyLicense $license, string $status, ?User $actor, ?string $notes, string $action = 'transition', array $attributes = []): CompanyLicense
    {
        if (! in_array($status, CompanyLicense::STATUSES, true)) {
            abort(422);
        }

        return DB::transaction(function () use ($license, $status, $actor, $notes, $action, $attributes) {
            $tracked = ['status', 'plan', 'starts_at', 'expires_at', 'next_renewal_at', 'grace_until', 'user_limit', 'branch_limit'];
            $before = $license->only($tracked);
            $from = $license->status;
            $license->update([...$attributes, 'status' => $status, 'notes' => $notes, 'updated_by' => $actor?->id]);
            $snapshot = $license->fresh()->only($tracked);
            $changes = collect($snapshot)->filter(fn ($value, $key) => $before[$key] != $value)
                ->mapWithKeys(fn ($value, $key) => [$key => ['from' => $before[$key], 'to' => $value]])->all();
            $license->events()->create(['company_id' => $license->company_id, 'actor_id' => $actor?->id, 'action' => $action, 'from_status' => $from, 'to_status' => $status, 'snapshot' => $snapshot, 'changes' => $changes, 'notes' => $notes]);

            return $license->fresh();
        });
    }

    public function updateContract(Company $company, User $actor, string $status, ?string $notes, array $attributes = []): CompanyLicense
    {
        abort_unless($actor->isPlatformAdmin(), 403);

        return $this->transition(
            $this->ensure($company),
            $status,
            $actor,
            $notes,
            'manual',
            $attributes,
        );
    }

    public function changeLifecycle(Company $company, User $actor, string $action, ?string $notes = null, array $attributes = []): CompanyLicense
    {
        abort_unless($actor->isPlatformAdmin(), 403);

        $status = match ($action) {
            'activate', 'reactivate' => 'active',
            'suspend' => 'suspended',
            'cancel' => 'cancelled',
            default => throw ValidationException::withMessages(['action' => 'Acción de licencia no reconocida.']),
        };

        return $this->transition($this->ensure($company), $status, $actor, $notes, $action, $attributes);
    }

    public function renew(Company $company, User $actor, CarbonInterface $expiresAt, ?CarbonInterface $nextRenewalAt = null, ?CarbonInterface $graceUntil = null, ?string $notes = null, string $source = 'manual'): CompanyLicense
    {
        abort_unless($actor->isPlatformAdmin(), 403);
        $license = $this->ensure($company);
        if ($expiresAt->isPast() || ($license->expires_at && $expiresAt->lessThanOrEqualTo($license->expires_at))) {
            throw ValidationException::withMessages(['expires_at' => 'La renovación debe extender el vencimiento actual y quedar en el futuro.']);
        }
        if ($graceUntil && $graceUntil->lessThan($expiresAt)) {
            throw ValidationException::withMessages(['grace_until' => 'El fin de gracia no puede ser anterior al vencimiento.']);
        }

        return $this->transition($license, 'active', $actor, $notes, 'renewal_'.$source, [
            'starts_at' => $license->starts_at ?? now(),
            'expires_at' => $expiresAt,
            'next_renewal_at' => $nextRenewalAt,
            'grace_until' => $graceUntil,
        ]);
    }

    public function updateModules(Company $company, User $actor, array $enabledModules): void
    {
        abort_unless($actor->isPlatformAdmin(), 403);

        $unknownModules = array_diff($enabledModules, array_keys(ModuleRegistry::MODULES));
        if ($unknownModules !== []) {
            throw ValidationException::withMessages([
                'modules' => 'La selección contiene módulos no reconocidos.',
            ]);
        }

        DB::transaction(function () use ($company, $actor, $enabledModules) {
            foreach (array_keys(ModuleRegistry::MODULES) as $moduleKey) {
                $company->modules()->updateOrCreate(
                    ['module_key' => $moduleKey],
                    ['is_enabled' => in_array($moduleKey, $enabledModules, true)],
                );
            }

            $license = $this->ensure($company);
            $license->events()->create([
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'action' => 'modules',
                'from_status' => $license->status,
                'to_status' => $license->status,
                'snapshot' => [
                    ...$license->only(['status', 'plan', 'user_limit', 'branch_limit']),
                    'modules' => array_values($enabledModules),
                ],
                'notes' => 'Contrato de módulos actualizado.',
            ]);
        });
    }

    public function savePlan(?LicensePlan $plan, User $actor, array $attributes): LicensePlan
    {
        abort_unless($actor->isPlatformAdmin(), 403);

        $attributes['modules'] = array_values(array_unique($attributes['modules']));
        $attributes[$plan ? 'updated_by' : 'created_by'] = $actor->id;

        if ($plan) {
            $plan->update($attributes);

            return $plan->fresh();
        }

        return LicensePlan::create($attributes);
    }

    public function applyPlan(Company $company, LicensePlan $plan, User $actor, array $overrides = []): CompanyLicense
    {
        abort_unless($actor->isPlatformAdmin(), 403);
        abort_unless($plan->is_active, 422);

        $contract = array_merge([
            'license_plan_id' => $plan->id,
            'plan' => $plan->name,
            'branch_limit' => $plan->branch_limit,
            'user_limit' => $plan->user_limit,
        ], $overrides);
        $status = $contract['status'] ?? $this->ensure($company)->status;
        unset($contract['status'], $contract['modules']);

        return DB::transaction(function () use ($company, $plan, $actor, $contract, $status, $overrides) {
            $license = $this->transition($this->ensure($company), $status, $actor, $overrides['notes'] ?? null, 'plan_applied', $contract);
            $this->updateModules($company, $actor, $overrides['modules'] ?? $plan->modules);

            return $license->fresh();
        });
    }

    public function assertCapacity(Company $company, string $resource): void
    {
        $license = $this->refresh($this->ensure($company));
        $limit = $resource === 'users' ? $license->user_limit : $license->branch_limit;
        $used = $resource === 'users' ? $company->users()->count() : $company->branches()->count();
        if ($limit !== null && $used >= $limit) {
            throw ValidationException::withMessages([$resource => "La licencia alcanzó el límite de {$resource} ({$limit})."]);
        }
    }
}
