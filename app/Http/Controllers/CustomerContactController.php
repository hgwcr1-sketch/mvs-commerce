<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerContact;
use Illuminate\Http\Request;

class CustomerContactController extends Controller
{
    public function store(Request $request, Customer $cliente)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'position' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'mobile' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'is_primary' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $validated['customer_id'] = $cliente->id;
$validated['is_primary'] = $request->boolean('is_primary');

if ($validated['is_primary']) {
    CustomerContact::where('customer_id', $cliente->id)
        ->update(['is_primary' => false]);
}

CustomerContact::create($validated);


        return redirect()
            ->route('clientes.show', ['cliente' => $cliente->id])
            ->with('success', 'Contacto agregado correctamente.');
    }

    public function destroy(Customer $cliente, CustomerContact $contacto)
    {
        if ($contacto->customer_id !== $cliente->id) {
            abort(404);
        }

        $contacto->delete();

        return redirect()
            ->route('clientes.show', ['cliente' => $cliente->id])
            ->with('success', 'Contacto eliminado correctamente.');
    }

    public function setPrimary(Customer $cliente, CustomerContact $contacto)
{
    if ($contacto->customer_id !== $cliente->id) {
        abort(404);
    }

    CustomerContact::where('customer_id', $cliente->id)
        ->update(['is_primary' => false]);

    $contacto->update([
        'is_primary' => true
    ]);

    return redirect()
        ->route('clientes.show', ['cliente' => $cliente->id])
        ->with('success', 'Contacto principal actualizado correctamente.');
}
}