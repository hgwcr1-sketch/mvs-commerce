<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentMethodRequest;
use App\Http\Requests\UpdatePaymentMethodRequest;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PaymentMethodController extends Controller
{
    public function index(): View
    {
        $paymentMethods = PaymentMethod::forCompany($this->activeCompanyId())
            ->ordered()
            ->paginate(20);

        return view('settings.pos.payment-methods.index', compact('paymentMethods'));
    }

    public function create(): View
    {
        return view('settings.pos.payment-methods.create', [
            'paymentMethod' => new PaymentMethod(),
            'types' => $this->types(),
        ]);
    }

    public function store(StorePaymentMethodRequest $request): RedirectResponse
    {
        PaymentMethod::create([
            ...$request->validated(),
            'company_id' => $this->activeCompanyId(),
            'is_system' => false,
        ]);

        return redirect()
            ->route('settings.pos.payment-methods.index')
            ->with('success', 'Forma de pago creada correctamente.');
    }

    public function edit(PaymentMethod $paymentMethod): View
    {
        $this->ensureActiveCompany($paymentMethod);

        return view('settings.pos.payment-methods.edit', [
            'paymentMethod' => $paymentMethod,
            'types' => $this->types(),
        ]);
    }

    public function update(
        UpdatePaymentMethodRequest $request,
        PaymentMethod $paymentMethod,
    ): RedirectResponse {
        $this->ensureActiveCompany($paymentMethod);
        $data = $request->validated();

        if ($paymentMethod->is_system) {
            unset($data['code'], $data['type']);
        }

        $paymentMethod->update($data);

        return redirect()
            ->route('settings.pos.payment-methods.index')
            ->with('success', 'Forma de pago actualizada correctamente.');
    }

    public function toggleStatus(PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->ensureActiveCompany($paymentMethod);
        $paymentMethod->update(['is_active' => !$paymentMethod->is_active]);

        return redirect()
            ->route('settings.pos.payment-methods.index')
            ->with('success', $paymentMethod->is_active
                ? 'Forma de pago activada correctamente.'
                : 'Forma de pago desactivada correctamente.');
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->ensureActiveCompany($paymentMethod);

        if ($paymentMethod->is_system) {
            return redirect()
                ->route('settings.pos.payment-methods.index')
                ->with('error', 'Las formas de pago del sistema no se pueden eliminar; puede desactivarlas.');
        }

        if (SalePayment::query()->where('payment_method_id', $paymentMethod->id)->exists()) {
            return redirect()
                ->route('settings.pos.payment-methods.index')
                ->with('error', 'Esta forma de pago tiene pagos históricos y no puede eliminarse; puede desactivarla.');
        }

        $paymentMethod->delete();

        return redirect()
            ->route('settings.pos.payment-methods.index')
            ->with('success', 'Forma de pago eliminada correctamente.');
    }

    private function activeCompanyId(): int
    {
        $companyId = session('active_company_id');
        abort_unless($companyId, 404);

        return (int) $companyId;
    }

    private function ensureActiveCompany(PaymentMethod $paymentMethod): void
    {
        abort_unless($paymentMethod->company_id === $this->activeCompanyId(), 404);
    }

    private function types(): array
    {
        return [
            PaymentMethod::TYPE_CASH => 'Efectivo',
            PaymentMethod::TYPE_CARD => 'Tarjeta',
            PaymentMethod::TYPE_SINPE => 'SINPE',
            PaymentMethod::TYPE_BANK_TRANSFER => 'Transferencia bancaria',
            PaymentMethod::TYPE_CREDIT => 'Crédito',
            PaymentMethod::TYPE_LOYALTY_POINTS => 'Puntos de lealtad',
            PaymentMethod::TYPE_OTHER => 'Otro',
        ];
    }
}
