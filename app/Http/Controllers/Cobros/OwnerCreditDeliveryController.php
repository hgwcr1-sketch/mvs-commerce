<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditAcceptance;
use App\Models\Company;
use App\Services\Cobros\CobrosCreditDeliveryService;
use App\Services\Cobros\InstallmentScheduleService;
use App\Support\Cobros\Money;
use App\Support\Cobros\WhatsApp;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * BLOQUE 9A.2 — entrega con firma: revisar condiciones, firmar, comprobante
 * PDF y enlace de WhatsApp. Solo cobros.portal_dueno; tenant por global scope.
 * El contrato se arma SIEMPRE desde el snapshot aceptado (inmutable).
 */
class OwnerCreditDeliveryController extends Controller
{
    private const FREQ_LABEL = [
        CobrosCredit::FREQUENCY_DAILY => 'Todos los días',
        CobrosCredit::FREQUENCY_WEEKLY => 'Cada semana',
        CobrosCredit::FREQUENCY_BIWEEKLY => 'Cada 15 días',
        CobrosCredit::FREQUENCY_MONTHLY => 'Cada mes',
    ];

    /** Pantalla "Confirmar entrega del préstamo" (solo crédito aprobado). */
    public function delivery(Request $request, int $credit, InstallmentScheduleService $schedule): View
    {
        $row = $this->findOrFailCredit($credit);

        if ($row->status === CobrosCredit::STATUS_DISBURSED) {
            return $this->receipt($request, $credit);
        }

        return view('cobros.owner.credit-delivery', $this->payload($row, $schedule));
    }

    /** Pantalla posterior a la entrega: "Préstamo entregado correctamente ✓". */
    public function receipt(Request $request, int $credit): View
    {
        $row = $this->findOrFailCredit($credit);
        $acceptance = CobrosCreditAcceptance::forCompany($this->company()->id)
            ->where('credit_id', $row->id)
            ->first();

        if ($row->status !== CobrosCredit::STATUS_DISBURSED || $acceptance === null) {
            abort(422, 'Este préstamo aún no ha sido entregado.');
        }

        $data = $this->payload($row, app(InstallmentScheduleService::class));
        $data['acceptance'] = $acceptance;
        $data['whatsappUrl'] = $this->whatsappUrl($row, $data['normalAmount']);

        return view('cobros.owner.credit-receipt', $data);
    }

    /** Comprobante PDF privado (inline o descarga) generado del snapshot aceptado. */
    public function pdf(Request $request, int $credit)
    {
        $row = $this->findOrFailCredit($credit);
        $acceptance = CobrosCreditAcceptance::forCompany($this->company()->id)
            ->where('credit_id', $row->id)
            ->firstOrFail();

        $data = $this->payload($row, app(InstallmentScheduleService::class));
        $data['acceptance'] = $acceptance;
        $data['signature'] = $this->signatureImage($acceptance);

        $name = 'comprobante-prestamo-credito-'.$row->id.'.pdf';
        $pdf = Pdf::loadView('cobros.pdf.credit-acceptance', $data)->setPaper('letter');

        return $request->boolean('descargar')
            ? $pdf->download($name)
            : $pdf->stream($name);
    }

    /** Datos de pantalla/PDF derivados SIEMPRE del snapshot del crédito. */
    public function payload(CobrosCredit $credit, InstallmentScheduleService $schedule): array
    {
        $terms = $credit->terms_snapshot;
        $request = $credit->request;
        $customer = $credit->customer;

        $rows = $credit->installments->isEmpty()
            ? $schedule->build(
                (string) $terms['total_due'],
                (int) $terms['installment_count'],
                (string) $terms['frequency'],
                (string) $terms['first_due_date']
            )
            : $credit->installments->map(fn ($i) => [
                'number' => $i->number,
                'due_date' => $i->due_date->toDateString(),
                'contractual_amount' => (string) $i->contractual_amount,
            ])->all();

        $last = $rows[array_key_last($rows)];
        $interest = bcsub((string) $terms['total_due'], (string) $terms['principal'], 4);

        $interestLabel = match ($terms['interest_type']) {
            CobrosCredit::INTEREST_NONE => 'Sin interés',
            CobrosCredit::INTEREST_FIXED => 'Monto fijo de '.Money::human((string) $terms['interest_value'], $this->company()->currency),
            CobrosCredit::INTEREST_PERCENT => $this->trimNumber((string) $terms['interest_value']).'% sobre el monto prestado',
            default => '—',
        };

        $lateFeeLabel = match ($terms['late_fee_type']) {
            CobrosCredit::LATE_FEE_NONE => 'Sin mora',
            CobrosCredit::LATE_FEE_FIXED => Money::human((string) $terms['late_fee_value'], $this->company()->currency).' por atraso',
            CobrosCredit::LATE_FEE_PERCENT => $this->trimNumber((string) $terms['late_fee_value']).'% por atraso',
            default => '—',
        };

        return [
            'company' => $this->company(),
            'credit' => $credit,
            'creditRequest' => $request,
            'customerName' => (string) ($customer?->name ?? $request?->name),
            'identification' => (string) ($customer?->identification ?? $request?->identification),
            'identificationType' => (string) ($customer?->identification_type ?? $request?->identification_type),
            'route' => $credit->route,
            'collector' => $credit->collector,
            'terms' => $terms,
            'rows' => $rows,
            'interest' => $interest,
            'interestLabel' => $interestLabel,
            'lateFeeLabel' => $lateFeeLabel,
            'frequencyLabel' => self::FREQ_LABEL[$terms['frequency']] ?? '—',
            'normalAmount' => count($rows) > 1 ? $rows[0]['contractual_amount'] : $last['contractual_amount'],
            'lastAmount' => $last['contractual_amount'],
            'lastDueDate' => $last['due_date'],
            'acceptanceText' => CobrosCreditDeliveryService::ACCEPTANCE_TEXT,
            'frequencies' => self::FREQ_LABEL,
            // Rutas de la pantalla: el dueño usa owner.*; el cobrador las
            // sobreescribe (subclase) para no chocar con permission:portal_dueno.
            'disburseRoute' => $this->disburseRoute($credit),
            'backRoute' => $this->backRoute($credit),
            'pdfRoute' => $this->pdfRoute($credit),
            'pdfDownloadRoute' => $this->pdfDownloadRoute($credit),
        ];
    }

    /** POST de entrega de ESTA pantalla (dueño, portal_dueno). */
    protected function disburseRoute(CobrosCredit $credit): string
    {
        return route('cobros.owner.creditos.disburse', $credit->id);
    }

    /** Enlace "Volver al préstamo" (dueño, portal_dueno). */
    protected function backRoute(CobrosCredit $credit): string
    {
        return route('cobros.owner.creditos.show', $credit->id);
    }

    /** Comprobante PDF inline/descarga (dueño, portal_dueno). */
    protected function pdfRoute(CobrosCredit $credit): string
    {
        return route('cobros.owner.creditos.comprobante_pdf', $credit->id);
    }

    protected function pdfDownloadRoute(CobrosCredit $credit): string
    {
        return route('cobros.owner.creditos.comprobante_pdf', ['credit' => $credit->id, 'descargar' => 1]);
    }

    /** Imagen real de la firma (proporcional, sin deformar) para el PDF. */
    public function signatureImage(CobrosCreditAcceptance $acceptance): array
    {
        $path = $acceptance->signature_path;
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return ['data' => null, 'width' => 0, 'height' => 0];
        }

        $binary = Storage::disk('local')->get($path);
        $width = unpack('N', substr($binary, 16, 4))[1] ?? 600;
        $height = unpack('N', substr($binary, 20, 4))[1] ?? 200;

        $targetWidth = min((int) $width, 400);

        return [
            'data' => 'data:image/png;base64,'.base64_encode($binary),
            'width' => $targetWidth,
            'height' => max(60, (int) round($targetWidth * ((int) $height / max(1, (int) $width)))),
        ];
    }

    /** Enlace wa.me con el mensaje de entrega (null si no hay teléfono utilizable). */
    private function whatsappUrl(CobrosCredit $credit, string $normalAmount): ?string
    {
        $customer = $credit->customer;
        if ($customer === null) {
            return null;
        }

        $terms = $credit->terms_snapshot;

        return WhatsApp::loanUrl(
            $this->company(),
            $customer,
            (string) $terms['principal'],
            (string) $terms['total_due'],
            (int) $terms['installment_count'],
            $normalAmount,
            self::FREQ_LABEL[$terms['frequency']] ?? '',
            $credit->first_due_date?->format('d/m/Y') ?? (string) $terms['first_due_date'],
            $credit->publicReference()
        );
    }

    /** 12.5000 → 12.5 (solo presentación; el dinero no cambia). */
    private function trimNumber(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    protected function findOrFailCredit(int $id): CobrosCredit
    {
        return CobrosCredit::query()->findOrFail($id);
    }

    protected function company(): Company
    {
        return Company::findOrFail((int) session('active_company_id'));
    }
}
