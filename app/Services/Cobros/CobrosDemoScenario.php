<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\AccountReceivablePayment;
use App\Models\CobrosAssignment;
use App\Models\CobrosAssignmentLog;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosBureauEvent;
use App\Models\CobrosBureauIdentity;
use App\Models\CobrosBureauIdentityDocument;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosCreditRequestLog;
use App\Models\CobrosRoute;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\CobrosRouteLicense;
use App\Models\CobrosRouteUser;
use App\Models\CobrosVisit;
use App\Models\CobrosVisitLog;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\DemoCompanyProvisioner;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\AccountsReceivableService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * BLOQUE 21D-2 — escenario DEMO de CobrOS con datos 100% sintéticos.
 *
 * Patrón de los DEMO existentes (DemoCompanyProvisioner / DemoTransactionalScenario):
 * generación idempotente + reset explícito, REUTILIZANDO los servicios actuales de
 * licencias, modalidades, asignaciones, créditos y pagos. No duplica lógica de negocio.
 *
 * Seguridad:
 *  - Solo corre en entornos local/testing Y solo sobre la empresa DEMO
 *    (DemoCompanyProvisioner::isDemoCompany); en cualquier otro caso lanza RuntimeException.
 *  - Reset/seed tocan EXCLUSIVAMENTE filas marcadas del escenario (rutas "DEMO Ruta *",
 *    clientes "DEMO *" con identificación 999*, usuarios @demo-cobros.test); nunca
 *    clientes, créditos ni usuarios ajenos.
 *  - Datos sintéticos: identificaciones de 9 dígitos que empiezan por 9 (inválidas como
 *    cédula costarricense, cuyos primeros dígitos van de 0 a 8), teléfonos 5555-000x,
 *    nombres claramente ficticios. Sin fotografías ni documentos de identificación.
 *  - No envía correos, WhatsApp, notificaciones ni integra pagos externos.
 */
class CobrosDemoScenario
{
    /** Dominio sintético de usuarios del escenario (no existe en la realidad). */
    public const USER_DOMAIN = 'demo-cobros.test';

    public const ROUTE_NORTE = 'DEMO Ruta Norte';
    public const ROUTE_SUR = 'DEMO Ruta Sur';
    public const ROUTE_MULTI = 'DEMO Ruta Multi';

    /** Prefijo de clientes del escenario (marcador de reset). */
    public const CUSTOMER_PREFIX = 'DEMO ';

    /** 9 dígitos empezando por 9: imposible como cédula costarricense (primer dígito 0-8). */
    public const IDENTIFICATION_PREFIX = '999';

    public const PRINCIPAL = '50000.0000';
    public const PAYMENT_AMOUNT = '10000.0000';

    public function __construct(
        private readonly CobrosModalityService $modalities,
        private readonly CobrosCreditService $credits,
        private readonly CompanyLicenseService $licenses,
        private readonly CollectorLicenseService $collectorLicenses,
        private readonly PortfolioAssignmentService $assignments,
        private readonly AccountsReceivableService $accounts,
        private readonly DemoCompanyProvisioner $demoCompany,
    ) {}

    /** La empresa existe, es DEMO y el entorno está autorizado. */
    public function assertRunnable(Company $company): void
    {
        if (! CobrosDemoAccess::enabled()) {
            throw new RuntimeException('CobrosDemoScenario requiere habilitación explícita del DEMO en un entorno permitido.');
        }

        if (! $this->demoCompany->isDemoCompany($company)
            && ! str_starts_with((string) $company->trade_name, 'DEMO Visitante')) {
            throw new RuntimeException('CobrosDemoScenario solo opera sobre empresas DEMO.');
        }
    }

    public function isSeeded(Company $company): bool
    {
        return User::query()
            ->where('email', 'like', '%@'.self::USER_DOMAIN)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->exists();
    }

    /**
     * Genera el escenario completo. Idempotente: si ya está sembrado no duplica nada.
     *
     * @return array<string, int|bool>
     */
    public function seed(Company $company): array
    {
        $this->assertRunnable($company);

        if ($this->isSeeded($company)) {
            return $this->summary($company) + ['created' => false];
        }

        $admin = $this->platformAdmin();
        $this->ensureCobrosModule($company, $admin);
        app(PaymentMethodProvisioner::class)->provision($company);

        $branch = $company->branches()->where('is_active', true)->orderBy('id')->first();
        if ($branch === null) {
            throw new RuntimeException('La empresa DEMO necesita al menos una sucursal activa para el escenario CobrOS.');
        }

        $suffix = 'c'.$company->id;
        $owner = $this->scenarioUser($company, "prestamista.administrador.{$suffix}");
        $independiente = $this->scenarioUser($company, "cobrador.independiente.{$suffix}");
        $vinculado = $this->scenarioUser($company, "cobrador.vinculado.{$suffix}");
        // Alias para conservar las llamadas financieras existentes con dos cobradores.
        $multiprestamista = $vinculado;

        // Modalidades: UNA sola fuente de verdad (catálogo/servicio existentes).
        $this->modalities->assign($company, $owner, CobrosModalityCatalog::PRESTAMISTA);
        $this->modalities->assign($company, $independiente, CobrosModalityCatalog::COBRADOR_INDEPENDIENTE);
        $this->modalities->assign($company, $vinculado, CobrosModalityCatalog::COBRADOR_VINCULADO);

        $norte = $this->route($company, self::ROUTE_NORTE, [$independiente->id], $admin);
        $sur = $this->route($company, self::ROUTE_SUR, [$vinculado->id], $admin);
        $multi = $sur;

        $clienteNorte = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Norte Uno', '999000001');
        $clienteNorte2 = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Norte Dos', '999000002');
        $clienteSur = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Sur Uno', '999000003');
        $clienteSur2 = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Sur Dos', '999000004');
        $clienteMulti = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Multi Uno', '999000005');
        $clienteAtraso = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Norte Atraso', '999000006');

        // Asignaciones con el servicio existente (trazabilidad incluida).
        $this->assignments->assign($company, $clienteNorte, $independiente, $owner, 'Escenario DEMO', $norte->id);
        $this->assignments->assign($company, $clienteNorte2, $independiente, $owner, 'Escenario DEMO', $norte->id);
        $this->assignments->assign($company, $clienteSur, $vinculado, $owner, 'Escenario DEMO', $sur->id);
        $this->assignments->assign($company, $clienteSur2, $vinculado, $owner, 'Escenario DEMO', $sur->id);
        $this->assignments->assign($company, $clienteMulti, $multiprestamista, $owner, 'Escenario DEMO', $multi->id);
        $this->assignments->assign($company, $clienteAtraso, $independiente, $owner, 'Escenario DEMO', $norte->id);

        $clientesNorte = [$clienteNorte, $clienteNorte2, $clienteAtraso];
        $clientesSur = [$clienteSur, $clienteSur2, $clienteMulti];
        foreach (range(7, 10) as $number) {
            $clientesNorte[] = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Norte '.$number, sprintf('999%06d', $number));
        }
        foreach (range(11, 14) as $number) {
            $clientesSur[] = $this->customer($company, self::CUSTOMER_PREFIX.'Cliente Sur '.$number, sprintf('999%06d', $number));
        }
        foreach ([[$independiente, $norte, $clientesNorte], [$vinculado, $sur, $clientesSur]] as [$collector, $route, $customers]) {
            foreach ($customers as $index => $customer) {
                $assignment = $this->assignments->assign($company, $customer, $collector, $owner, 'Escenario DEMO', $route->id);
                $assignment->update([
                    'visit_frequency' => 'semanal',
                    'visit_weekday' => ($index + 1) % 7,
                ]);
            }
        }

        // Un préstamo por modalidad de cobro: solicitud → aprobación → desembolso (servicios reales).
        $this->loan($company, $clienteNorte, $independiente, $norte, $owner, '999000001');
        $this->loan($company, $clienteSur, $vinculado, $sur, $owner, '999000003');
        $this->loan($company, $clienteMulti, $multiprestamista, $multi, $owner, '999000005');
        // Préstamo con cuotas vencidas (primer vencimiento en el pasado) para escenarios de atraso.
        $this->loan($company, $clienteAtraso, $independiente, $norte, $owner, '999000006', today()->subWeeks(2));

        // Un pago real por préstamo al día (mismo camino de dinero que la aplicación: custodia del cobrador).
        foreach ([[$independiente, $clienteNorte], [$vinculado, $clienteSur], [$multiprestamista, $clienteMulti]] as [$collector, $customer]) {
            $this->payment($company, $branch->id, $customer, $collector);
        }

        // Agenda semanal ficticia: un cliente por día para cada cobrador.
        $this->visits($company, $branch->id, $independiente);
        $this->visits($company, $branch->id, $vinculado);


        return $this->summary($company) + ['created' => true];
    }

    /**
     * Elimina SOLO las filas del escenario de ESTA empresa. Idempotente.
     *
     * @return array<string, int>
     */
    public function reset(Company $company): array
    {
        $this->assertRunnable($company);

        $removed = [
            'payments' => 0, 'accounts' => 0, 'installments' => 0, 'credits' => 0,
            'requests' => 0, 'assignments' => 0, 'routes' => 0, 'visits' => 0, 'customers' => 0, 'users' => 0,
        ];

        $routeIds = CobrosRoute::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('name', [self::ROUTE_NORTE, self::ROUTE_SUR, self::ROUTE_MULTI])
            ->pluck('id');

        $scenarioCustomers = Customer::where('company_id', $company->id)
            ->where('name', 'like', self::CUSTOMER_PREFIX.'%')
            ->pluck('id');

        if ($routeIds->isNotEmpty()) {
            $accountIds = AccountReceivable::whereIn('cobros_route_id', $routeIds)->pluck('id');
            $creditIds = CobrosCredit::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('cobros_route_id', $routeIds)
                ->pluck('id');

            // Orden de borrado respetando los FOREIGN KEY ... ON DELETE RESTRICT:
            // recibos/reversos → pagos → cuotas → créditos → cuentas por cobrar.
            if ($accountIds->isNotEmpty()) {
                CobrosPaymentReversal::withoutGlobalScopes()->whereIn('account_receivable_id', $accountIds)->delete();
                CobrosReceipt::withoutGlobalScopes()->whereIn('account_receivable_id', $accountIds)->delete();
                $removed['payments'] = AccountReceivablePayment::whereIn('account_receivable_id', $accountIds)->delete();
            }

            if ($creditIds->isNotEmpty()) {
                $removed['installments'] = CobrosCreditInstallment::whereIn('credit_id', $creditIds)->delete();
                $removed['credits'] = CobrosCredit::withoutGlobalScopes()->whereIn('id', $creditIds)->delete();
            }

            if ($accountIds->isNotEmpty()) {
                $removed['accounts'] = AccountReceivable::whereIn('id', $accountIds)->delete();
            }

            $requestIds = CobrosCreditRequest::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('cobros_route_id', $routeIds)
                ->pluck('id');
            if ($requestIds->isNotEmpty()) {
                // Hijos con FK restrict: bitácoras y bureau antes que la solicitud.
                CobrosCreditRequestLog::withoutGlobalScopes()->whereIn('credit_request_id', $requestIds)->delete();
                CobrosBureauIdentityDocument::withoutGlobalScopes()->whereIn('source_credit_request_id', $requestIds)->delete();
                CobrosBureauEvent::withoutGlobalScopes()->whereIn('source_credit_request_id', $requestIds)->delete();
                $removed['requests'] = CobrosCreditRequest::withoutGlobalScopes()->whereIn('id', $requestIds)->delete();
            }

            $assignmentIds = CobrosAssignment::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('cobros_route_id', $routeIds)
                ->pluck('id');
            if ($scenarioCustomers->isNotEmpty()) {
                CobrosAssignmentLog::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->whereIn('customer_id', $scenarioCustomers)
                    ->delete();
                CobrosBureauIdentity::withoutGlobalScopes()
                    ->whereIn('customer_id', $scenarioCustomers)
                    ->delete();
            }
            if ($assignmentIds->isNotEmpty()) {
                $removed['assignments'] = CobrosAssignment::withoutGlobalScopes()->whereIn('id', $assignmentIds)->delete();
            }

            CobrosRouteUser::withoutGlobalScopes()->whereIn('cobros_route_id', $routeIds)->delete();
            CobrosRouteLicense::withoutGlobalScopes()->whereIn('cobros_route_id', $routeIds)->delete();
            $removed['routes'] = CobrosRoute::withoutGlobalScopes()->whereIn('id', $routeIds)->delete();
        }

        // Visitas del escenario: bitácoras primero (FK restrict), luego visitas
        // (FK restrict hacia customers; account_receivable_id es nullOnDelete).
        if ($scenarioCustomers->isNotEmpty()) {
            $visitIds = CobrosVisit::where('company_id', $company->id)
                ->whereIn('customer_id', $scenarioCustomers)->pluck('id');
            if ($visitIds->isNotEmpty()) {
                CobrosVisitLog::whereIn('visit_id', $visitIds)->delete();
                $removed['visits'] = CobrosVisit::whereIn('id', $visitIds)->delete();
            }
        }

        if ($scenarioCustomers->isNotEmpty()) {
            $removed['customers'] = Customer::where('company_id', $company->id)
                ->where('name', 'like', self::CUSTOMER_PREFIX.'%')
                ->where('identification', 'like', self::IDENTIFICATION_PREFIX.'%')
                ->delete();
        }

        // Usuarios sintéticos del escenario: se elimina SOLO la membresía de ESTA
        // empresa. Las filas de usuario se conservan a propósito: múltiples tablas de
        // auditoría/alertas tienen FK restrict hacia users (routeos_audit_logs, alerts,
        // cobros_collector_licenses) y borrarlas implicaría tocar registros append-only.
        // El conteo del escenario (summary.users) mide usuarios VINCULADOS a la empresa,
        // por lo que tras el reset vuelve a 0 y el reseed puede reutilizarlos.
        $scenarioUserIds = User::where('email', 'like', '%@'.self::USER_DOMAIN)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->pluck('id');
        if ($scenarioUserIds->isNotEmpty()) {
            $removed['users'] = \DB::table('company_user')
                ->where('company_id', $company->id)
                ->whereIn('user_id', $scenarioUserIds)
                ->delete();
        }

        return $removed;
    }

    /** @return array<string, int|bool> */
    public function summary(Company $company): array
    {
        return [
            'routes' => CobrosRoute::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereIn('name', [self::ROUTE_NORTE, self::ROUTE_SUR, self::ROUTE_MULTI])->count(),
            'customers' => Customer::where('company_id', $company->id)
                ->where('name', 'like', self::CUSTOMER_PREFIX.'%')->count(),
            'assignments' => CobrosAssignment::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereIn('collector_user_id', $this->scenarioUserIds($company))->count(),
            'credits' => CobrosCredit::withoutGlobalScopes()->where('company_id', $company->id)
                ->whereIn('cobros_route_id', $this->scenarioRouteIds($company))->count(),
            'payments' => AccountReceivablePayment::whereIn('account_receivable_id',
                AccountReceivable::whereIn('cobros_route_id', $this->scenarioRouteIds($company))->pluck('id'))->count(),
            'visits' => CobrosVisit::where('company_id', $company->id)
                ->whereIn('customer_id', Customer::where('company_id', $company->id)
                    ->where('name', 'like', self::CUSTOMER_PREFIX.'%')->pluck('id'))->count(),
            'users' => count($this->scenarioUserIds($company)),
        ];
    }

    /** @return list<int> */
    private function scenarioRouteIds(Company $company): array
    {
        return CobrosRoute::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereIn('name', [self::ROUTE_NORTE, self::ROUTE_SUR, self::ROUTE_MULTI])->pluck('id')->all();
    }

    /** @return list<int> */
    private function scenarioUserIds(Company $company): array
    {
        return User::where('email', 'like', '%@'.self::USER_DOMAIN)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->pluck('id')->all();
    }

    /** Usuario sintético por empresa (email único por empresa, nunca credenciales reales). */
    private function scenarioUser(Company $company, string $localPart): User
    {
        $email = $localPart.'@'.self::USER_DOMAIN;
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'DEMO '.ucwords(str_replace(['.', '-'], ' ', $localPart)),
                // Reutiliza la contraseña configurada del DEMO existente; jamás se imprime.
                'password' => Hash::make((string) (config('demo.owner_password') ?: Str::random(32))),
                'is_active' => true,
            ]
        );

        if (! $user->companies()->where('companies.id', $company->id)->exists()) {
            $user->companies()->attach($company->id, ['role_id' => null]);
        }

        return $user;
    }

    private function platformAdmin(): User
    {
        $admin = User::query()->where('is_platform_admin', true)->where('is_active', true)->orderBy('id')->first();
        if ($admin !== null) {
            return $admin;
        }

        // Admin sintético interno: contraseña aleatoria, no configurable ni imprimible.
        return User::create([
            'name' => 'DEMO Plataforma',
            'email' => 'plataforma.'.Str::lower(Str::random(8)).'@'.self::USER_DOMAIN,
            'password' => Hash::make(Str::random(32)),
            'is_platform_admin' => true,
            'is_active' => true,
        ]);
    }

    /** Habilita el módulo CobrOS conservando los módulos ya contratados de la empresa. */
    private function ensureCobrosModule(Company $company, User $admin): void
    {
        $enabled = CompanyModule::where('company_id', $company->id)
            ->where('is_enabled', true)
            ->pluck('module_key')
            ->all();

        if (! in_array('cobros', $enabled, true)) {
            $enabled[] = 'cobros';
        }

        $this->licenses->updateModules($company, $admin, array_values(array_unique($enabled)));
    }

    private function route(Company $company, string $name, array $userIds, User $admin): CobrosRoute
    {
        $route = CobrosRoute::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
            ['active' => true]
        );

        foreach ($userIds as $userId) {
            CobrosRouteUser::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'cobros_route_id' => $route->id, 'user_id' => $userId],
                ['active' => true]
            );
        }

        // Licencia por ruta con el servicio existente (vigencia de un año, actor plataforma).
        $this->collectorLicenses->renewForRoute($route, $company, $admin, now()->addYear());

        return $route;
    }

    private function customer(Company $company, string $name, string $identification): Customer
    {
        // Customer usa SoftDeletes: el reset deja la fila (soft delete) y el reseed
        // la restaura; jamás duplica identificación por empresa.
        $existing = Customer::withTrashed()
            ->where('company_id', $company->id)
            ->where('identification', $identification)
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->forceFill(['name' => $name])->restore();
            }

            return $existing;
        }

        return Customer::create([
            'company_id' => $company->id,
            'name' => $name,
            'identification' => $identification,
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
    }

    /** Solicitud VERIFICADA → aprobación → desembolso con los servicios reales. */
    private function loan(Company $company, Customer $customer, User $collector, CobrosRoute $route, User $owner, string $identification, ?\DateTimeInterface $firstDueDate = null): CobrosCredit
    {
        $request = CobrosCreditRequest::firstOrCreate(
            [
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'cobros_route_id' => $route->id,
                'collector_user_id' => $collector->id,
            ],
            [
                'applicant_type' => 'existente',
                'status' => CobrosCreditRequest::STATUS_VERIFIED,
                'identification_type' => 'cedula',
                'identification' => $identification,
                'name' => $customer->name,
                'amount_requested' => self::PRINCIPAL,
                'submitted_at' => now(),
            ]
        );

        $credit = $this->credits->approve($company, $request, $owner, [
            'principal' => self::PRINCIPAL,
            'interest_type' => CobrosCredit::INTEREST_FIXED,
            'interest_value' => '5000.0000',
            'installment_count' => 4,
            'frequency' => CobrosCredit::FREQUENCY_WEEKLY,
            'first_due_date' => ($firstDueDate ?? today()->addWeek())->toDateString(),
            'late_fee_type' => CobrosCredit::LATE_FEE_NONE,
            'late_fee_value' => '0.0000',
            'late_fee_grace_days' => 0,
        ]);

        return $this->credits->disburse($company, $credit, $owner);
    }

    /** Agenda semanal: un cliente por día, de lunes a domingo, por cobrador. */
    private function visits(Company $company, int $branchId, User $collector): void
    {
        $assignments = CobrosAssignment::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('collector_user_id', $collector->id)
            ->whereHas('customer', fn ($q) => $q->where('name', 'like', self::CUSTOMER_PREFIX.'%'))
            ->orderBy('id')->get();
        $monday = today($company->timezone ?: config('app.timezone'))->startOfWeek(1);

        foreach ($assignments as $assignment) {
            $account = AccountReceivable::where('company_id', $company->id)
                ->where('branch_id', $branchId)
                ->where('cobros_route_id', $assignment->cobros_route_id)
                ->where('customer_id', $assignment->customer_id)
                ->orderByDesc('id')->first();

            CobrosVisit::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'customer_id' => $assignment->customer_id,
                    'collector_user_id' => $collector->id,
                    'scheduled_for' => $monday->copy()->addDays(((int) $assignment->visit_weekday + 6) % 7)->toDateString(),
                    'status' => CobrosVisit::STATUS_PENDING,
                ],
                [
                    'branch_id' => $branchId,
                    'account_receivable_id' => $account?->id,
                    'notes' => 'Visita DEMO semanal de ejemplo (sin validez).',
                ]
            );
        }
    }

    /** Un abono real por préstamo, por el camino de dinero existente (custodia). */
    private function payment(Company $company, int $branchId, Customer $customer, User $collector): void
    {
        $account = AccountReceivable::where('company_id', $company->id)
            ->where('branch_id', $branchId)
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->first();
        if ($account === null) {
            throw new RuntimeException('No se generó la cuenta por cobrar del préstamo DEMO.');
        }

        $method = PaymentMethod::query()->forCompany($company->id)->active()
            ->whereNotIn('type', [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS])
            ->orderBy('id')
            ->first();
        if ($method === null) {
            throw new RuntimeException('La empresa DEMO no tiene formas de pago disponibles para abonos.');
        }

        $this->accounts->payInCustody($account, [
            'amount' => self::PAYMENT_AMOUNT,
            'payment_method_id' => $method->id,
        ], $collector, $company->id, $branchId);
    }
}
