<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Services\Customers\IdentificationNameLookupService;
use App\Services\Cobros\CobrosCreditRequestService;
use App\Services\Cobros\CobrosBureauService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * BLOQUE 8 — verificación de solicitudes del dueño/prestamista.
 * Aislamiento: tenant por global scope de BelongsToCobrosTenant (404 ajeno),
 * permiso cobros.portal_dueno. NO crea crédito, CxC, cuotas, saldo ni pagos.
 */
class OwnerCreditRequestController extends Controller
{
    /** Bandeja de solicitudes agrupada por ruta (filtros opcionales ruta/estado). */
    public function index(Request $request): View
    {
        $data = $request->validate([
            'route_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $query = CobrosCreditRequest::query()->with(['route', 'collector'])->orderByDesc('id');
        if (! empty($data['route_id'])) {
            $query->where('cobros_route_id', (int) $data['route_id']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('cobros.owner.credit-requests', [
            'company' => $this->company(),
            'routes' => CobrosRoute::query()->orderBy('name')->get(['id', 'name']),
            'requests' => $query->get(),
            'filters' => $data,
            // 9C: estado REAL del cr�dito por solicitud (badge "Pr�stamo entregado").
            'creditStatus' => \App\Models\CobrosCredit::whereIn('credit_request_id', $query->pluck('id'))
                ->pluck('status', 'credit_request_id'),
        ]);
    }

    /** Detalle: datos del solicitante, identidad, Hacienda (si aplica) y bitácora. */
    public function show(Request $request, int $id): View
    {
        $creditRequest = $this->findOrFail($id);
        $this->markBellRead($request, $creditRequest);

        return view('cobros.owner.credit-request-show', [
            'company' => $this->company(),
            'creditRequest' => $creditRequest->load(['route', 'collector', 'logs' => fn ($q) => $q->orderByDesc('id')]),
            'credit' => \App\Models\CobrosCredit::where('credit_request_id', $creditRequest->id)->first(),
            'haciendaName' => $this->haciendaName($creditRequest),
            // 9C.4: la MISMA política de autoridad gobierna la UX de consulta
            // en esta pantalla (server-side, nunca desde el frontend).
            'requiresOwnerPolicy' => \App\Models\CobrosCollectorAuthorityPolicy::requiresOwner(
                (int) $creditRequest->company_id,
                (int) $creditRequest->collector_user_id
            ),
        ]);
    }

    /**
     * Fotos desde disco PRIVADO: nunca Storage::url ni URL pública.
     * ?descargar=1 fuerza descarga con nombre seguro/descriptivo.
     */
    public function photo(Request $request, int $id, string $lado)
    {
        $creditRequest = $this->findOrFail($id);
        $path = $lado === 'front' ? $creditRequest->id_front_path : ($lado === 'back' ? $creditRequest->id_back_path : null);

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $response = response()->file(Storage::disk('local')->path($path));

        if ($request->boolean('descargar')) {
            $side = $lado === 'front' ? 'frente' : 'reverso';
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'jpg';
            }
            $response->setContentDisposition(
                \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'solicitud-'.$creditRequest->id.'-'.$side.'.'.$extension
            );
        }

        return $response;
    }

    /** VERIFICAR → lista para decisión/aprobación del Bloque 9. */
    public function verify(Request $request, int $id, CobrosCreditRequestService $service): RedirectResponse
    {
        $creditRequest = $this->findOrFail($id);
        $motivo = (string) $request->input('motivo', '');
        $service->verify($this->company(), $creditRequest, $request->user(), $motivo);

        return redirect()
            ->route('cobros.owner.solicitudes.show', $creditRequest->id)
            ->with('success', 'Solicitud verificada · Lista para decisión.');
    }

    /** SOLICITAR CORRECCIÓN → vuelve al cobrador con motivo obligatorio. */
    public function correct(Request $request, int $id, CobrosCreditRequestService $service): RedirectResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $creditRequest = $this->findOrFail($id);
        $service->requestCorrection($this->company(), $creditRequest, $request->user(), $data['motivo']);

        return redirect()
            ->route('cobros.owner.solicitudes.show', $creditRequest->id)
            ->with('success', 'Corrección solicitada · El cobrador verá el motivo.');
    }

    /** RECHAZAR → terminal/inmutable, con motivo obligatorio. */
    public function reject(Request $request, int $id, CobrosCreditRequestService $service): RedirectResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        $creditRequest = $this->findOrFail($id);
        $service->reject($this->company(), $creditRequest, $request->user(), $data['motivo']);
        // 10) Decisión tomada: el pendiente accionable del dueño se atiende.
        self::markRequestBellRead($request->user(), $creditRequest->id);

        return redirect()
            ->route('cobros.owner.solicitudes.show', $creditRequest->id)
            ->with('success', 'Solicitud rechazada.');
    }

    /**
     * 9C.3 — oficina/prestamista ejecuta la consulta de evaluación.
     * Misma policy/consentimiento/auditoría que el cobrador; solo dentro de
     * expedientes legítimos existentes (nunca búsqueda arbitraria por cédula).
     */
    public function bureauCheck(Request $request, CobrosBureauService $bureau): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'identification_type' => ['nullable', 'string', 'max:20'],
            'identification' => ['required', 'string', 'max:50'],
            'autorizacion' => ['required', 'string', 'in:acepto'],
        ], [
            'autorizacion.required' => 'Debe autorizar la consulta de información antes de continuar.',
            'autorizacion.in' => 'Debe autorizar la consulta de información antes de continuar.',
        ]);

        return response()->json([
            'found' => $bureau->consultFor(
                $company,
                $request->user(),
                $data['identification_type'] ?? null,
                $data['identification']
            ),
        ]);
    }

    private function findOrFail(int $id): CobrosCreditRequest
    {
        // Global scope por company de la sesión activa → ajeno = 404 (IDOR).
        return CobrosCreditRequest::query()->findOrFail($id);
    }

    /**
     * Semántica Core de leído (alert_recipients.read_at): al abrir el detalle
     * se marca como leída SIN borrar historial; dismissed/status quedan intactos.
     */
    private function markBellRead(Request $request, CobrosCreditRequest $creditRequest): void
    {
        self::markRequestBellRead($request->user(), $creditRequest->id);
    }

    /**
     * 8) Campana del dueño: atender el detalle de la solicitud (o del crédito)
     * marca SOLO los recipients del usuario autenticado de ese trámite como
     * leídos. Nunca toca otros usuarios, otros tenants ni otros trámites.
     */
    public static function markRequestBellRead($user, int $requestId): void
    {
        if ($user === null) {
            return;
        }

        try {
            $recipient = \App\Models\AlertRecipient::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->whereHas('alert', function ($query) use ($requestId) {
                    $query->where('entity_type', 'cobros_credit_request')
                        ->where('entity_id', $requestId);
                })
                ->get();

            foreach ($recipient as $row) {
                $row->update(['read_at' => now()]);
            }
        } catch (\Throwable) {
            // La campana jamás bloquea la apertura del detalle.
        }
    }

    /** Resultado objetivo de identidad (Hacienda/local): null si no hay datos. */
    private function haciendaName(CobrosCreditRequest $creditRequest): ?string
    {
        $type = (string) ($creditRequest->identification_type ?? '');
        if ($type === '' || $creditRequest->identification === '') {
            return null;
        }

        try {
            $service = app(IdentificationNameLookupService::class);
            if (! $service->supports($this->company(), $type)) {
                return null;
            }

            return $service->lookup($this->company(), $type, $creditRequest->identification);
        } catch (Throwable) {
            return null;
        }
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('active_company_id'));
    }
}
