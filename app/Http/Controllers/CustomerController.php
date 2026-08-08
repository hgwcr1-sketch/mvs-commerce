<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Country;
use App\Models\Province;
use App\Models\Canton;
use App\Models\District;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Mostrar listado de clientes.
     */
    public function index(Request $request)
    {
        $search = $request->search;
        $status = $request->status;
$type = $request->type;

        $customers = Customer::query()

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

            'total' => Customer::count(),

            'active' => Customer::where('is_active', true)->count(),

            'companies' => Customer::where('customer_type', 'company')->count(),

            'individuals' => Customer::where('customer_type', 'individual')->count(),

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
    return view('clientes.create', [

        'customer' => new Customer(),

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

    Customer::create($data);

    return redirect()
        ->route('clientes.index')
        ->with('success', 'Cliente registrado correctamente.');
}

    /**
     * Mostrar cliente.
     */

    public function show(Customer $cliente)
{
    $cliente->load([
        'country',
        'province',
        'canton',
        'district',
        'contacts',
        'addresses.country',
        'addresses.province',
        'addresses.canton',
        'addresses.district'
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
    return view('clientes.edit', [

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
     * Actualizar cliente.
     */
   public function update(UpdateCustomerRequest $request, Customer $cliente)
{
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
    $cliente->update([
        'is_active' => !$cliente->is_active
    ]);

    return redirect()
        ->route('clientes.index')
        ->with('success', 'Estado del cliente actualizado correctamente.');
}

    public function destroy(Customer $cliente)
{
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
            'name'
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
            'name'
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
            'name'
        ]);
}
/**
 * Búsqueda rápida de clientes.
 */
public function search(Request $request)
{
    $search = $request->get('search');

    if (!$search) {
        return response()->json([]);
    }

    $customers = Customer::query()
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
            'email'
        ]);

    return response()->json($customers);
}
}