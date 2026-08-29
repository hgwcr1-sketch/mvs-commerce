<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Canton;
use App\Models\Company;
use App\Models\Country;
use App\Models\Customer;
use App\Models\District;
use App\Models\LoyaltyPortalCredential;
use App\Models\Province;
use App\Services\Loyalty\LoyaltyPortalDeliveryService;
use App\Services\PhoneNumberService;
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
                        ->orWhere('email', 'like', "%{$search}%");

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

        $customer = Customer::create($data);

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

        $phones = app(PhoneNumberService::class);
        $phoneNormalized = $phones->normalizePhone($customer->phone ?? $customer->mobile);
        $emailNormalized = $customer->email ? mb_strtolower(trim($customer->email)) : null;

        $username = null;
        if ($phoneNormalized) {
            $username = $phoneNormalized;
        } elseif ($emailNormalized && filter_var($emailNormalized, FILTER_VALIDATE_EMAIL)) {
            $username = $emailNormalized;
        }

        if (!$username) {
            return ['created' => false, 'error' => 'No se pudo crear acceso al Portal: el cliente no tiene teléfono ni correo válido.'];
        }

        // Validar unicidad dentro de la empresa
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

        $plainPassword = Str::password(12, true, true, false, false);
        // Asegurar que cumple reglas: si no, generar uno que cumpla
        if (!preg_match('/[a-z]/', $plainPassword) || !preg_match('/[A-Z]/', $plainPassword) || !preg_match('/[0-9]/', $plainPassword)) {
            $plainPassword = 'Aa1' . Str::random(9);
        }

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

        return view('clientes.show', [
            'customer' => $cliente,

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

        $cliente->update($data);

        return redirect()
            ->route('clientes.index')
            ->with('success', 'Cliente actualizado correctamente.');
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
                    ->orWhere('email', 'like', "%{$search}%");
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
