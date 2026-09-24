<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Canton;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerOneTimeToken;
use App\Models\District;
use App\Models\LoyaltyPortalCredential;
use App\Models\Province;
use App\Services\CustomerOneTimeTokenService;
use App\Services\CustomerPublicCodeService;
use App\Services\Loyalty\LoyaltyPortalDeliveryService;
use App\Services\PhoneNumberService;
use App\Services\RouteosAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Mostrar listado de clientes.
     */
    public function index(Request $request)
    {
        $companyId = $this->activeCompanyId();
        $search = $request->search;
        $status = $request->status;
        $type = $request->type;

        $customers = Customer::forCompany($companyId)

            ->when($search, function ($query) use ($search) {

                $query->where(function ($q) use ($search) {

                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('identification', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('customer_code', 'like', "%{$search}%");

                });

            })
            ->when($status !== null && $status !== '', function ($query) use ($status) {

                $query->where('is_active', $status);

            })

            ->when($type, function ($query) use ($type) {

                $query->where('customer_type', $type);

            })

            ->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [

            'total' => Customer::forCompany($companyId)->count(),

            'active' => Customer::forCompany($companyId)->where('is_active', true)->count(),

            'companies' => Customer::forCompany($companyId)->where('customer_type', 'company')->count(),

            'individuals' => Customer::forCompany($companyId)->where('customer_type', 'individual')->count(),

        ];

        return view('clientes.index', compact(
            'customers',
            'stats',
            'search',
            'status',
            'type'
        ));
    }

    /**
     * Mostrar formulario de creación.
     */
    public function create()
    {
        $defaultPhoneCountryCode = Company::query()
            ->whereKey($this->activeCompanyId())
            ->value('default_phone_country_code');

        return view('clientes.create', [

            'customer' => new Customer,
            'defaultPhoneCountryCode' => $defaultPhoneCountryCode,

            'countries' => Country::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'provinces' => Province::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'cantons' => Canton::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'districts' => District::where('is_active', true)
                ->orderBy('name')
                ->get(),

        ]);
    }

    /**
     * Guardar cliente.
     */
    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();

        $data['accepts_email_invoice'] = $request->boolean('accepts_email_invoice');
        $data['is_active'] = $request->boolean('is_active');
        $data['company_id'] = $this->activeCompanyId();
        $createPortalAccess = $request->boolean('create_portal_access');
        unset($data['create_portal_access']);

        // R01 RouteOS: coordenadas capturadas al crear quedan validadas por quien las envió.
        $geoCoordinatesProvided = array_key_exists('latitude', $data)
            && $data['latitude'] !== null
            && $data['longitude'] !== null;

        $customer = Customer::create($data);

        if ($geoCoordinatesProvided) {
            // location_validated_at/by no son fillable por diseño: se sellan explícitamente.
            $customer->forceFill([
                'location_validated_at' => now(),
                'location_validated_by' => $request->user()?->id,
            ])->save();
        }

        // R01 RouteOS: auditar inicialización de crédito y ubicación.
        $this->auditCustomerRouteosFields($customer, null, $data, $request, 'creacion');

        $portalResult = null;
        if ($createPortalAccess) {
            $portalResult = $this->createPortalAccessForCustomer($customer, $request);
            if ($portalResult['created']) {
                $delivery = app(LoyaltyPortalDeliveryService::class)->build(
                    Company::query()->findOrFail($this->activeCompanyId()),
                    $customer,
                    $portalResult['username'],
                    $portalResult['password']
                );
                $portalResult = array_merge($portalResult, $delivery);
                return redirect()
                    ->route('clientes.index')
                    ->with('success', 'Cliente registrado correctamente. Acceso al Portal creado: usuario ' . $portalResult['username'] . ' / contraseña temporal ' . $portalResult['password'])
                    ->with('portal_access', $portalResult);
            }
            if ($portalResult['error']) {
                return redirect()
                    ->route('clientes.index')
                    ->with('success', 'Cliente registrado correctamente.')
                    ->with('warning', $portalResult['error']);
            }
        }

        return redirect()
            ->route('clientes.index')
            ->with('success', 'Cliente registrado correctamente.');
    }

    private function createPortalAccessForCustomer(Customer $customer, Request $request): array
    {
        $companyId = (int) $customer->company_id;
        // No duplicar si ya existe
        if (LoyaltyPortalCredential::query()->where('customer_id', $customer->id)->exists()) {
            return ['created' => false, 'error' => 'Este cliente ya tiene acceso al Portal.'];
        }

        $resolver = app(\App\Services\Loyalty\LoyaltyPortalUsernameResolver::class);
        $resolved = $resolver->resolve($customer, Company::query()->findOrFail($companyId));
        $username = $resolved['username'];
        $emailNormalized = $resolved['emailNormalized'];
        $phoneNormalized = $resolved['phoneNormalized'];

        // Validar unicidad dentro de la empresa (usa misma regla que el resolver)
        $exists = LoyaltyPortalCredential::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($username, $emailNormalized) {
                $q->where('username', $username);
                if ($emailNormalized) {
                    $q->orWhere('email', $emailNormalized);
                }
            })->exists();

        if ($exists) {
            return ['created' => false, 'error' => 'El usuario o correo ya está registrado en esta empresa.'];
        }
        if (!$username) {
            return ['created' => false, 'error' => 'No se pudo crear acceso al Portal: el cliente no tiene teléfono ni correo válido.'];
        }

        $plainPassword = chr(random_int(97, 122)).chr(random_int(65, 90)).str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $credential = LoyaltyPortalCredential::create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'username' => $username,
            'email' => $emailNormalized ?? $username . '@portal.local',
            'password' => $plainPassword,
            'is_active' => true,
            'must_change_password' => true,
        ]);

        return ['created' => true, 'username' => $username, 'password' => $plainPassword, 'email' => $credential->email];
    }

    /**
     * Mostrar cliente.
     */
    public function show(Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);

        $cliente->load([
            'country',
            'province',
            'canton',
            'district',
            'contacts',
            'addresses.country',
            'addresses.province',
            'addresses.canton',
            'addresses.district',
        ]);

        $publicCodeService = app(\App\Services\CustomerPublicCodeService::class);
        $publicCodeService->ensure($cliente);
        $cliente->refresh();
        $qrSvg = null;
        $barcodeSvg = null;
        try {
            $qrSvg = $publicCodeService->qrSvg($cliente);
        } catch (\Throwable $e) {
            $qrSvg = null;
        }
        try {
            $barcodeSvg = $publicCodeService->barcodeSvg($cliente);
        } catch (\Throwable $e) {
            $barcodeSvg = null;
        }

        return view('clientes.show', [
            'customer' => $cliente,
            'qrSvg' => $qrSvg,
            'barcodeSvg' => $barcodeSvg,

            'countries' => Country::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'provinces' => Province::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'cantons' => Canton::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'districts' => District::where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);

    }

    /**
     * Mostrar formulario de edición.
     */
    public function edit(Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);

        return view('clientes.edit', [

            'customer' => $cliente,
            'defaultPhoneCountryCode' => null,

            'countries' => Country::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'provinces' => Province::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'cantons' => Canton::where('is_active', true)
                ->orderBy('name')
                ->get(),

            'districts' => District::where('is_active', true)
                ->orderBy('name')
                ->get(),

        ]);
    }

    /**
     * Actualizar cliente.
     */
    public function update(UpdateCustomerRequest $request, Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);

        $data = $request->validated();

        $data['accepts_email_invoice'] = $request->boolean('accepts_email_invoice');
        $data['is_active'] = $request->boolean('is_active');

        $routeosBefore = $this->routeosSnapshot($cliente);

        $cliente->update($data);

        // R01 RouteOS: si se envían coordenadas y cambian respecto a las previas,
        // la ubicación queda validada por el usuario que la capturó.
        if ($cliente->latitude !== null && $cliente->longitude !== null
            && ($this->decimalChanged($routeosBefore['latitude'], $cliente->latitude, 8)
                || $this->decimalChanged($routeosBefore['longitude'], $cliente->longitude, 8))) {
            $cliente->forceFill([
                'location_validated_at' => now(),
                'location_validated_by' => $request->user()?->id,
            ])->save();
        }

        // R01 RouteOS: auditoría de crédito y ubicación del cliente.
        $this->auditCustomerRouteosFields($cliente, $routeosBefore, $cliente->fresh()->only($this->routeosFields()), $request, 'actualizacion');

        return redirect()
            ->route('clientes.index')
            ->with('success', 'Cliente actualizado correctamente.');
    }

    /**
     * Campos RouteOS relevantes para auditoría (R01).
     */
    private function routeosFields(): array
    {
        return [
            'credit_limit',
            'credit_days',
            'latitude',
            'longitude',
            'location_reference',
            'location_validated_at',
            'location_validated_by',
        ];
    }

    private function routeosSnapshot(Customer $customer): array
    {
        $snapshot = $customer->only($this->routeosFields());
        $snapshot['location_validated_at'] = $customer->location_validated_at?->toIso8601String();

        return $snapshot;
    }

    /**
     * Comparación decimal segura para valores nullable (R01 RouteOS).
     * Trata null como 0: pasar de null a null no es un cambio.
     */
    private function decimalChanged(mixed $previous, mixed $current, int $scale): bool
    {
        $previous = $previous === null ? '0' : (string) $previous;
        $current = $current === null ? '0' : (string) $current;

        return bccomp($previous, $current, $scale) !== 0;
    }

    private function auditCustomerRouteosFields(
        Customer $customer,
        ?array $before,
        array $after,
        Request $request,
        string $context,
    ): void {
        $audit = app(RouteosAuditService::class);

        $creditChanged = $before === null
            || $this->decimalChanged($before['credit_limit'] ?? null, $after['credit_limit'] ?? null, 2)
            || (int) ($before['credit_days'] ?? 0) !== (int) ($after['credit_days'] ?? 0);

        if ($creditChanged) {
            $action = $before === null ? 'routeos.credito.inicializado' : 'routeos.credito.actualizado';
            $audit->log(
                (int) $customer->company_id,
                session('active_branch_id'),
                $action,
                $customer,
                $before !== null ? [
                    'credit_limit' => $before['credit_limit'],
                    'credit_days' => $before['credit_days'],
                ] : null,
                [
                    'credit_limit' => $after['credit_limit'],
                    'credit_days' => $after['credit_days'],
                ],
                ['context' => $context],
                $request->user(),
            );
        }

        $locationChanged = $before !== null && (
            $this->decimalChanged($before['latitude'] ?? null, $after['latitude'] ?? null, 8)
            || $this->decimalChanged($before['longitude'] ?? null, $after['longitude'] ?? null, 8)
            || ($before['location_reference'] ?? null) !== ($after['location_reference'] ?? null)
        );

        if ($before === null || $locationChanged) {
            $hasNewLocation = ($after['latitude'] ?? null) !== null && ($after['longitude'] ?? null) !== null;
            $audit->log(
                (int) $customer->company_id,
                session('active_branch_id'),
                $hasNewLocation
                    ? ($before === null ? 'routeos.ubicacion.registrada' : 'routeos.ubicacion.actualizada')
                    : 'routeos.ubicacion.limpiada',
                $customer,
                $before !== null ? [
                    'latitude' => $before['latitude'],
                    'longitude' => $before['longitude'],
                    'location_reference' => $before['location_reference'],
                ] : null,
                [
                    'latitude' => $after['latitude'] ?? null,
                    'longitude' => $after['longitude'] ?? null,
                    'location_reference' => $after['location_reference'] ?? null,
                    'location_validated' => $customer->fresh()->isLocationValidated(),
                ],
                ['context' => $context],
                $request->user(),
            );
        }
    }

    /**
     * Eliminar cliente.
     */
    public function toggleStatus(Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);

        $cliente->update([
            'is_active' => ! $cliente->is_active,
        ]);

        return redirect()
            ->route('clientes.index')
            ->with('success', 'Estado del cliente actualizado correctamente.');
    }

    public function destroy(Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);

        $cliente->delete();

        return redirect()
            ->route('clientes.index')
            ->with('success', 'Cliente eliminado correctamente.');
    }

    public function generateOneTimeToken(Request $request, Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);
        $company = Company::query()->findOrFail($this->activeCompanyId());
        $result = app(CustomerOneTimeTokenService::class)->generate($cliente, $company, 'redeem', 5);

        if ($request->expectsJson()) {
            return response()->json([
                'public_code' => $cliente->public_code,
                'pin' => $result['plain'],
                'expires_at' => $result['token']->expires_at->toIso8601String(),
                'qrSvg' => $result['qrSvg'],
            ]);
        }

        return back()->with('success', 'PIN temporal generado. Vence en 5 minutos y es de un solo uso.')->with('one_time_pin', $result['plain'])->with('one_time_qr', $result['qrSvg'])->with('one_time_expires', $result['token']->expires_at);
    }

    public function verifyOneTimeToken(Request $request, Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);
        $company = Company::query()->findOrFail($this->activeCompanyId());
        $data = $request->validate(['pin' => ['required', 'string', 'max:20']]);
        try {
            app(CustomerOneTimeTokenService::class)->verify($cliente, $company, $data['pin'], 'redeem');
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }
            return back()->withErrors($e->errors());
        }

        if ($request->expectsJson()) {
            return response()->json(['verified' => true, 'message' => 'PIN verificado.']);
        }

        return back()->with('success', 'PIN verificado correctamente. Puede proceder con el canje.');
    }

    /**
     * Obtener provincias por país.
     */
    public function provinces(Country $country)
    {
        return Province::where('country_id', $country->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }

    /**
     * Obtener cantones por provincia.
     */
    public function cantons(Province $province)
    {
        return Canton::where('province_id', $province->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }

    /**
     * Obtener distritos por cantón.
     */
    public function districts(Canton $canton)
    {
        return District::where('canton_id', $canton->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }

    /**
     * Búsqueda rápida de clientes.
     */
    public function search(Request $request)
    {
        $search = $request->get('search');

        if (! $search) {
            return response()->json([]);
        }

        $customers = Customer::forCompany($this->activeCompanyId())
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('identification', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(8)
            ->get([
                'id',
                'name',
                'identification',
                'phone',
                'mobile',
                'email',
                'customer_code',
            ]);

        return response()->json($customers);
    }

    private function activeCompanyId(): int
    {
        $companyId = session('active_company_id');

        abort_unless($companyId, 403, 'No hay una empresa activa.');

        return (int) $companyId;
    }

    private function ensureCustomerBelongsToActiveCompany(Customer $customer): void
    {
        abort_unless(
            (int) $customer->company_id === $this->activeCompanyId(),
            404
        );
    }
}
