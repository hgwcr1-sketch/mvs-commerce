<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosDailyClosing;
use App\Models\Company;
use App\Services\Cobros\DailyClosingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cierre del día del cobrador dentro de /cobros/*.
 *
 * El cobrador declara totales por medio de pago, adjunta comprobantes de
 * depósito y cierre de datáfono, agrega notas y envía el cierre al dueño.
 * La evidencia se guarda en disco privado y se sirve por ruta autorizada
 * (el dueño/supervisor o el propio cobrador); nunca por URL pública.
 */
class DailyClosingController extends Controller
{
    /** Días hacia atrás (incluido hoy) que el cobrador puede cerrar. */
    private const BACK_DAYS = 6;

    /** Hasta 4 decimales exactos, sin floats: la validación lo exige. */
    private const MONEY_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';

    public function show(Request $request, DailyClosingService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $collector = $request->user();
        $day = $this->resolveDay($request, $company);

        $totals = $service->computeDayTotals($company, $collector, $day);
        $closing = CobrosDailyClosing::query()
            ->where('company_id', $company->id)
            ->where('collector_user_id', $collector->id)
            ->whereDate('business_date', $day->toDateString())
            ->first();

        return view('cobros.collector.daily-closing', [
            'company' => $company,
            'day' => $day,
            'dayLabel' => $day->locale('es')->isoFormat('dddd D [de] MMMM YYYY'),
            'totals' => $totals,
            'closing' => $closing,
            'differences' => $closing ? $service->differences($closing) : null,
            'otherDates' => $this->otherDates($company, $day),
        ]);
    }

    public function store(Request $request, DailyClosingService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $collector = $request->user();
        $day = $this->resolveDay($request, $company);

        $money = ['nullable', 'string', 'regex:'.self::MONEY_PATTERN];
        $data = $request->validate([
            'business_date' => ['nullable', 'date'],
            'declared_cash' => $money,
            'declared_sinpe' => $money,
            'declared_card' => $money,
            'declared_bank_transfer' => $money,
            'notes' => ['nullable', 'string', 'max:1000'],
            'evidence_deposit' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'evidence_dataphone' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ], [
            'declared_cash.regex' => 'Efectivo debe ser un monto con hasta 4 decimales.',
            'declared_sinpe.regex' => 'SINPE debe ser un monto con hasta 4 decimales.',
            'declared_card.regex' => 'Datáfono debe ser un monto con hasta 4 decimales.',
            'declared_bank_transfer.regex' => 'Depósito debe ser un monto con hasta 4 decimales.',
            'evidence_deposit.image' => 'El comprobante de depósito debe ser una imagen.',
            'evidence_dataphone.image' => 'El cierre de datáfono debe ser una imagen.',
        ]);

        $depositPath = $this->storeEvidence($request, 'evidence_deposit', $company, 'deposito');
        $dataphonePath = $this->storeEvidence($request, 'evidence_dataphone', $company, 'dataphone');

        $service->submit($company, $collector, $day, [
            'cash' => $this->toDecimal($data['declared_cash'] ?? null),
            'sinpe' => $this->toDecimal($data['declared_sinpe'] ?? null),
            'card' => $this->toDecimal($data['declared_card'] ?? null),
            'bank_transfer' => $this->toDecimal($data['declared_bank_transfer'] ?? null),
        ], $data['notes'] ?? null, $depositPath, $dataphonePath);

        return redirect()
            ->route('cobros.closing.show', ['date' => $day->toDateString()])
            ->with('success', 'Cierre del día enviado al dueño.');
    }

    /**
     * Sirve una evidencia del cierre al propio cobrador o al dueño/
     * supervisor de la misma empresa. Disco privado: sin ruta pública.
     */
    public function evidence(Request $request, CobrosDailyClosing $closing, string $kind): BinaryFileResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($closing->company_id !== $company->id) {
            abort(404);
        }

        $user = $request->user();
        $isOwnerOrSupervisor = $user->hasPermission('cobros.portal_dueno', $company)
            || $user->hasPermission('cobros.supervisar', $company);

        if ($closing->collector_user_id !== $user->id && ! $isOwnerOrSupervisor) {
            abort(403);
        }

        $path = match ($kind) {
            'deposito' => $closing->evidence_deposit_path,
            'dataphone' => $closing->evidence_dataphone_path,
            default => null,
        };

        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path));
    }

    private function resolveDay(Request $request, Company $company): CarbonImmutable
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $today = CarbonImmutable::today($timezone);

        $requested = $request->string('date')->toString();
        if ($requested === '') {
            return $today;
        }

        try {
            $day = CarbonImmutable::parse($requested, $timezone)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }

        if ($day->greaterThan($today) || $day->lessThan($today->subDays(self::BACK_DAYS))) {
            return $today;
        }

        return $day;
    }

    /** @return array<string, string> Otras fechas cerrables (sin la actual) */
    private function otherDates(Company $company, CarbonImmutable $current): array
    {
        $timezone = $company->timezone ?: config('app.timezone');
        $dates = [];

        for ($i = 1; $i <= self::BACK_DAYS; $i++) {
            $day = CarbonImmutable::today($timezone)->subDays($i);
            $dates[$day->toDateString()] = $day->locale('es')->isoFormat('D MMM YYYY');
        }

        unset($dates[$current->toDateString()]);

        return $dates;
    }

    private function storeEvidence(Request $request, string $field, Company $company, string $prefix): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }

        $file = $request->file($field);
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        return $file->storeAs(
            'cobros/closings/'.$company->id.'/'.$request->user()->id.'/'.now()->format('Ymd_His').'_'.$prefix,
            $prefix.'.'.$extension,
            'local'
        );
    }

    /** Cadena validada a decimal escala 4 exacto, sin conversión float. */
    private function toDecimal(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd($value, '0', 4);
    }
}
