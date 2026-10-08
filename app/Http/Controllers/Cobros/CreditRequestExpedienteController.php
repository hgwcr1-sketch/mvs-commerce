<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosAssignment;
use App\Models\CobrosCreditExpediente;
use App\Models\CobrosCreditRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Cobros\CobrosExpedienteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * 10A - expediente PERMANENTE DEL CLIENTE (company + customer): trabajo/
 * ingresos, señas, referencias, fiador opcional y documentos adicionales.
 * Se abre desde la ficha "VER CLIENTE", existe con o sin solicitud, y
 * sobrevive a rechazo/cancelación de solicitudes. Solo captura.
 */
class CreditRequestExpedienteController extends Controller
{
    public function show(Request $request, Customer $customer): View
    {
        $this->accessible($request, $customer);
        $company = $this->company();
        $isOwner = $request->user()->hasPermission('cobros.portal_dueno', $company);
        $expediente = $this->expediente($company, $customer);

        return view('cobros.collector.credit-request-expediente', [
            'customer' => $customer,
            'company' => $company,
            'expediente' => $expediente,
            'ownerMode' => $isOwner,
            'storedReferences' => old('references', $expediente?->references
                ->map(fn ($reference) => ['name' => $reference->name, 'phone' => $reference->phone, 'relationship' => $reference->relationship])
                ->values()->all() ?? []),
            'saveUrl' => $isOwner
                ? route('cobros.owner.customers.expediente.save', $customer->id)
                : route('cobros.collector.customers.expediente.save', $customer->id),
            'backUrl' => $isOwner
                ? route('cobros.owner.customers.show', $customer->id)
                : route('cobros.collector.portfolio.show', $customer->id),
        ]);
    }

    public function save(Request $request, Customer $customer, CobrosExpedienteService $service): RedirectResponse
    {
        $this->accessible($request, $customer);
        $company = $this->company();

        // Filas de referencias sin nombre no se envían (el 2do campo vacío del formulario).
        $references = $request->input('references');
        if (is_array($references)) {
            $request->merge(['references' => array_values(array_filter(
                $references,
                fn ($reference) => is_array($reference) && trim((string) ($reference['name'] ?? '')) !== ''
            ))]);
        }

        $validated = $request->validate($this->rules($request));

        $files = [];
        foreach (CobrosExpedienteService::DOC_TYPES as $type) {
            if ($request->hasFile('doc_'.$type)) {
                $files[$type] = $request->file('doc_'.$type);
            }
        }

        $service->save(
            $company,
            $customer,
            $request->user(),
            Arr::except($validated, array_map(fn ($type) => 'doc_'.$type, CobrosExpedienteService::DOC_TYPES)),
            $files,
            $this->linkedRequest($request, $company, $customer)
        );

        return back()->with('expediente_saved', 'Expediente guardado correctamente.');
    }

    /** Documento adicional PRIVADO: disco local, autoridad completa, nunca URL pública. */
    public function document(Request $request, Customer $customer, string $type)
    {
        $this->accessible($request, $customer);
        if (! in_array($type, CobrosExpedienteService::DOC_TYPES, true)) {
            abort(404);
        }
        $expediente = $this->expediente($this->company(), $customer);
        $document = $expediente?->documents()->where('doc_type', $type)->first();

        if ($document === null || ! Storage::disk('local')->exists($document->path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($document->path));
    }

    /**
     * Autoridad: cliente de MISMA empresa (404 si no), luego owner
     * (portal_dueno) o cobrador con asignación en cartera (403 si no).
     */
    private function accessible(Request $request, Customer $customer): Customer
    {
        $company = $this->company();
        $user = $request->user();

        if ((int) $customer->company_id !== (int) $company->id) {
            abort(404);
        }
        if ($user->hasPermission('cobros.portal_dueno', $company)) {
            return $customer;
        }
        $assigned = CobrosAssignment::query()->forCompany((int) $company->id)
            ->where('customer_id', $customer->id)
            ->where('collector_user_id', $user->id)
            ->exists();
        if (! $assigned) {
            abort(403, 'Este cliente no le pertenece.');
        }

        return $customer;
    }

    /** Vínculo histórico opcional: ?solicitud=ID de ESTE cliente (auditoría 10A). */
    private function linkedRequest(Request $request, Company $company, Customer $customer): ?CobrosCreditRequest
    {
        $solicitud = (int) $request->query('solicitud', 0);
        if ($solicitud <= 0) {
            return null;
        }

        return CobrosCreditRequest::forCompany((int) $company->id)
            ->where('id', $solicitud)
            ->where('customer_id', $customer->id)
            ->first();
    }

    /** Reglas server-side: todo opcional salvo los datos mínimos del fiador si aplica. */
    private function rules(Request $request): array
    {
        $money = ['nullable', 'string', 'max:20', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];

        $rules = [
            'occupation' => ['nullable', 'string', 'max:120'],
            'workplace' => ['nullable', 'string', 'max:150'],
            'income_amount' => $money,
            'income_frequency' => ['nullable', 'in:daily,weekly,biweekly,monthly'],
            'activity_time' => ['nullable', 'string', 'max:60'],
            'address_reference' => ['nullable', 'string', 'max:250'],
            'has_guarantor' => ['nullable', 'boolean'],
            'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_identification' => ['nullable', 'string', 'max:50'],
            'guarantor_phone' => ['nullable', 'string', 'max:30'],
            'guarantor_address' => ['nullable', 'string', 'max:250'],
            'guarantor_occupation' => ['nullable', 'string', 'max:120'],
            'guarantor_income' => $money,
            'references' => ['nullable', 'array', 'max:2'],
            'references.*.name' => ['required', 'string', 'max:150'],
            'references.*.phone' => ['required', 'string', 'max:30'],
            'references.*.relationship' => ['required', 'string', 'max:60'],
        ];
        foreach (CobrosExpedienteService::DOC_TYPES as $type) {
            $rules['doc_'.$type] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];
        }
        if ($request->boolean('has_guarantor')) {
            $rules['guarantor_name'] = ['required', 'string', 'max:150'];
            $rules['guarantor_identification'] = ['required', 'string', 'max:50'];
            $rules['guarantor_phone'] = ['required', 'string', 'max:30'];
        }

        return $rules;
    }

    private function expediente(Company $company, Customer $customer): ?CobrosCreditExpediente
    {
        return CobrosCreditExpediente::query()->forCompany((int) $company->id)
            ->where('customer_id', $customer->id)
            ->with(['references', 'documents'])
            ->first();
    }

    private function company(): Company
    {
        return Company::findOrFail((int) session('active_company_id'));
    }
}
