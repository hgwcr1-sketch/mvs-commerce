<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseVerification;
use App\Models\User;
use App\Services\Purchases\PurchaseVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseVerificationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($this->can($request, 'compras.recepcion.verificar') || $this->can($request, 'compras.recepcion.asignar') || $this->can($request, 'compras.recepcion.resolver'), 403);
        $query = PurchaseVerification::query()->where('company_id', session('active_company_id'))->where('branch_id', session('active_branch_id'))
            ->with(['purchase.supplier:id,name,commercial_name', 'assignee:id,name']);
        if (! $this->can($request, 'compras.recepcion.asignar') && ! $this->can($request, 'compras.recepcion.resolver')) {
            $query->where('assigned_to', $request->user()->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $verifications = $query->latest('assigned_at')->paginate(20)->withQueryString();
        return view('purchase-verifications.index', compact('verifications'));
    }

    public function store(Request $request, Purchase $purchase, PurchaseVerificationService $service)
    {
        $this->guardPurchase($purchase);
        $data = $request->validate(['assigned_to' => ['required', 'integer']]);
        $assignee = $this->assignableUsers($purchase)->firstWhere('id', (int) $data['assigned_to']);
        if (! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => 'Seleccione un usuario activo, autorizado y asignado a esta sucursal.']);
        }
        $verification = $service->assign($purchase, $request->user(), $assignee);
        return redirect()->route('purchase-verifications.show', $verification)->with('success', 'Verificación asignada.');
    }

    public function show(Request $request, PurchaseVerification $purchaseVerification)
    {
        $this->guardVerification($request, $purchaseVerification);
        $purchaseVerification->load(['purchase.supplier', 'items.product', 'creator:id,name', 'assigner:id,name', 'assignee:id,name', 'verifier:id,name', 'resolver:id,name']);
        return view('purchase-verifications.show', ['verification' => $purchaseVerification]);
    }

    public function start(Request $request, PurchaseVerification $purchaseVerification, PurchaseVerificationService $service)
    {
        $this->guardVerifier($request, $purchaseVerification);
        $service->start($purchaseVerification, $request->user());
        return back()->with('success', 'Revisión iniciada.');
    }

    public function verify(Request $request, PurchaseVerification $purchaseVerification, PurchaseVerificationService $service)
    {
        $this->guardVerifier($request, $purchaseVerification);
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.received_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999999.9999'],
            'lines.*.confirmed' => ['required', 'accepted'],
            'lines.*.observation' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->verify($purchaseVerification, $request->user(), $data['lines']);
        return back()->with('success', 'Recepción verificada.');
    }

    public function close(Request $request, PurchaseVerification $purchaseVerification, PurchaseVerificationService $service)
    {
        $this->guardContext($purchaseVerification);
        $data = $request->validate(['resolution_notes' => ['nullable', 'string', 'max:2000']]);
        $service->close($purchaseVerification, $request->user(), $data['resolution_notes'] ?? null);
        return back()->with('success', 'Verificación cerrada digitalmente.');
    }

    public function assignable(Purchase $purchase)
    {
        $this->guardPurchase($purchase);
        return response()->json($this->assignableUsers($purchase)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values());
    }

    private function assignableUsers(Purchase $purchase)
    {
        return User::query()->select('users.*')->distinct()->where('users.is_active', true)
            ->join('company_user', fn ($join) => $join->on('company_user.user_id', '=', 'users.id')->where('company_user.company_id', $purchase->company_id))
            ->join('branch_user', fn ($join) => $join->on('branch_user.user_id', '=', 'users.id')->where('branch_user.branch_id', $purchase->branch_id))
            ->join('permission_role', 'permission_role.role_id', '=', 'company_user.role_id')
            ->join('permissions', fn ($join) => $join->on('permissions.id', '=', 'permission_role.permission_id')->where('permissions.name', 'compras.recepcion.verificar')->where('permissions.is_active', true))
            ->orderBy('users.name')->get();
    }

    private function guardPurchase(Purchase $purchase): void
    {
        abort_unless((int) $purchase->company_id === (int) session('active_company_id') && (int) $purchase->branch_id === (int) session('active_branch_id'), 404);
    }

    private function guardContext(PurchaseVerification $verification): void
    {
        abort_unless((int) $verification->company_id === (int) session('active_company_id') && (int) $verification->branch_id === (int) session('active_branch_id'), 404);
    }

    private function guardVerification(Request $request, PurchaseVerification $verification): void
    {
        $this->guardContext($verification);
        abort_unless(($verification->assigned_to === $request->user()->id && $this->can($request, 'compras.recepcion.verificar')) || $this->can($request, 'compras.recepcion.asignar') || $this->can($request, 'compras.recepcion.resolver'), 403);
    }

    private function guardVerifier(Request $request, PurchaseVerification $verification): void
    {
        $this->guardContext($verification);
        abort_unless($verification->assigned_to === $request->user()->id, 403);
    }

    private function can(Request $request, string $permission): bool
    {
        $company = Company::find(session('active_company_id'));
        return $company && $request->user()->hasPermission($permission, $company);
    }
}
