<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditRequest;
use App\Models\Company;
use App\Services\Cobros\CobrosCreditDeliveryService;
use App\Services\Cobros\CobrosCreditService;
use App\Services\Cobros\InstallmentScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 9 — flujo del dueño/prestamista: definir crédito → aprobación →
 * desembolso. Únicamente cobros.portal_dueno; tenant por global scope
 * (IDOR = 404). El cobrador NUNCA aprueba ni desembolsa.
 */
class OwnerCreditController extends Controller
{
    /** Formulario de condiciones desde una solicitud VERIFICADA (o su crédito). */
    public function define(Request $request, int $solicitud, InstallmentScheduleService $schedule): View
    {
        $creditRequest = $this->findOrFailRequest($solicitud);
        // 10) owner_required: atender la solicitud limpia SOLO la campana del dueño.
        OwnerCreditRequestController::markRequestBellRead($request->user(), $creditRequest->id);
        $credit = CobrosCredit::where('credit_request_id', $creditRequest->id)->first();

        if ($credit !== null) {
            return $this->show($request, (int) $credit->id, $schedule);
        }

        if ($creditRequest->status !== CobrosCreditRequest::STATUS_VERIFIED) {
            abort(422, 'La solicitud debe estar verificada para definir el crédito.');
        }

        // Calendario inicial renderizado por el MOTOR (mismos defaults que el
        // formulario): la vista nunca muestra "Calendario de pagos" vacío.
        $initialRows = $schedule->preview(
            number_format((float) $creditRequest->amount_requested, 2, '.', ''),
            'none',
            '0',
            12,
            'monthly',
            now()->addWeek()->toDateString()
        )['rows'];

        return view('cobros.owner.credit-define', [
            'company' => $this->company(),
            'creditRequest' => $creditRequest,
            'frequencies' => InstallmentScheduleService::FREQUENCIES,
            'initialRows' => $initialRows,
        ]);
    }

    /** Vista previa del total + calendario con el MISMO motor que el desembolso. */
    public function preview(Request $request, InstallmentScheduleService $schedule): JsonResponse
    {
        $terms = $this->validateTerms($request);
        $result = $schedule->preview(
            $terms['principal'],
            $terms['interest_type'],
            $terms['interest_value'],
            (int) $terms['installment_count'],
            $terms['frequency'],
            $terms['first_due_date']
        );

        $rows = $result['rows'];
        $last = $rows[array_key_last($rows)];

        return response()->json([
            'principal' => $schedule->money($terms['principal']),
            'total_due' => $result['total_due'],
            // Diferencia exacta (BCMath) entre total y principal: NO es una
            // regla financiera nueva, solo lectura del mismo resultado.
            'interest' => bcsub($result['total_due'], $terms['principal'], 4),
            'installment_count' => count($rows),
            'normal_amount' => count($rows) > 1 ? $rows[0]['contractual_amount'] : $last['contractual_amount'],
            'last_amount' => $last['contractual_amount'],
            'rows' => $rows,
        ]);
    }

    /** APROBAR: guarda términos congelados; NO crea CxC ni cuotas todavía. */
    public function approve(Request $request, int $solicitud, CobrosCreditService $service): RedirectResponse
    {
        $creditRequest = $this->findOrFailRequest($solicitud);
        $terms = $this->validateTerms($request);

        $credit = $service->approve($this->company(), $creditRequest, $request->user(), $terms);

        // 10) Decisión tomada: la alerta accionable del dueño ya no queda pendiente.
        OwnerCreditRequestController::markRequestBellRead($request->user(), $creditRequest->id);

        return redirect()
            ->route('cobros.owner.creditos.show', $credit->id)
            ->with('success', 'Crédito aprobado · Revise y confirme el desembolso.');
    }

    /** Detalle del crédito: condiciones congeladas + calendario + desembolso. */
    public function show(Request $request, int $credit, InstallmentScheduleService $schedule): View
    {
        $row = CobrosCredit::query()->findOrFail($credit);
        // 8) Supervisar el crédito atiende el trámite: limpia SOLO la campana
        //    del dueño autenticado para esa solicitud (badge ↓).
        \App\Http\Controllers\Cobros\OwnerCreditRequestController::markRequestBellRead(
            $request->user(),
            (int) $row->credit_request_id
        );
        $terms = $row->terms_snapshot;

        // Calendario derivado del snapshot: persistido si ya desembolsó,
        // recalculado con el mismo motor si sigue aprobado (vista previa).
        $rows = $row->installments->isEmpty()
            ? $schedule->build(
                (string) $terms['total_due'],
                (int) $terms['installment_count'],
                (string) $terms['frequency'],
                (string) $terms['first_due_date']
            )
            : $row->installments->map(fn ($i) => [
                'number' => $i->number,
                'due_date' => $i->due_date->toDateString(),
                'contractual_amount' => (string) $i->contractual_amount,
            ])->all();

        return view('cobros.owner.credit-show', [
            'company' => $this->company(),
            'credit' => $row,
            'creditRequest' => $row->request,
            'rows' => $rows,
        ]);
    }

    /** ENTREGAR: firma del cliente obligatoria → aceptación → desembolso (9A.2). */
    public function disburse(Request $request, int $credit, CobrosCreditDeliveryService $delivery): RedirectResponse
    {
        $row = CobrosCredit::query()->findOrFail($credit);
        $delivery->confirm($this->company(), $row, $request->user(), $request->input('signature'));

        return redirect()
            ->route('cobros.owner.creditos.comprobante', $row->id)
            ->with('success', 'Préstamo entregado correctamente ✓');
    }

    /** Términos del formulario (UI de negocio; dinero con hasta 4 decimales). */
    protected function validateTerms(Request $request): array
    {
        $decimal = ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,4})?$/D'];

        $data = $request->validate([
            'principal' => $decimal,
            'interest_type' => ['required', 'string', 'in:none,fixed,percent'],
            'interest_value' => ['nullable', 'string', 'regex:/^\d{1,13}(\.\d{1,4})?$/D'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:120'],
            'frequency' => ['required', 'string', 'in:daily,weekly,biweekly,monthly'],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'late_fee_type' => ['required', 'string', 'in:none,fixed,percent'],
            'late_fee_value' => ['nullable', 'string', 'regex:/^\d{1,13}(\.\d{1,4})?$/D'],
            'late_fee_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'principal.required' => 'Indique el monto que va a prestar.',
            'principal.regex' => 'Escriba un monto válido, por ejemplo 50000 o 50000.50.',
            'interest_value.regex' => 'Escriba un valor válido, por ejemplo 20 o 1500.50.',
            'late_fee_value.regex' => 'Escriba un valor válido, por ejemplo 500 o 500.50.',
            'late_fee_grace_days.integer' => 'Escriba un número de días.',
            'installment_count.required' => 'Indique en cuántos pagos dividirá el préstamo.',
            'installment_count.min' => 'Debe haber al menos 1 pago.',
            'frequency.in' => 'Elija cada cuánto paga el cliente.',
            'first_due_date.after_or_equal' => 'El primer pago no puede ser anterior a hoy.',
        ]);

        $data['principal'] = trim($data['principal']);
        if (bccomp($data['principal'], '0.0000', 4) <= 0) {
            abort(422, 'El monto aprobado debe ser mayor que cero.');
        }

        // Interés: "Sin interés" no exige valor; los demás sí.
        $data['interest_value'] = trim((string) ($data['interest_value'] ?? ''));
        if ($data['interest_type'] === 'none') {
            $data['interest_value'] = '0';
        } elseif ($data['interest_value'] === '') {
            throw ValidationException::withMessages([
                'interest_value' => 'Escriba el valor del interés.',
            ]);
        }
        if (bccomp($data['interest_value'], '0.0000', 4) < 0) {
            throw ValidationException::withMessages([
                'interest_value' => 'El interés debe ser cero o mayor.',
            ]);
        }

        // Mora: "No" no exige valor ni días de gracia; con mora sí.
        $data['late_fee_value'] = trim((string) ($data['late_fee_value'] ?? ''));
        if ($data['late_fee_type'] === 'none') {
            $data['late_fee_value'] = '0';
            $data['late_fee_grace_days'] = 0;
        } else {
            if ($data['late_fee_value'] === '') {
                throw ValidationException::withMessages([
                    'late_fee_value' => 'Escriba el valor de la mora.',
                ]);
            }
            if (($data['late_fee_grace_days'] ?? null) === null || $data['late_fee_grace_days'] === '') {
                throw ValidationException::withMessages([
                    'late_fee_grace_days' => 'Indique los días antes de cobrar mora.',
                ]);
            }
            $data['late_fee_grace_days'] = (int) $data['late_fee_grace_days'];
        }

        return $data;
    }

    protected function findOrFailRequest(int $id): CobrosCreditRequest
    {
        // Global scope por empresa activa: solicitud ajena = 404 (IDOR).
        return CobrosCreditRequest::query()->findOrFail($id);
    }

    protected function company(): Company
    {
        return Company::findOrFail((int) session('active_company_id'));
    }
}
