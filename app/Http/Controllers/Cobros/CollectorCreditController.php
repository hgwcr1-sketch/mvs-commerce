<?php

namespace App\Http\Controllers\Cobros;

use App\Models\CobrosCollectorAuthorityPolicy;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditRequest;
use App\Services\Cobros\CobrosCreditDeliveryService;
use App\Services\Cobros\CobrosCreditRequestService;
use App\Services\Cobros\CobrosCreditService;
use App\Services\Cobros\InstallmentScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * BLOQUE 9C — el COBRADOR es la autoridad del préstamo de su ruta.
 *
 * Preparar condiciones → CONTINUAR CON EL PRÉSTAMO (o NO REALIZARLO) →
 * entrega con firma (9A.2). Reutiliza CobrosCreditService,
 * InstallmentScheduleService y la vista 9A sin duplicar motor ni vistas.
 * El dueño NO es requisito para avanzar; su pantallas siguen siendo de
 * supervisión/histórico (Bloque 8 intacto).
 */
class CollectorCreditController extends OwnerCreditController
{
    /** "Mis préstamos": créditos propios del cobrador en la empresa activa. */
    public function index(Request $request): View
    {
        $company = \App\Models\Company::findOrFail((int) session('active_company_id'));
        $credits = CobrosCredit::query()->forCompany((int) $company->id)
            ->where('collector_user_id', $request->user()->id)
            ->with('customer')
            ->orderByDesc('id')
            ->get();

        return view('cobros.collector.credit-index', [
            'company' => $company,
            'credits' => $credits,
        ]);
    }

    /** Solicitud propia del cobrador (ajena = 403; otro tenant = 404). */
    protected function findOrFailRequest(int $id): CobrosCreditRequest
    {        $request = CobrosCreditRequest::query()->findOrFail($id);

        $actor = auth()->user();
        if ($actor === null || (int) $request->collector_user_id !== (int) $actor->id) {
            abort(403, 'Esta solicitud no le pertenece.');
        }

        $activeRoute = (int) session('active_cobros_route_id', 0);
        if ($activeRoute !== 0 && $request->cobros_route_id !== null && (int) $request->cobros_route_id !== $activeRoute) {
            abort(403, 'Esta solicitud pertenece a otra ruta.');
        }

        return $request;
    }

    /** "PREPARAR PRÉSTAMO": mismo formulario 9A con rutas/etiquetas del cobrador. */
    public function prepare(Request $request, int $solicitud, \App\Services\Cobros\InstallmentScheduleService $schedule): View
    {
        $creditRequest = $this->findOrFailRequest($solicitud);
        $credit = CobrosCredit::where('credit_request_id', $creditRequest->id)->first();

        if ($credit !== null) {
            return redirect()->route('cobros.collector.creditos.entrega', $credit->id);
        }

        if ($creditRequest->status !== CobrosCreditRequest::STATUS_VERIFIED) {
            abort(422, 'La solicitud debe estar revisada para preparar el préstamo.');
        }

        // Calendario inicial por el MOTOR 9A (mismos defaults del formulario).
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
            'backRoute' => route('cobros.collector.credit-requests.index'),
            'backLabel' => '← Volver a mis solicitudes',
            'approveRoute' => route('cobros.collector.credit-requests.continuar', $creditRequest->id),
            'previewUrl' => route('cobros.collector.credit-requests.preview'),
            'primaryLabel' => 'CONTINUAR CON EL PRÉSTAMO',
            'confirmLead' => 'Está por continuar con este préstamo de',
            'confirmTitle' => 'Confirmar préstamo',
            'confirmLabel' => 'CONTINUAR',
            'noRealizarRoute' => route('cobros.collector.credit-requests.not-realizar', $creditRequest->id),
            // owner_required: prepara la propuesta pero NO decide (el dueño autoriza).
            'hideDecisions' => CobrosCollectorAuthorityPolicy::requiresOwner(
                (int) $creditRequest->company_id,
                (int) $creditRequest->collector_user_id
            ),
        ]);
    }

    /**
     * 13) Modalidad collector_authorized: el cobrador revisa la información
     * de su propia solicitud (paso de verificación de datos, NO económico).
     * Con owner_required esta vía queda prohibida: verifica el dueño.
     */
    public function verify(Request $request, int $solicitud, CobrosCreditRequestService $service): RedirectResponse
    {
        $creditRequest = $this->findOrFailRequest($solicitud);
        $this->requireCollectorAuthority($creditRequest);

        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ], [
            'motivo.required' => 'Describa la revisión realizada con el cliente.',
        ]);

        $service->verify($this->company(), $creditRequest, $request->user(), $data['motivo']);

        return redirect()
            ->route('cobros.collector.credit-requests.index')
            ->with('success', 'Solicitud verificada · Ya puede preparar el préstamo.');
    }

    /**
     * 13) Guard server-side de la política de autoridad: con owner_required
     * el cobrador NO puede autorizar ni dar por no realizado el préstamo
     * (su rol es preparar y enviar; decide el dueño). Nunca se confía en
     * nada enviado desde el frontend.
     */
    private function requireCollectorAuthority(CobrosCreditRequest $creditRequest): void
    {
        if (CobrosCollectorAuthorityPolicy::requiresOwner(
            (int) $creditRequest->company_id,
            (int) $creditRequest->collector_user_id
        )) {
            abort(403, 'Las solicitudes de este cobrador esperan la autorización del dueño.');
        }
    }

    /** "CONTINUAR CON EL PRÉSTAMO": guarda condiciones y pasa a la entrega. */
    public function approve(Request $request, int $solicitud, CobrosCreditService $service): RedirectResponse
    {
        $creditRequest = $this->findOrFailRequest($solicitud);
        $this->requireCollectorAuthority($creditRequest);
        $terms = $this->validateTerms($request);
        $credit = $service->approve($this->company(), $creditRequest, $request->user(), $terms);

        return redirect()
            ->route('cobros.collector.creditos.entrega', $credit->id)
            ->with('success', 'Préstamo preparado · Revise las condiciones con el cliente.');
    }

    /**
     * "NO REALIZAR EL PRÉSTAMO": conserva solicitud e evidencia, motivo
     * categorizado en bitácora append-only; NO crea crédito/CxC y NO genera
     * ninguna calificación negativa (solo un hecho objetivo registrado).
     */
    public function notRealizar(Request $request, int $solicitud, CobrosCreditRequestService $service): RedirectResponse
    {
        $creditRequest = $this->findOrFailRequest($solicitud);
        $this->requireCollectorAuthority($creditRequest);

        $data = $request->validate([
            'motivo' => ['required', 'string', 'in:cliente_no_califica,condiciones_no_acordadas,informacion_incompleta,otro'],
        ], [
            'motivo.required' => 'Elija el motivo por el que no se realizará el préstamo.',
            'motivo.in' => 'Elija un motivo válido.',
        ]);

        $label = match ($data['motivo']) {
            'cliente_no_califica' => 'El cliente no califica para este préstamo',
            'condiciones_no_acordadas' => 'No se acordaron las condiciones',
            'informacion_incompleta' => 'Información incompleta del cliente',
            'otro' => 'Otro motivo',
        };

        $service->reject(
            $this->company(),
            $creditRequest,
            $request->user(),
            'No realizar el préstamo · '.$label,
            [CobrosCreditRequest::STATUS_VERIFIED]
        );

        return redirect()
            ->route('cobros.collector.credit-requests.index')
            ->with('success', 'Solicitud conservada · No se realizó el préstamo.');
    }

    /** Entrega: firma obligatoria (9A.2) con propiedad del cobrador. */
    public function disburse(Request $request, int $credit, CobrosCreditDeliveryService $delivery): RedirectResponse
    {
        $row = CobrosCredit::query()->findOrFail($credit);

        if ((int) $row->collector_user_id !== (int) $request->user()->id) {
            abort(403, 'Este préstamo no le pertenece.');
        }

        // 14B: con ruta activa, la entrega solo procede si el prestamo es de esa ruta.
        $activeRoute = (int) session('active_cobros_route_id', 0);
        if ($activeRoute !== 0 && $row->cobros_route_id !== null && (int) $row->cobros_route_id !== $activeRoute) {
            abort(403, 'Este prestamo pertenece a otra ruta.');
        }

        $delivery->confirm($this->company(), $row, $request->user(), $request->input('signature'));

        return redirect()
            ->route('cobros.collector.creditos.comprobante', $row->id)
            ->with('success', 'Préstamo entregado correctamente ✓');
    }
}
