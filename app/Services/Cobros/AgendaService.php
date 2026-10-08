<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosAssignment;
use App\Models\CobrosVisit;
use App\Models\CobrosVisitLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Bloque 6 — Agenda automática del cobrador.
 *
 * La recurrencia OPERATIVA (diario/semanal/quincenal/mensual) se calcula bajo
 * demanda: sin cron, sin filas masivas y de forma determinista/idempotente.
 * La fecha financiera (AccountReceivable.due_date), saldos, mora y condiciones
 * del crédito JAMÁS se modifican aquí. Autoridad: empresa activa + auth()->id()
 * + CobrosRoute activa (Branch nunca es autorización).
 */
class AgendaService
{
    public const TABS = ['hoy', 'manana', 'semana', 'todos'];

    public const TAB_LABELS = [
        'hoy' => 'HOY',
        'manana' => 'MAÑANA',
        'semana' => 'ESTA SEMANA',
        'todos' => 'TODOS',
    ];

    /** Horizonte acotado de la pestaña TODOS (nunca genera calendario infinito). */
    private const TODOS_DAYS = 30;

    public function __construct(private readonly VisitService $visits) {}

    /** Rango [start,end] en Y-m-d para cada pestaña (timezone de la empresa). */
    public function rangeFor(string $tab, Company $company): array
    {
        $today = CarbonImmutable::today($company->timezone ?: config('app.timezone'));

        return match ($tab) {
            'manana' => [$today->addDay()->toDateString(), $today->addDay()->toDateString()],
            'semana' => [$today->toDateString(), $today->endOfWeek()->toDateString()],
            'todos' => [$today->toDateString(), $today->addDays(self::TODOS_DAYS)->toDateString()],
            default => [$today->toDateString(), $today->toDateString()],
        };
    }

    /**
     * Ocurrencias deterministas de la recurrencia dentro del rango (≤ ~31 fechas).
     * - diario: cada día calendario (sin "día hábil").
     * - semanal: mismos weekday configurado.
     * - quincenal: FECHA ANCLA + múltiplos de 15 días (nunca asume 1/15).
     * - mensual: mismo día del mes; si el mes no lo contiene, último día válido.
     */
    public function occurrencesFor(CobrosAssignment $assignment, string $start, string $end): Collection
    {
        $dates = collect();
        $s = CarbonImmutable::parse($start);
        $e = CarbonImmutable::parse($end);
        if ($e->lt($s)) {
            return $dates;
        }

        switch ($assignment->visit_frequency) {
            case 'diario':
                for ($d = $s; $d->lte($e); $d = $d->addDay()) {
                    $dates->push($d->toDateString());
                }
                break;

            case 'semanal':
                if ($assignment->visit_weekday === null) {
                    break;
                }
                $d = $s;
                while ($d->lte($e) && $d->dayOfWeek !== (int) $assignment->visit_weekday) {
                    $d = $d->addDay();
                }
                while ($d->lte($e)) {
                    $dates->push($d->toDateString());
                    $d = $d->addDays(7);
                }
                break;

            case 'quincenal':
                if ($assignment->next_visit_date === null) {
                    break;
                }
                $anchor = $assignment->next_visit_date->startOfDay();
                $k = (int) floor(($s->timestamp - $anchor->timestamp) / (15 * 86400));
                $d = $anchor->addDays(15 * $k);
                while ($d->lt($s)) {
                    $d = $d->addDays(15);
                }
                while ($d->lte($e)) {
                    $dates->push($d->toDateString());
                    $d = $d->addDays(15);
                }
                break;

            case 'mensual':
                if ($assignment->visit_month_day === null) {
                    break;
                }
                $month = $s->startOfMonth();
                while ($month->lte($e)) {
                    $day = min((int) $assignment->visit_month_day, $month->daysInMonth);
                    $candidate = $month->addDays($day - 1);
                    if ($candidate->gte($s) && $candidate->lte($e)) {
                        $dates->push($candidate->toDateString());
                    }
                    $month = $month->addMonthNoOverflow()->startOfMonth();
                }
                break;
        }

        return $dates;
    }

    /** Siguiente fecha operativa estrictamente posterior al puntero, por configuración. */
    public function nextAfter(CobrosAssignment $assignment): ?string
    {
        $current = $assignment->next_visit_date;
        if ($current === null) {
            return null;
        }

        switch ($assignment->visit_frequency) {
            case 'diario':
                return $current->addDay()->toDateString();
            case 'semanal':
                if ($assignment->visit_weekday === null) {
                    return $current->addDays(7)->toDateString();
                }
                $d = $current->addDay();
                while ($d->dayOfWeek !== (int) $assignment->visit_weekday) {
                    $d = $d->addDay();
                }
                return $d->toDateString();
            case 'quincenal':
                return $current->addDays(15)->toDateString();
            case 'mensual':
                if ($assignment->visit_month_day === null) {
                    return $current->addMonthNoOverflow()->toDateString();
                }
                $m = $current->addMonthNoOverflow()->startOfMonth();
                $day = min((int) $assignment->visit_month_day, $m->daysInMonth);
                return $m->addDays($day - 1)->toDateString();
        }

        return null;
    }

    /**
     * Al completar/procesar una visita: avanza el puntero next_visit_date hasta ser
     * estrictamente posterior a hoy (idempotente, límite de seguridad).
     * NO se invoca al reprogramar (la excepción no desplaza la recurrencia base).
     */
    public function advanceAfterProcessing(User $collector, Company $company, Customer $customer): void
    {
        $assignment = CobrosAssignment::query()->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->where('customer_id', $customer->id)
            ->whereNotNull('visit_frequency')
            ->whereNotNull('next_visit_date')
            ->first();
        if ($assignment === null) {
            return;
        }

        $today = CarbonImmutable::today($company->timezone ?: config('app.timezone'));
        $guard = 0;
        while ($assignment->next_visit_date !== null
            && ! $assignment->next_visit_date->gt($today)
            && $guard++ < 400) {
            $next = $this->nextAfter($assignment);
            if ($next === null) {
                break;
            }
            $assignment->next_visit_date = $next;
        }

        if ($assignment->isDirty('next_visit_date')) {
            $assignment->save();
        }
    }

    /**
     * Reprogramación de UNA visita desde la Agenda: crea la fila de origen SOLO si
     * hace falta (transacción + idempotencia) y reutiliza VisitService::reschedule.
     * No modifica la frecuencia base ni nada financiero. 404 si el cliente no está
     * en la cartera del cobrador autenticado (sin IDOR).
     */
    public function rescheduleOccurrence(User $collector, Company $company, Customer $customer, string $originDate, string $newDate): CobrosVisit
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($collector, $company, $customer, $originDate, $newDate) {
            $base = CobrosVisit::query()->forCompany($company->id)
                ->where('collector_user_id', $collector->id)
                ->where('customer_id', $customer->id);

            $originDone = (clone $base)->where('status', CobrosVisit::STATUS_RESCHEDULED)
                ->whereDate('scheduled_for', $originDate)
                ->orderBy('id')->lockForUpdate()->first();
            $target = (clone $base)->where('status', CobrosVisit::STATUS_PENDING)
                ->whereDate('scheduled_for', $newDate)
                ->orderBy('id')->lockForUpdate()->first();

            // Idempotencia: origen ya reprogramado CON visita destino pendiente.
            if ($originDone !== null && $target !== null) {
                return $target;
            }

            if ($originDone !== null) {
                // Origen ya materializado y marcado: solo falta el destino (sin duplicar origen).
                return $this->visits->reschedule($collector, $company, $originDone, ['scheduled_for' => $newDate]);
            }

            $pending = (clone $base)->where('status', CobrosVisit::STATUS_PENDING)
                ->whereDate('scheduled_for', $originDate)
                ->orderBy('id')->lockForUpdate()->first();
            // Crear la visita de origen solo cuando es necesaria (autoridad 2B incluida).
            $visit = $pending ?? $this->visits->schedule($collector, $company, $customer, ['scheduled_for' => $originDate]);

            if ($target !== null) {
                // El destino YA existe (otra cadena): se materializa y marca el origen
                // como reprogramada SIN duplicar la visita destino (dedup cliente+fecha).
                $visit->update(['status' => CobrosVisit::STATUS_RESCHEDULED]);
                CobrosVisitLog::create([
                    'company_id' => $company->id,
                    'visit_id' => $visit->id,
                    'from_status' => CobrosVisit::STATUS_PENDING,
                    'to_status' => CobrosVisit::STATUS_RESCHEDULED,
                    'actor_user_id' => $collector->id,
                    'notes' => 'Reprogramada',
                ]);

                return $target;
            }

            return $this->visits->reschedule($collector, $company, $visit, ['scheduled_for' => $newDate]);
        });
    }

    /**
     * Agenda bajo demanda para la pestaña elegida: recurrencia (configuración
     * operativa) + visitas/promesas reales, deduplicadas por cliente+fecha.
     * Aislamiento estricto: company + collector + assignments de la ruta activa.
     */
    public function agenda(User $collector, Company $company, string $tab): array
    {
        if (! in_array($tab, self::TABS, true)) {
            $tab = 'hoy';
        }
        [$start, $end] = $this->rangeFor($tab, $company);
        $routeId = (int) session('active_cobros_route_id', 0);
        $routeId = $routeId > 0 ? $routeId : null;

        // Mismo filtro de ruta que la cartera: ruta activa o assignments legados sin ruta.
        $assignments = CobrosAssignment::query()->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->when($routeId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('cobros_route_id', $routeId)
                ->orWhereNull('cobros_route_id')))
            ->with('customer')
            ->get();

        $allowed = [];
        foreach ($assignments as $assignment) {
            $customer = $assignment->customer;
            if ($customer === null || ! $customer->is_active) {
                continue;
            }
            if (! isset($allowed[$assignment->customer_id])) {
                $allowed[$assignment->customer_id] = [
                    'customer' => $customer,
                    'assignment' => $assignment,
                    'route' => $assignment->cobrosRoute?->name,
                ];
            }
        }

        // 1) Excepciones reales de reprogramación X→Y (mismo company+collector+
        // cliente ya restringido por ruta): la serie virtual NO renderiza ninguna
        // ocurrencia V con X <= V < Y. Y sí se muestra y la serie posterior a Y
        // queda intacta. No escribe nada en BD.
        $exceptions = [];
        $origins = CobrosVisit::query()->forCompany($company->id)
            ->forCollector($collector->id)
            ->whereIn('customer_id', array_keys($allowed))
            ->where('status', CobrosVisit::STATUS_RESCHEDULED)
            ->whereNotNull('scheduled_for')
            ->get(['id', 'customer_id', 'scheduled_for']);
        if ($origins->isNotEmpty()) {
            $targets = CobrosVisit::query()->forCompany($company->id)
                ->forCollector($collector->id)
                ->whereIn('rescheduled_from_visit_id', $origins->pluck('id'))
                ->whereNotNull('scheduled_for')
                ->get(['id', 'rescheduled_from_visit_id', 'scheduled_for'])
                ->keyBy('rescheduled_from_visit_id');
            foreach ($origins as $origin) {
                $target = $targets->get($origin->id);
                if ($target === null) {
                    continue;
                }
                $x = $origin->scheduled_for->toDateString();
                $y = $target->scheduled_for->toDateString();
                if ($y > $x) {
                    $exceptions[(int) $origin->customer_id][] = [$x, $y];
                }
            }
        }

        // 2) Recurrencia calculada (sin crear filas): un evento = una tarjeta.
        $items = [];
        $suppressedDates = [];
        foreach ($allowed as $customerId => $meta) {
            $assignment = $meta['assignment'];
            if ($assignment->visit_frequency === null) {
                continue;
            }
            foreach ($this->occurrencesFor($assignment, $start, $end) as $date) {
                $suppressed = false;
                foreach ($exceptions[$customerId] ?? [] as [$x, $y]) {
                    if ($date >= $x && $date < $y) {
                        $suppressed = true;
                        break;
                    }
                }
                if ($suppressed) {
                    $suppressedDates[$customerId][] = $date;
                    continue;
                }
                $key = $customerId.'|'.$date;
                if (! isset($items[$key])) {
                    $items[$key] = [
                        'date' => $date,
                        'customer' => $meta['customer'],
                        'route' => $meta['route'],
                        'visit' => null,
                        'kind' => 'recurrencia',
                    ];
                }
            }
        }

        // 2) Visitas reales del rango + promesas por promise_date (historial conservado).
        $visits = CobrosVisit::query()->forCompany($company->id)
            ->forCollector($collector->id)
            ->whereIn('customer_id', array_keys($allowed))
            ->with('customer')
            ->get()
            ->filter(function (CobrosVisit $visit) use ($start, $end) {
                $date = $visit->status === CobrosVisit::STATUS_PROMISE && $visit->promise_date
                    ? $visit->promise_date->toDateString()
                    : $visit->scheduled_for?->toDateString();

                return $date !== null && $date >= $start && $date <= $end;
            });

        foreach ($visits as $visit) {
            $isPromise = $visit->status === CobrosVisit::STATUS_PROMISE && $visit->promise_date;
            $date = $isPromise ? $visit->promise_date->toDateString() : $visit->scheduled_for->toDateString();
            $key = $visit->customer_id.'|'.$date;

            // Ocurrencia base materializada y marcada reprogramada: la excepción NO se
            // renderiza en la agenda operativa (el historial sigue intacto en BD) y la
            // recurrencia virtual de esa fecha se suprime. La visita destino sí aparece.
            if ($visit->status === CobrosVisit::STATUS_RESCHEDULED) {
                // El origen de la cadena que ya suprimió su virtual vuelve a la
                // agenda solo si su fecha NO era ocurrencia de la serie.
                if (in_array($date, $suppressedDates[(int) $visit->customer_id] ?? [], true)) {
                    continue;
                }
                $virtual = $items[$key] ?? null;
                if ($virtual !== null && $virtual['visit'] === null && $virtual['kind'] === 'recurrencia') {
                    unset($items[$key]);
                } elseif ($virtual === null) {
                    // Fecha real fuera de la recurrencia (dato manual): se conserva tal cual.
                    $items[$key] = [
                        'date' => $date,
                        'customer' => $allowed[$visit->customer_id]['customer'],
                        'route' => $allowed[$visit->customer_id]['route'],
                        'visit' => $visit,
                        'kind' => 'visita',
                    ];
                }
                continue;
            }

            if (isset($items[$key])) {
                // Mismo evento real (cliente+fecha): UNA sola tarjeta con la visita unida.
                $items[$key]['visit'] = $visit;
                $items[$key]['kind'] = $isPromise ? 'promesa' : 'visita';
                continue;
            }
            $items[$key] = [
                'date' => $date,
                'customer' => $allowed[$visit->customer_id]['customer'],
                'route' => $allowed[$visit->customer_id]['route'],
                'visit' => $visit,
                'kind' => $isPromise ? 'promesa' : 'visita',
            ];
        }

        // 3) Saldo pendiente por cliente (solo lectura, BCMath).
        $balances = [];
        if ($items !== []) {
            $open = AccountReceivable::query()->forCompany($company->id)
                ->whereIn('customer_id', array_keys($allowed))
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
                ->get(['customer_id', 'balance_due']);
            foreach ($open as $account) {
                $cid = (int) $account->customer_id;
                $balances[$cid] = bcadd($balances[$cid] ?? '0.0000', (string) $account->balance_due, 4);
            }
        }

        $rows = [];
        foreach ($items as $key => $item) {
            $item['balance'] = $balances[(int) $item['customer']->id] ?? '0.0000';
            $rows[$key] = $item;
        }
        uksort($rows, function ($a, $b) use ($rows) {
            return [$rows[$a]['date'], $rows[$a]['customer']->name] <=> [$rows[$b]['date'], $rows[$b]['customer']->name];
        });

        return [
            'tab' => $tab,
            'start' => $start,
            'end' => $end,
            'items' => array_values($rows),
        ];
    }
}

