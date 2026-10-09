<?php

namespace App\Services\Cobros;

use App\Models\CobrosVisit;
use App\Models\CobrosVisitLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fase 2B — Agenda diaria y gestión de visitas.
 *
 * Autoridad SIEMPRE: empresa activa de la sesión + auth()->id() como cobrador.
 * Nunca se acepta collector_id del request. 2B NO toca dinero: AccountReceivable,
 * balance_due y pagos jamás se modifican; promise_amount es SOLO informativo.
 */
class VisitService
{
    public function schedule(User $collector, Company $company, Customer $customer, array $data): CobrosVisit
    {
        $this->assertAssignedCustomer($collector, $company, $customer);

        $scheduledFor = $this->date($data['scheduled_for'] ?? null, 'scheduled_for', true);
        // Programar SOLO crea visita pendiente: el estado/promesa del navegador se ignora.
        $status = CobrosVisit::STATUS_PENDING;

        return DB::transaction(function () use ($collector, $company, $customer, $scheduledFor, $status, $data) {
            $visit = CobrosVisit::create([
                'company_id' => $company->id,
                'branch_id' => null,
                'customer_id' => $customer->id,
                'collector_user_id' => $collector->id,
                'scheduled_for' => $scheduledFor,
                'status' => $status,
                'visited_at' => null,
                'promise_date' => null,
                'promise_amount' => null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->log($company, $visit, null, $status, $collector, $data['notes'] ?? null);

            return $visit;
        });
    }

    public function record(User $collector, Company $company, CobrosVisit $visit, array $data): CobrosVisit
    {
        $this->assertOwnVisit($collector, $company, $visit);

        $status = $data['status'] ?? $visit->status;
        $this->assertStatus($status, $data);

        return DB::transaction(function () use ($collector, $company, $visit, $status, $data) {
            if ($status === CobrosVisit::STATUS_RESCHEDULED) {
                return $this->reschedule($collector, $company, $visit, $data);
            }

            $from = $visit->status;
            $lat = $data['latitude'] ?? null;
            $lng = $data['longitude'] ?? null;
            $hasSnapshot = $lat !== null && $lat !== '' && $lng !== null && $lng !== '';
            $visit->update([
                'status' => $status,
                'visited_at' => in_array($status, [CobrosVisit::STATUS_VISITED, CobrosVisit::STATUS_NOT_FOUND, CobrosVisit::STATUS_PROMISE], true)
                    ? CarbonImmutable::today($company->timezone ?: config('app.timezone'))->toDateString()
                    : $visit->visited_at,
                'promise_date' => $status === CobrosVisit::STATUS_PROMISE ? $this->date($data['promise_date'] ?? null, 'promise_date', true) : null,
                'promise_amount' => $status === CobrosVisit::STATUS_PROMISE && isset($data['promise_amount']) && $data['promise_amount'] !== null && $data['promise_amount'] !== ''
                    ? (string) $data['promise_amount']
                    : null,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $visit->notes,
                // Snapshot GPS opcional: evidencia histórica de la gestión; jamás escribe Customer.
                'latitude' => $hasSnapshot ? (string) $lat : $visit->latitude,
                'longitude' => $hasSnapshot ? (string) $lng : $visit->longitude,
                'location_captured_at' => $hasSnapshot ? now() : $visit->location_captured_at,
            ]);

            if ($from !== $status) {
                $this->log($company, $visit, $from, $status, $collector, $data['notes'] ?? null);
            }

            return $visit->fresh();
        });
    }

    /**
     * Reprogramación trazada: la visita original queda `reprogramada` y nace una
     * nueva pendiente con la fecha explícita. El historial nunca se borra.
     */
    public function reschedule(User $collector, Company $company, CobrosVisit $visit, array $data): CobrosVisit
    {
        $this->assertOwnVisit($collector, $company, $visit);

        $newDate = $this->date($data['scheduled_for'] ?? null, 'scheduled_for', true);

        return DB::transaction(function () use ($collector, $company, $visit, $newDate, $data) {
            $from = $visit->status;
            $visit->update(['status' => CobrosVisit::STATUS_RESCHEDULED]);

            $this->log($company, $visit, $from, CobrosVisit::STATUS_RESCHEDULED, $collector, $data['notes'] ?? 'Reprogramada');

            $new = CobrosVisit::create([
                'company_id' => $company->id,
                'branch_id' => $visit->branch_id,
                'customer_id' => $visit->customer_id,
                'collector_user_id' => $collector->id,
                'scheduled_for' => $newDate,
                'status' => CobrosVisit::STATUS_PENDING,
                'rescheduled_from_visit_id' => $visit->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->log($company, $new, null, CobrosVisit::STATUS_PENDING, $collector, 'Nace de reprogramación');

            return $new;
        });
    }

    /**
     * Gestión rápida desde la ficha de Mi Cartera: reutiliza la visita pendiente
     * propia del cliente (o crea la de hoy con schedule()) y registra con las
     * MISMAS reglas, estados y bitácora de 2B. Sin estados nuevos; no toca dinero.
     */
    public function manage(User $collector, Company $company, Customer $customer, array $data): CobrosVisit
    {
        $this->assertAssignedCustomer($collector, $company, $customer);

        $visit = CobrosVisit::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->where('collector_user_id', $collector->id)
            ->where('status', CobrosVisit::STATUS_PENDING)
            ->orderByDesc('scheduled_for')->orderByDesc('id')
            ->first();

        if (! $visit) {
            $visit = $this->schedule($collector, $company, $customer, [
                'scheduled_for' => CarbonImmutable::today($company->timezone ?: config('app.timezone'))->toDateString(),
            ]);
        }

        return $this->record($collector, $company, $visit, $data);
    }

    /** Agenda de hoy: SOLO visitas programadas para hoy del cobrador autenticado. */
    public function today(User $collector, Company $company)
    {
        $today = CarbonImmutable::today($company->timezone ?: config('app.timezone'))->toDateString();

        return CobrosVisit::query()->forCompany($company->id)
            ->forCollector($collector->id)
            ->whereDate('scheduled_for', $today)
            ->with(['customer', 'branch'])
            ->orderBy('scheduled_for')->orderBy('id')
            ->get();
    }

    /** Historial cronológico (más reciente primero) de un cliente de la cartera. */
    public function historyFor(User $collector, Company $company, Customer $customer)
    {
        $this->assertAssignedCustomer($collector, $company, $customer);

        return CobrosVisit::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->with(['collector', 'branch'])
            ->orderByDesc('scheduled_for')->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * Al reasignar cartera NO se transfiere silenciosamente ninguna visita pendiente:
     * la del cobrador anterior queda `reprogramada` (historial preservado) y el nuevo
     * cobrador empieza de cero.
     */
    public function closePendingForCustomer(Customer $customer, User $actor, string $reason): void
    {
        CobrosVisit::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('status', CobrosVisit::STATUS_PENDING)
            ->get()
            ->each(function (CobrosVisit $visit) use ($actor, $reason) {
                DB::transaction(function () use ($visit, $actor, $reason) {
                    $visit->update(['status' => CobrosVisit::STATUS_RESCHEDULED]);
                    $this->log($visit->company, $visit, CobrosVisit::STATUS_PENDING, CobrosVisit::STATUS_RESCHEDULED, $actor, $reason);
                });
            });
    }

    // --------------------------------------------------------

    private function assertAssignedCustomer(User $collector, Company $company, Customer $customer): void
    {
        $sameCompany = (int) $customer->company_id === (int) $company->id;
        // 14B: con ruta activa, la visita solo opera clientes de esa ruta (o sin ruta).
        $routeId = (int) session('active_cobros_route_id', 0);
        $assigned = $sameCompany && \App\Models\CobrosAssignment::query()->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->where('customer_id', $customer->id)
            ->when($routeId > 0, fn ($q) => $q->where(fn ($w) => $w
                ->where('cobros_route_id', $routeId)
                ->orWhereNull('cobros_route_id')))
            ->exists();

        abort_unless($assigned, 404);
    }

    private function assertOwnVisit(User $collector, Company $company, CobrosVisit $visit): void
    {
        abort_unless(
            (int) $visit->company_id === (int) $company->id
            && (int) $visit->collector_user_id === (int) $collector->id,
            404
        );

        // 14B: la visita de un cliente de otra ruta queda fuera del alcance
        // mientras esa ruta no sea la activa (URL o peticion manipulada).
        $routeId = (int) session('active_cobros_route_id', 0);
        if ($routeId > 0) {
            $onActiveRoute = \App\Models\CobrosAssignment::query()->forCompany($company->id)
                ->where('collector_user_id', $collector->id)
                ->where('customer_id', $visit->customer_id)
                ->where(fn ($q) => $q->where('cobros_route_id', $routeId)->orWhereNull('cobros_route_id'))
                ->exists();
            abort_unless($onActiveRoute, 404);
        }
    }

    private function assertStatus(string $status, array $data): void
    {
        if (! in_array($status, CobrosVisit::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Estado de visita inválido.']);
        }

        if ($status === CobrosVisit::STATUS_PROMISE) {
            $this->date($data['promise_date'] ?? null, 'promise_date', true);
        }
    }

    private function date($value, string $field, bool $required)
    {
        if (($value === null || trim((string) $value) === '') && ! $required) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([$field => 'Fecha inválida.']);
        }
    }

    private function log(Company $company, CobrosVisit $visit, ?string $from, string $to, User $actor, ?string $notes = null): void
    {
        CobrosVisitLog::create([
            'company_id' => $company->id,
            'visit_id' => $visit->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->id,
            'notes' => $notes,
        ]);
    }
}
