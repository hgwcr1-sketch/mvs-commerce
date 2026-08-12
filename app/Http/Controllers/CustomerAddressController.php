<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    /**
     * Guardar una nueva dirección del cliente.
     */
    public function store(Request $request, Customer $cliente)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'country_id' => 'nullable|exists:countries,id',
            'province_id' => 'nullable|exists:provinces,id',
            'canton_id' => 'nullable|exists:cantons,id',
            'district_id' => 'nullable|exists:districts,id',
            'address' => 'required|string',
            'is_primary' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        $validated['customer_id'] = $cliente->id;
        $validated['is_primary'] = $request->boolean('is_primary');

        if ($validated['is_primary']) {
            CustomerAddress::where('customer_id', $cliente->id)
                ->update(['is_primary' => false]);
        }

        CustomerAddress::create($validated);

        return redirect()
            ->route('clientes.show', ['cliente' => $cliente->id])
            ->with('success', 'Dirección agregada correctamente.');
    }

    /**
     * Marcar una dirección como principal.
     */
    public function setPrimary(Customer $cliente, CustomerAddress $direccion)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);
        $this->ensureAddressBelongsToCustomer($direccion, $cliente);

        CustomerAddress::where('customer_id', $cliente->id)
            ->update(['is_primary' => false]);

        $direccion->update([
            'is_primary' => true,
        ]);

        return redirect()
            ->route('clientes.show', ['cliente' => $cliente->id])
            ->with('success', 'Dirección principal actualizada correctamente.');
    }

    /**
     * Eliminar una dirección.
     */
    public function destroy(Customer $cliente, CustomerAddress $direccion)
    {
        $this->ensureCustomerBelongsToActiveCompany($cliente);
        $this->ensureAddressBelongsToCustomer($direccion, $cliente);

        $direccion->delete();

        return redirect()
            ->route('clientes.show', ['cliente' => $cliente->id])
            ->with('success', 'Dirección eliminada correctamente.');
    }

    private function ensureCustomerBelongsToActiveCompany(Customer $customer): void
    {
        abort_unless(
            (int) $customer->company_id === (int) session('active_company_id'),
            404
        );
    }

    private function ensureAddressBelongsToCustomer(
        CustomerAddress $address,
        Customer $customer
    ): void {
        abort_unless($address->customer_id === $customer->id, 404);
    }
}
