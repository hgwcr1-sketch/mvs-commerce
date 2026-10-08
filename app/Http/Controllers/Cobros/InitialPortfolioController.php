<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosInitialPortfolioCorrectionRequest;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosInitialPortfolioSnapshot;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\InitialPortfolioMigrationService;
use App\Services\Cobros\InitialPortfolioRecordService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cartera inicial POR COBRADOR (auth), revisable por el prestamista.
 * El cobrador inicia/carga/finaliza su ciclo; finalizar abre el portal de
 * inmediato (sin aprobación). El prestamista solo revisa/resuelve/reabre.
 */
class InitialPortfolioController extends Controller
{
    public function index(Request $request, InitialPortfolioMigrationService $migrations): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $user = $request->user();
        abort_if($user->hasPermission('cobros.portal_dueno', $company), 403, 'Use el resumen de carteras iniciales.');

        return view('cobros.collector.initial-portfolio', [
            'company' => $company,
            'state' => $migrations->stateFor($company, $user),
            'migration' => $migrations->migrationFor($company, $user),
            'isOwner' => false,
            'loadedClients' => app(InitialPortfolioRecordService::class)->countFor($company, $user),
            'records' => CobrosInitialPortfolioRecord::query()
                ->whereHas('migration', fn ($q) => $q->where('company_id', $company->id)->where('collector_user_id', $user->id))
                ->with('customer')->orderBy('id')->get(),
            'snapshot' => CobrosInitialPortfolioSnapshot::query()
                ->where('company_id', $company->id)
                ->where('collector_user_id', $user->id)
                ->latest('id')->first(),
        ]);
    }

    public function createClient(Request $request, InitialPortfolioRecordService $records): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $records->assertOpen($company, $request->user());

        return view('cobros.collector.initial-portfolio-client', [
            'company' => $company,
            'isOwner' => false,
        ]);
    }

    public function storeClient(Request $request, InitialPortfolioRecordService $records)
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $records->assertOpen($company, $request->user());

        $data = $request->validate([
            'identification_type' => ['required', 'in:cedula,dimex,pasaporte'],
            'identification' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'alt_contact_name' => ['nullable', 'string', 'max:120'],
            'alt_contact_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:250'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'original_amount' => ['nullable', 'string', 'max:32', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'balance' => ['required', 'string', 'max:32', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'installment' => ['required', 'string', 'max:32', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'frequency' => ['required', 'in:diario,semanal,quincenal,mensual'],
            'next_charge_date' => ['required', 'date'],
            'prior_credits' => ['nullable', 'integer', 'min:0', 'max:999'],
            'declared_behavior' => ['required', 'in:bueno,regular,malo,sin_informacion'],
            'historical_note' => ['nullable', 'string', 'max:500'],
            'action' => ['nullable', 'in:siguiente,volver'],
        ]);

        $records->create($company, $request->user(), $data);
        $next = ($data['action'] ?? null) === 'siguiente';

        return redirect()->route($next ? 'cobros.initial-portfolio.client' : 'cobros.initial-portfolio')
            ->with('success', $next ? 'Cliente guardado. Puede cargar el siguiente.' : 'Cliente guardado.');
    }

    public function start(Request $request, InitialPortfolioMigrationService $migrations): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $migrations->start($company, $request->user());

        // Comenzar lleva directo al formulario 5B (estado en progreso).
        return redirect()->route('cobros.initial-portfolio.client')
            ->with('success', 'Migración de cartera inicial abierta. Puede continuar cuando quiera.');
    }

    /** Finalizar congela + foto de apertura y abre el portal INMEDIATAMENTE. */
    public function finalize(Request $request, InitialPortfolioMigrationService $migrations): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate(['confirm' => ['required', 'string']]);

        // Confirmación fuerte con equivalentes normalizados: acentos, caja y
        // espacios exteriores se ignoran (FINALIZAR MIGRACIÓN / MIGRACION).
        $confirm = preg_replace('/\s+/u', ' ', mb_strtoupper(\Illuminate\Support\Str::ascii(trim($data['confirm']))));
        if ($confirm !== 'FINALIZAR MIGRACION') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'confirm' => 'Escriba FINALIZAR MIGRACIÓN para confirmar.',
            ]);
        }

        $migrations->finalize($company, $request->user());

        return redirect()->route('cobros.collector.index')
            ->with('success', 'Cartera inicial finalizada. Ya puede trabajar y cobrar.');
    }

    /** Reapertura excepcional por cobrador: solo Panel Maestro (auditable). */
    public function reopen(Request $request, Company $company, User $user, InitialPortfolioMigrationService $migrations): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $request->validate(['reason' => ['required', 'string', 'min:8', 'max:250']]);

        $migrations->reopen($company, $user, $request->user(), (string) $request->input('reason'));

        return redirect()->route('platform.companies.show', $company)
            ->with('success', 'Carga inicial reabierta para ese cobrador (registrada en auditoría).');
    }

    /** Prestamista reabre la carga de UN cobrador (cliente olvidado): solo agrega. */
    public function reopenForCollector(Request $request, InitialPortfolioMigrationService $migrations): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate([
            'collector_user_id' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'min:8', 'max:250'],
        ]);
        $collector = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->findOrFail((int) $data['collector_user_id']);

        $migrations->reopen($company, $collector, $request->user(), $data['reason']);

        return redirect()->route('cobros.initial-portfolio.summary')
            ->with('success', 'Carga reabierta para ese cobrador: podrá agregar clientes olvidados.');
    }

    /** Resumen del prestamista por cobrador (control administrativo, no gate). */
    public function summary(Request $request, InitialPortfolioMigrationService $migrations): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $user = $request->user();
        abort_unless($user->hasPermission('cobros.portal_dueno', $company), 403);

        $rows = $migrations->summaryFor($company);
        $corrections = CobrosInitialPortfolioCorrectionRequest::query()
            ->where('company_id', $company->id)
            ->where('status', CobrosInitialPortfolioCorrectionRequest::STATUS_PENDING)
            ->with(['record.customer', 'requestedBy'])
            ->orderBy('id')->get();

        return view('cobros.collector.initial-portfolio-summary', [
            'company' => $company,
            'rows' => $rows,
            'corrections' => $corrections,
        ]);
    }

    /** Marcar REVISADA (posterior, sin alterar datos financieros). */
    public function review(Request $request, InitialPortfolioMigrationService $migrations): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate(['collector_user_id' => ['required', 'numeric']]);
        $collector = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->findOrFail((int) $data['collector_user_id']);

        $migrations->markReviewed($company, $collector, $request->user());

        return redirect()->route('cobros.initial-portfolio.summary')->with('success', 'Cartera marcada como revisada.');
    }

    /** El cobrador solicita corrección de un dato congelado: NO modifica nada. */
    public function requestCorrection(Request $request, CobrosInitialPortfolioRecord $record, InitialPortfolioRecordService $records): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate([
            'field' => ['required', 'in:original_amount,balance,installment,frequency,next_charge_date,prior_credits,declared_behavior,historical_note'],
            'requested_value' => ['required', 'string', 'max:60'],
            'reason' => ['required', 'string', 'min:8', 'max:250'],
        ]);

        $records->requestCorrection($company, $request->user(), $record, $data);

        return redirect()->route('cobros.initial-portfolio')
            ->with('success', 'Corrección solicitada: el prestamista la revisará.');
    }

    /** Prestamista aprueba/rechaza una corrección de dato congelado. */
    public function resolveCorrection(Request $request, CobrosInitialPortfolioCorrectionRequest $correction, InitialPortfolioRecordService $records): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate(['decision' => ['required', 'in:aprobar,rechazar']]);

        $records->resolveCorrection($company, $request->user(), $correction, $data['decision']);

        return redirect()->route('cobros.initial-portfolio.summary')
            ->with('success', 'Solicitud '.($data['decision'] === 'aprobar' ? 'aprobada y aplicada' : 'rechazada').'.');
    }
}
