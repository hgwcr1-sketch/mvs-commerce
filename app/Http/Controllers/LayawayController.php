<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\Cash\CashSessionResolver;
use App\Services\Sales\LayawayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LayawayController extends Controller
{
    public function index(Request $r): View
    {
        $q = Layaway::query()->forCompany((int) session('active_company_id'))->forBranch((int) session('active_branch_id'))->with('customer:id,name');
        if ($r->status) {
            $q->where('status', $r->status);
        }

return view('layaways.index', ['layaways' => $q->latest()->paginate(20)->withQueryString()]);
    }

    public function create(Request $r, CashSessionResolver $resolver): View
    {
        $companyId = (int) session('active_company_id');
        $branchId = (int) session('active_branch_id');

        return view('layaways.create', ['customers' => Customer::forCompany($companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'products' => Product::query()->where('company_id', $companyId)->where('is_active', true)->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId)->where('branch_product.stock', '>', 0))->with(['branches' => fn ($q) => $q->where('branches.id', $branchId), 'unit:id,allows_decimals'])->orderBy('name')->get(), 'methods' => PaymentMethod::forCompany($companyId)->active()->whereNotIn('type', ['credit', 'loyalty_points'])->ordered()->get(), 'sessions' => $resolver->applicable($r->user(), $companyId, $branchId), 'company' => Company::findOrFail($companyId)]);
    }

    public function store(Request $r, LayawayService $service): RedirectResponse
    {
        $data = $r->validate(['customer_id' => ['required', 'integer'], 'expires_at' => ['nullable', 'date', 'after_or_equal:today'], 'notes' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['nullable', 'integer'], 'items.*.quantity' => ['nullable', 'numeric', 'gt:0'], 'initial_amount' => ['required', 'numeric', 'gt:0'], 'payment_method_id' => ['required', 'integer'], 'received_amount' => ['nullable', 'numeric', 'gte:0'], 'cash_session_id' => ['nullable', 'integer'], 'reference' => ['nullable', 'string', 'max:150']]);
        $layaway = $service->create($data, $r->user(), (int) session('active_company_id'), (int) session('active_branch_id'));
        $r->session()->flash('mvs_layaway_print', ['type' => 'layaway', 'layaway_id' => $layaway->id]);

        return redirect()->route('apartados.show', $layaway)->with('success', 'Apartado creado correctamente.');
    }

    public function show(Request $r, Layaway $apartado, CashSessionResolver $resolver): View
    {
        $a = $this->scoped($apartado)->load(['customer', 'items.product.unit', 'payments.paymentMethod', 'payments.user', 'sale']);
        $companyId = $a->company_id;
        $methods = PaymentMethod::forCompany($companyId)->active()->whereNotIn('type', ['credit', 'loyalty_points'])->ordered()->get();
        $sheet = ['layawayId' => $a->id, 'methods' => $methods->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'allows_change' => (bool) $m->allows_change])->values(), 'layawayTicketUrl' => route('mvs.print.ticket.layaway', ['layaway' => '__LAYAWAY__'], false), 'paymentTicketUrl' => route('mvs.print.ticket.layaway.payment', ['layaway' => '__LAYAWAY__', 'payment' => '__PAYMENT__'], false), 'initialPrint' => null, 'configUrl' => route('mvs.print.config', [], false)];
        $print = session()->pull('mvs_layaway_print');
        if ($print) {
            $isPayment = $print['type'] === 'payment';
            $sheet['initialPrint'] = ['type' => $print['type'], 'id' => $isPayment ? $print['payment_id'] : $print['layaway_id'], 'url' => $isPayment ? route('mvs.print.ticket.layaway.payment', ['layaway' => $print['layaway_id'], 'payment' => $print['payment_id']], false) : route('mvs.print.ticket.layaway', ['layaway' => $print['layaway_id']], false)];
        }

return view('layaways.show', ['layaway' => $a, 'methods' => $methods, 'sessions' => $resolver->applicable($r->user(), $companyId, $a->branch_id), 'sheet' => $sheet]);
    }

    public function payment(Request $r, Layaway $apartado, LayawayService $service): RedirectResponse
    {
        $data = $r->validate(['amount' => ['required', 'numeric', 'gt:0'], 'payment_method_id' => ['required', 'integer'], 'received_amount' => ['nullable', 'numeric', 'gte:0'], 'cash_session_id' => ['nullable', 'integer'], 'reference' => ['nullable', 'string', 'max:150'], 'payment_notes' => ['nullable', 'string', 'max:2000']]);
        $payment = $service->pay($this->scoped($apartado), $data, $r->user());
        if ($r->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Abono registrado correctamente.', 'layaway_id' => $apartado->id, 'payment_id' => $payment->id, 'amount' => $payment->amount, 'received_amount' => $payment->received_amount, 'change_amount' => $payment->change_amount, 'balance_due' => $apartado->fresh()->balance_due]);
        }$r->session()->flash('mvs_layaway_print', ['type' => 'payment', 'layaway_id' => $apartado->id, 'payment_id' => $payment->id]);

        return back()->with('success', 'Abono registrado correctamente.');
    }

    public function cancel(Request $r, Layaway $apartado, LayawayService $service): RedirectResponse
    {
        $data = $r->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $service->cancel($this->scoped($apartado), $r->user(), $data['reason']);

        return back()->with('success', 'Apartado cancelado e inventario liberado.');
    }

    public function deliver(Request $r, Layaway $apartado, LayawayService $service): RedirectResponse
    {
        $sale = $service->deliver($this->scoped($apartado), $r->user());

        return redirect()->route('ventas.show', $sale)->with('success', 'Apartado entregado y venta completada.');
    }

    public function updateSettings(Request $r): RedirectResponse
    {
        $data = $r->validate(['layaway_validity_days' => ['required', 'integer', 'min:1', 'max:365'], 'layaway_alert_days' => ['required', 'integer', 'in:1,3,5,7,15']]);
        Company::findOrFail((int) session('active_company_id'))->update($data);

        return back()->with('success', 'Configuración de apartados actualizada.');
    }

    private function scoped(Layaway $a): Layaway
    {
        abort_unless($a->company_id === (int) session('active_company_id') && $a->branch_id === (int) session('active_branch_id'),404);

        return $a;
    }
}
