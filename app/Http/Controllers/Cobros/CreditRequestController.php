<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCobrosCreditRequest;
use App\Models\CobrosCreditRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Cobros\CobrosBureauService;
use App\Services\Cobros\CobrosCreditRequestService;
use App\Services\Cobros\CobrosRouteContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** BLOQUE 7A — nueva solicitud de crédito del cobrador (sin edición posterior). */
class CreditRequestController extends Controller
{
    public function create(Request $request): View
    {
        $user = $request->user();
        $route = app(CobrosRouteContext::class)->current($user);
        $resume = null;

        // BLOQUE 8 — reenvío de corrección: prellena desde la MISMA solicitud.
        if ($request->filled('resume')) {
            $resume = CobrosCreditRequest::query()
                ->forCollector($user->id)
                ->findOrFail((int) $request->input('resume'));

            if ($resume->cobros_route_id !== $route->id || $resume->status !== CobrosCreditRequest::STATUS_CORRECTION) {
                abort(404, 'Esta solicitud no está disponible para corrección.');
            }

            session()->flashInput([
                'applicant_type' => $resume->applicant_type,
                'identification_type' => $resume->identification_type,
                'identification' => $resume->identification,
                'name' => $resume->name,
                'phone' => $resume->phone,
                'address' => $resume->address,
                'amount_requested' => $resume->amount_requested,
                'notes' => $resume->notes,
                'customer_id' => $resume->customer_id,
                'resume_id' => $resume->id,
            ]);
        }

        // 2) MIS CLIENTES → NUEVA SOLICITUD: preselección segura del customer
        //    (mismo flujo CLIENTE EXISTENTE; nunca crea Customer duplicado).
        $preselect = null;
        $customerDocs = ['front' => false, 'back' => false];
        if ($request->filled('customer')) {
            $preselect = Customer::query()
                ->where('company_id', $this->company()->id)
                ->whereNull('deleted_at')
                ->findOrFail((int) $request->input('customer'));

            session()->flashInput([
                'applicant_type' => 'existente',
                'customer_id' => $preselect->id,
                'identification' => $preselect->identification,
                'name' => $preselect->name,
                'phone' => $preselect->phone,
                'address' => $preselect->address,
            ]);
        }

        // 4) Documentos de identificación ya registrados en el expediente de
        //    ESTA empresa: no se exige volver a subirlos.
        if (($preselect?->id ?? old('customer_id')) !== null && ($preselect?->id ?? (int) old('customer_id')) > 0) {
            $prior = CobrosCreditRequest::query()->forCompany($this->company()->id)
                ->where('customer_id', (int) ($preselect?->id ?? old('customer_id')))
                ->orderByDesc('id')
                ->get(['id_front_path', 'id_back_path'])
                ->first();
            $customerDocs = [
                'front' => $prior !== null && $prior->id_front_path !== null,
                'back' => $prior !== null && $prior->id_back_path !== null,
            ];
        }

        return view('cobros.collector.credit-request-create', [
            'company' => $this->company(),
            'route' => $route,
            'resumeId' => $resume?->id,
            'resume' => $resume,
            'preselect' => $preselect,
            'customerDocs' => $customerDocs,
            // 9C.3: bajo owner_required el cobrador captura y envía, pero la
            // consulta la ejecuta la oficina (la UI ni siquiera ofrece el call).
            'canConsultBureau' => ! \App\Models\CobrosCollectorAuthorityPolicy::requiresOwner(
                (int) $this->company()->id,
                (int) $user->id
            ),
        ]);
    }

    /**
     * Paso de revisión: valida datos + FREnte/REVERSO, temporaliza las fotos en
     * disco privado con un token de sesión y NO persiste la solicitud todavía.
     */
    public function review(StoreCobrosCreditRequest $request): View
    {
        $company = $this->company();
        $user = $request->user();
        $route = app(CobrosRouteContext::class)->current($user);
        $data = $request->validated();

        // BLOQUE 8 — el reenvío debe apuntar a una corrección propia de esta ruta.
        $resume = null;
        if (! empty($data['resume_id'])) {
            $resume = CobrosCreditRequest::query()->forCollector($user->id)->find((int) $data['resume_id']);
            if ($resume === null || $resume->cobros_route_id !== $route->id || $resume->status !== CobrosCreditRequest::STATUS_CORRECTION) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'resume_id' => 'Esta solicitud no está disponible para corrección.',
                ]);
            }
        }

        $token = Str::random(40);
        $base = 'cobros/credit_requests/tmp/'.$company->id.'/'.$user->id.'/'.$token;
        // BLOQUE 8 — corrección: sin archivo nuevo se conservan las fotos
        // existentes (payload null → store NO mueve nada de la evidencia previa).
        $front = $request->hasFile('id_front')
            ? $request->file('id_front')->storeAs($base, 'frente.'.$this->ext($request, 'id_front'), 'local')
            : null;
        $back = $request->hasFile('id_back')
            ? $request->file('id_back')->storeAs($base, 'reverso.'.$this->ext($request, 'id_back'), 'local')
            : null;

        $docFront = $front;
        $docBack = $back;
        if ($resume !== null) {
            $front ??= null;
            $back ??= null;
        } elseif ($front === null || $back === null) {
            // 4) CLIENTE EXISTENTE: referenciar las fotos ya registradas en el
            //    expediente de ESTA empresa. NO se mueven ni se copian: la
            //    evidencia histórica se conserva intacta y jamás se toma de
            //    otro tenant.
            $prior = (int) ($data['customer_id'] ?? 0) > 0
                ? CobrosCreditRequest::query()->forCompany($company->id)
                    ->where('customer_id', (int) $data['customer_id'])
                    ->whereNotNull('id_front_path')
                    ->orderByDesc('id')
                    ->get(['id_front_path', 'id_back_path'])
                    ->first()
                : null;
            $docFront ??= $prior?->id_front_path;
            $docBack ??= $prior?->id_back_path;
            if ($docFront === null || $docBack === null) {
                $missing = $docFront === null ? 'id_front' : 'id_back';
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $missing => 'La foto de la identificación es obligatoria. Falta registrar un documento del expediente.',
                ]);
            }
        }

        $customer = null;
        if (($data['applicant_type'] ?? '') === 'existente') {
            $customer = Customer::query()->where('company_id', $company->id)
                ->whereKey($data['customer_id'])->whereNull('deleted_at')->firstOrFail();
            $data['identification'] = (string) $customer->identification;
            $data['name'] = (string) $customer->name;
            $data['phone'] = $customer->phone ?? ($data['phone'] ?? null);
        }

        $payload = [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'route_id' => $route->id,
            'data' => $data,
            'front' => $front,
            'back' => $back,
            // Referencia (sin mover) a la evidencia ya registrada del expediente.
            'doc_front' => $resume !== null ? null : $docFront,
            'doc_back' => $resume !== null ? null : $docBack,
        ];
        session()->put('credit_request_review_'.$token, $payload);
        session()->flashInput($data);

        return view('cobros.collector.credit-request-review', [
            'company' => $company,
            'route' => $route,
            'data' => $data,
            'customer' => $customer,
            'token' => $token,
            'front' => $front,
            'back' => $back,
        ]);
    }

    /** Envío: consume el token de revisión (fotos ya validadas) y crea la solicitud. */
    public function store(StoreCobrosCreditRequest $request, CobrosCreditRequestService $service): RedirectResponse
    {
        $company = $this->company();
        $user = $request->user();
        $route = app(CobrosRouteContext::class)->current($user);
        $data = $request->validated();

        $key = 'credit_request_review_'.$data['review_token'];
        $payload = session()->get($key);
        if (! is_array($payload)
            || ($payload['company_id'] ?? null) !== $company->id
            || ($payload['user_id'] ?? null) !== $user->id
            || ($payload['route_id'] ?? null) !== $route->id
            || (($payload['front'] ?? null) !== null && ! Storage::disk('local')->exists($payload['front']))
            || (($payload['back'] ?? null) !== null && ! Storage::disk('local')->exists($payload['back']))
            || (($payload['front'] ?? null) === null && ($payload['back'] ?? null) === null
                && empty($payload['data']['resume_id'])
                && ($payload['doc_front'] ?? null) === null && ($payload['doc_back'] ?? null) === null)) {
            session()->forget($key);

            return redirect()
                ->route('cobros.collector.credit-requests.create')
                ->withInput($data)
                ->withErrors(['review_token' => 'La revisión expiró. Repita el paso de revisión con sus fotos.']);
        }

        // Los datos enviados provienen de la revisión validada.
        $data = $payload['data'];
        $resumeId = (int) ($data['resume_id'] ?? 0);

        // Mueve SOLO las evidencias nuevas del temporal al directorio definitivo;
        // las no reemplazadas conservan su ruta original (integridad de historial).
        $final = 'cobros/credit_requests/'.$company->id.'/'.$user->id.'/'.now()->format('Ymd_His').'_'.Str::random(6);
        $front = null;
        $back = null;
        if (($payload['front'] ?? null) !== null) {
            $front = $final.'/'.basename($payload['front']);
            Storage::disk('local')->move($payload['front'], $front);
        }
        if (($payload['back'] ?? null) !== null) {
            $back = $final.'/'.basename($payload['back']);
            Storage::disk('local')->move($payload['back'], $back);
        }
        // 4) Sin foto nueva: se referencia la evidencia ya registrada del
        //    expediente (nunca se mueve ni se destruye la histórica).
        $front ??= $payload['doc_front'] ?? null;
        $back ??= $payload['doc_back'] ?? null;
        session()->forget($key);

        // BLOQUE 9C: el mensaje post-envío depende de la política real del
        // cobrador (owner_required espera al dueño; collector_authorized el
        // siguiente paso es SU revisión con el cliente).
        $requiresOwner = \App\Models\CobrosCollectorAuthorityPolicy::requiresOwner(
            (int) $company->id,
            (int) $user->id
        );
        $nextStep = $requiresOwner
            ? 'Pendiente de revisión.'
            : 'Continúe con la revisión de la información del cliente.';
        // collector_authorized: Mario continúa en SU pantalla de evaluación;
        // owner_required: vuelve a su circuito (lista, espera del dueño).
        $target = $requiresOwner
            ? route('cobros.collector.credit-requests.index')
            : null;

        if ($resumeId > 0) {
            // BLOQUE 8 — corrección: reutiliza la fila (historial/evidencias previas
            // conservadas en disco) y vuelve a pendiente.
            $resume = CobrosCreditRequest::query()->forCollector($user->id)->findOrFail($resumeId);
            $resumed = $service->resubmit($company, $route, $user, $resume, $data, $front, $back);

            return redirect()
                ->to($target ?? route('cobros.collector.credit-requests.evaluate', $resumed->id))
                ->with('success', 'Corrección reenviada · '.$nextStep);
        }

        $created = $service->submit($company, $route, $user, $data, $front, $back);

        return redirect()
            ->to($target ?? route('cobros.collector.credit-requests.evaluate', $created->id))
            ->with('success', 'Solicitud enviada · '.$nextStep);
    }

    /** Mis solicitudes: company + collector + ruta activa desde el contexto. */
    public function index(Request $request): View
    {
        $route = app(CobrosRouteContext::class)->current($request->user());

        $requests = CobrosCreditRequest::forCompany((int) session('active_company_id'))
            ->forCollector($request->user()->id)
            ->forRoute($route->id)
            ->with('logs')
            ->orderByDesc('id')
            ->get();

        // 9C: enlace directo a la entrega cuando el préstamo ya fue preparado.
        $creditIds = \App\Models\CobrosCredit::whereIn('credit_request_id', $requests->pluck('id'))
            ->pluck('id', 'credit_request_id');

        $credits = \App\Models\CobrosCredit::whereIn('credit_request_id', $requests->pluck('id'))
            ->get(['id', 'credit_request_id', 'status'])
            ->keyBy('credit_request_id');

        return view('cobros.collector.credit-request-index', [
            'company' => $this->company(),
            'route' => $route,
            'requests' => $requests,
            'creditIds' => $creditIds,
            'credits' => $credits,
            // 13) Solo en modalidad collector_authorized el cobrador revisa
            // sus propios datos; con owner_required verifica el dueño.
            'canSelfVerify' => ! \App\Models\CobrosCollectorAuthorityPolicy::requiresOwner(
                (int) session('active_company_id'),
                $request->user()->id
            ),
        ]);
    }

    /** Typeahead de CLIENTE EXISTENTE: solo clientes de la empresa activa. */
    public function searchCustomers(Request $request): JsonResponse
    {
        $company = $this->company();
        $data = $request->validate([
            'q' => ['required', 'string', 'max:60'],
        ]);

        // 3) Búsqueda compartida (nombre/cédula/teléfono, acentos/mayúsculas).
        //    Aislamiento SIEMPRE company-scoped en el backend.
        $customers = Customer::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->tap(fn ($query) => \App\Support\Cobros\CustomerSearch::apply($query, $data['q']))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'identification', 'phone']);

        return response()->json(['customers' => $customers]);
    }

    /**
     * 7B — Buró DENTRO del flujo legítimo de Nueva solicitud.
     * Solo confirma existencia (sin nombre ni documentos de otras companies)
     * y audita cada acceso con company/actor/purpose (identity_accessed).
     * No es un buscador libre: no crea identidad ni expone datos ajenos.
     *
     * 9C — gate: la consulta exige autorización explícita del cliente
     * (propósito + versión del texto quedarán en el audit trail).
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

        // 9C.3: la policy real (company+collector) decide si puede consultar;
        // la auditoría (actor/purpose/versión/contexto) vive en el servicio.
        return response()->json([
            'found' => $bureau->consultFor(
                $company,
                $request->user(),
                $data['identification_type'] ?? null,
                $data['identification']
            ),
        ]);
    }

    /**
     * 9C-B: pantalla de EVALUACIÓN del cobrador (collector_authorized).
     * Datos + fotos privadas + consentimiento/consulta + confirmación manual
     * (REVISAR Y CONFIRMAR DATOS → Verificada). Solo suyo, su company y su
     * ruta activa; con owner_required esta pantalla no existe (circuito del
     * dueño). La consulta nunca verifica ni aprueba automáticamente.
     */
    public function evaluate(Request $request, int $solicitud): View
    {
        $company = $this->company();
        $user = $request->user();

        $row = CobrosCreditRequest::forCompany((int) $company->id)->findOrFail($solicitud);
        if ($row->collector_user_id !== $user->id) {
            abort(403, 'Esta solicitud no le pertenece.');
        }
        $route = app(CobrosRouteContext::class)->current($user);
        if ((int) $row->cobros_route_id !== (int) $route->id) {
            abort(403, 'Esta solicitud pertenece a otra ruta.');
        }
        if (\App\Models\CobrosCollectorAuthorityPolicy::requiresOwner((int) $company->id, (int) $user->id)) {
            abort(403, 'Esta solicitud espera la revisión del dueño.');
        }
        if ($row->status !== CobrosCreditRequest::STATUS_PENDING) {
            return redirect()->route('cobros.collector.credit-requests.index');
        }

        return view('cobros.collector.credit-request-evaluate', [
            'company' => $company,
            'creditRequest' => $row,
        ]);
    }

    /** Fotos de identificación PRIVADAS (disco local, nunca públicas). */
    public function photo(Request $request, int $solicitud, string $lado)
    {
        $company = $this->company();
        $user = $request->user();

        $row = CobrosCreditRequest::forCompany((int) $company->id)->findOrFail($solicitud);
        if ($row->collector_user_id !== $user->id) {
            abort(403, 'Esta solicitud no le pertenece.');
        }
        $route = app(CobrosRouteContext::class)->current($user);
        if ((int) $row->cobros_route_id !== (int) $route->id) {
            abort(403, 'Esta solicitud pertenece a otra ruta.');
        }

        $path = $lado === 'front' ? $row->id_front_path : ($lado === 'back' ? $row->id_back_path : null);
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    private function ext(StoreCobrosCreditRequest $request, string $field): string
    {
        $extension = strtolower($request->file($field)->getClientOriginalExtension() ?: 'jpg');

        return in_array($extension, ['jpeg', 'jpg', 'png', 'webp'], true) ? $extension : 'jpg';
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('active_company_id'));
    }
}
