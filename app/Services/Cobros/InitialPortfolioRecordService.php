<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosInitialPortfolioCorrectionRequest;
use App\Models\CobrosInitialPortfolioEvent;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bloque 5B — alta de cliente/crédito mientras la migración 5A esté EN PROGRESO.
 *
 * Integridad: tenant estricto; reutiliza el Customer del mismo tenant por
 * identificación (nunca otro tenant, jamás lo modifica); marca de por vida
 * source=cartera_inicial y verification=informacion_declarada con actor/fecha.
 * No toca CxC, pagos ni recibos existentes.
 */
class InitialPortfolioRecordService
{
    public function __construct(private readonly InitialPortfolioMigrationService $migrations) {}

    /** Solo se carga con la migración DEL COBRADOR abierta; finalizada/no existe => 403. */
    public function assertOpen(Company $company, User $collector): CobrosInitialPortfolioMigration
    {
        if ($collector->hasPermission('cobros.portal_dueno', $company)) {
            abort(403, 'La carga inicial pertenece al cobrador, no al prestamista.');
        }

        $migration = $this->migrations->migrationFor($company, $collector);
        abort_unless($migration !== null && ! $migration->isFinalized(), 403, 'La migración de cartera inicial no está abierta.');

        return $migration;
    }

    public function countFor(Company $company, User $collector): int
    {
        $migration = $this->migrations->migrationFor($company, $collector);

        return $migration === null
            ? 0
            : CobrosInitialPortfolioRecord::query()->where('migration_id', $migration->id)->count();
    }

    /**
     * Crea (o reutiliza en el MISMO tenant) el cliente y registra el crédito
     * declarado. Transacción: cliente + registro atómicos; sin eventos de pagos.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, User $user, array $data): CobrosInitialPortfolioRecord
    {
        $migration = $this->assertOpen($company, $user);

        $identification = trim((string) $data['identification']);
        $local = Customer::query()->where('company_id', $company->id)
            ->where('identification', $identification)->first();

        if (! $local) {
            // Identificación ajena al tenant: no se reutiliza ni se duplica jamás.
            if (Customer::query()->where('identification', $identification)->exists()) {
                throw ValidationException::withMessages([
                    'identification' => 'Esa identificación ya está registrada.',
                ]);
            }
        }

        return DB::transaction(function () use ($company, $user, $data, $migration, $identification, $local) {
            $customerReused = $local !== null;

            if (! $local) {
                $local = Customer::create([
                    'company_id' => $company->id,
                    'customer_type' => 'individual',
                    'identification' => $identification,
                    // Mapeo al enum existente del Customer: 01 cédula, 03 DIMEX, 05 pasaporte.
                    'identification_type' => ['cedula' => '01', 'dimex' => '03', 'pasaporte' => '05'][$data['identification_type']] ?? '01',
                    'name' => trim((string) $data['name']),
                    'phone' => trim((string) $data['phone']),
                    'address' => $data['address'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'is_active' => true,
                ]);
            }

            $already = CobrosInitialPortfolioRecord::query()
                ->where('migration_id', $migration->id)
                ->where('customer_id', $local->id)->exists();
            if ($already) {
                throw ValidationException::withMessages([
                    'identification' => 'Este cliente ya fue cargado en la migración.',
                ]);
            }

            $record = CobrosInitialPortfolioRecord::create([
                'migration_id' => $migration->id,
                'company_id' => $company->id,
                'customer_id' => $local->id,
                'identification_type' => $data['identification_type'],
                'source' => CobrosInitialPortfolioRecord::SOURCE_INITIAL,
                'verification' => CobrosInitialPortfolioRecord::VERIFICATION_DECLARED,
                'alt_contact_name' => $data['alt_contact_name'] ?? null,
                'alt_contact_phone' => $data['alt_contact_phone'] ?? null,
                'original_amount' => $this->money($data['original_amount'] ?? null),
                'balance' => $this->money($data['balance']),
                'installment' => $this->money($data['installment']),
                'frequency' => $data['frequency'],
                'next_charge_date' => $data['next_charge_date'],
                'prior_credits' => isset($data['prior_credits']) && $data['prior_credits'] !== '' ? (int) $data['prior_credits'] : null,
                'declared_behavior' => $data['declared_behavior'],
                'historical_note' => $data['historical_note'] ?? null,
                'customer_reused' => $customerReused,
                'migrated_by_user_id' => $user->id,
                'migrated_at' => now(),
            ]);

            // La autoridad de "Mi cartera" es cobros_assignments: cada cliente
            // migrado queda asignado al MISMO cobrador que lo cargó, en la ruta
            // activa de la sesión (respetando tenant/actividad/pivot). Se reutiliza
            // el servicio existente de asignaciones (idempotente) DENTRO de la
            // misma transacción que el registro.
            $routeId = $this->activeRouteId($company, $user);
            app(PortfolioAssignmentService::class)->assign(
                $company,
                $local,
                $user,
                $user,
                'Carga de cartera inicial (migración 5B)',
                $routeId
            );

            // Crédito declarado con saldo > 0 => UNA CxC operativa real en la
            // misma ruta. Es lo que hace visible el saldo y el botón COBRAR en
            // "Mi cartera". Sin pagos, recibos ni historial ficticio.
            $this->createReceivable($company, $user, $local, $record, $routeId);

            return $record;
        });
    }

    /**
     * CxC única e idempotente (reintento no duplica: clave por registro 5B).
     *
     * Compatibilidad técnica de NOT NULL por legado: branch_id se rellena con
     * la primera sucursal de la empresa solo para satisfacer el esquema; la
     * sucursal NO decide autoridad, ruta ni cartera en CobrOS (esa es
     * cobros_route_id) y no se muestra como "Sucursal" en la interfaz CobrOS.
     */
    private function createReceivable(Company $company, User $user, Customer $customer, CobrosInitialPortfolioRecord $record, ?int $routeId): void
    {
        $balance = (string) $record->balance;
        if (bccomp($balance, '0', 4) <= 0) {
            return; // Sin saldo declarado no existe crédito operativo que crear.
        }

        $branch = $company->branches()->orderBy('id')->first();
        abort_if($branch === null, 422, 'La empresa no tiene sucursal para registrar el crédito migrado.');

        // sale_id NULL: crédito declarado sin venta (la FK es nullable desde 000007).
        $existing = AccountReceivable::query()->forCompany($company->id)
            ->where('customer_id', $customer->id)->whereNull('sale_id')->first();
        if ($existing !== null) {
            return; // Reintento: la CxC declarada ya existe, no se duplica.
        }

        AccountReceivable::create([
                'company_id' => $company->id, 'branch_id' => $branch->id, 'cobros_route_id' => $routeId,
                'customer_id' => $customer->id, 'issued_at' => today(),
                // Vencimiento real declarado (next_charge_date), nunca inventado.
                'due_date' => $record->next_charge_date?->toDateString() ?? today()->toDateString(),
                'original_amount' => (string) ($record->original_amount ?? $record->balance),
                'balance_due' => $balance,
                'status' => AccountReceivable::STATUS_PENDING,
                'currency_code' => $company->currency,
            ]
        );
    }

    /** Ruta activa validada en tenant + pivot activo del migrador (nunca arbitrary). */
    private function activeRouteId(Company $company, User $user): ?int
    {
        $routeId = (int) session('active_cobros_route_id', 0);
        if ($routeId <= 0) {
            return null;
        }

        $valid = CobrosRoute::forCompany($company->id)->whereKey($routeId)->where('active', true)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id)
                ->where('cobros_route_user.active', true)
                ->where('cobros_route_user.company_id', $company->id))
            ->exists();

        return $valid ? $routeId : null;
    }

    /** Decimal exacto de 4 posiciones con BCMath (nunca float). */
    private function money(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return bcadd(trim((string) $value), '0', 4);
    }

    /**
     * SOLICITAR CORRECCIÓN de un dato congelado: jamás modifica el registro;
     * solo crea la solicitud con valor original + solicitado + motivo + actor.
     */
    public function requestCorrection(Company $company, User $collector, CobrosInitialPortfolioRecord $record, array $data): CobrosInitialPortfolioCorrectionRequest
    {
        $migration = $this->migrations->migrationFor($company, $collector);
        abort_unless($migration?->isFinalized(), 403, 'Las correcciones aplican después de finalizar.');
        abort_unless(
            (int) $record->company_id === (int) $company->id && (int) $record->migration_id === (int) $migration->id,
            404
        );

        $field = $data['field'];
        $original = (string) $record->{$field};

        return CobrosInitialPortfolioCorrectionRequest::create([
            'migration_id' => $migration->id,
            'record_id' => $record->id,
            'company_id' => $company->id,
            'collector_user_id' => $collector->id,
            'field' => $field,
            'original_value' => mb_substr($original, 0, 60),
            'requested_value' => mb_substr(trim((string) $data['requested_value']), 0, 60),
            'reason' => mb_substr(trim((string) $data['reason']), 0, 250),
            'status' => CobrosInitialPortfolioCorrectionRequest::STATUS_PENDING,
            'requested_by_user_id' => $collector->id,
            'requested_at' => now(),
        ]);
    }

    /**
     * Resolución del prestamista: rechaza (sin cambios) o aprueba aplicando el
     * nuevo valor de forma auditable. El valor original queda en la solicitud y
     * en el evento append-only; nunca se reescriben pagos posteriores.
     */
    public function resolveCorrection(Company $company, User $owner, CobrosInitialPortfolioCorrectionRequest $correction, string $decision): CobrosInitialPortfolioCorrectionRequest
    {
        if (! $owner->hasPermission('cobros.portal_dueno', $company)) {
            abort(403, 'Solo el prestamista resuelve correcciones.');
        }
        abort_unless((int) $correction->company_id === (int) $company->id, 404);
        abort_unless($correction->status === CobrosInitialPortfolioCorrectionRequest::STATUS_PENDING, 409, 'La solicitud ya fue resuelta.');
        abort_unless(in_array($decision, ['aprobar', 'rechazar'], true), 422, 'Decisión inválida.');

        $approved = $decision === 'aprobar';
        $correction->update([
            'status' => $approved
                ? CobrosInitialPortfolioCorrectionRequest::STATUS_APPROVED
                : CobrosInitialPortfolioCorrectionRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $owner->id,
            'reviewed_at' => now(),
            'applied_at' => $approved ? now() : null,
        ]);

        if ($approved) {
            $record = $correction->record()->firstOrFail();
            $normalized = in_array($correction->field, ['balance', 'installment', 'original_amount'], true)
                ? $this->money($correction->requested_value)
                : $correction->requested_value;
            $record->update([$correction->field => $normalized]);

            CobrosInitialPortfolioEvent::create([
                'migration_id' => $correction->migration_id,
                'company_id' => $correction->company_id,
                'type' => CobrosInitialPortfolioEvent::TYPE_CORRECTION,
                'actor_user_id' => $owner->id,
                'reason' => mb_substr(
                    $correction->field.': '.$correction->original_value.' -> '.$correction->requested_value
                        .' | '.$correction->reason,
                    0,
                    250
                ),
                'occurred_at' => now(),
            ]);
        }

        return $correction->refresh();
    }
}
