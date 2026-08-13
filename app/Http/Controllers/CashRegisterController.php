<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCashRegisterRequest;
use App\Http\Requests\UpdateCashRegisterRequest;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CompanyCashSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CashRegisterController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = $this->activeCompanyId();
        $branchId = $request->integer('branch_id');
        $branches = $this->activeBranches($companyId);
        $cashRegisters = CashRegister::query()
            ->where('cash_registers.company_id', $companyId)
            ->with('branch:id,name')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->join('branches', 'branches.id', '=', 'cash_registers.branch_id')
            ->orderBy('branches.name')
            ->orderByDesc('cash_registers.is_default')
            ->orderBy('cash_registers.name')
            ->select('cash_registers.*')
            ->paginate(20)
            ->withQueryString();

        return view('settings.cash-registers.index', [
            'cashRegisters' => $cashRegisters,
            'branches' => $branches,
            'selectedBranchId' => $branchId,
            'cashSettings' => $this->cashSettings($companyId),
        ]);
    }

    public function create(): View
    {
        $companyId = $this->activeCompanyId();

        return view('settings.cash-registers.create', [
            'cashRegister' => new CashRegister(['is_active' => true]),
            'branches' => $this->activeBranches($companyId),
            'hasSessions' => false,
        ]);
    }

    public function store(StoreCashRegisterRequest $request): RedirectResponse
    {
        $companyId = $this->activeCompanyId();
        $data = $request->safe()->only(['branch_id', 'code', 'name', 'is_active', 'is_default']);

        DB::transaction(function () use ($companyId, $data): void {
            $this->ensureActiveLimit($companyId, (int) $data['branch_id'], (bool) $data['is_active']);
            if ($data['is_default']) {
                $this->clearDefault($companyId, (int) $data['branch_id']);
            }
            CashRegister::create([...$data, 'company_id' => $companyId]);
        });

        return redirect()->route('settings.cash-registers.index')
            ->with('success', 'Caja creada correctamente.');
    }

    public function edit(CashRegister $cashRegister): View
    {
        $this->ensureActiveCompany($cashRegister);

        return view('settings.cash-registers.edit', [
            'cashRegister' => $cashRegister,
            'branches' => $this->activeBranches($this->activeCompanyId()),
            'hasSessions' => $cashRegister->sessions()->exists(),
        ]);
    }

    public function update(UpdateCashRegisterRequest $request, CashRegister $cashRegister): RedirectResponse
    {
        $this->ensureActiveCompany($cashRegister);
        $companyId = $this->activeCompanyId();
        $data = $request->safe()->only(['branch_id', 'code', 'name', 'is_active', 'is_default']);

        DB::transaction(function () use ($cashRegister, $companyId, $data): void {
            $locked = CashRegister::query()->lockForUpdate()->findOrFail($cashRegister->id);
            if ((int) $locked->branch_id !== (int) $data['branch_id'] && $locked->sessions()->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'No puede cambiar la sucursal de una caja con sesiones históricas.']);
            }
            if ($locked->is_active && !$data['is_active'] && $this->hasOpenSession($locked)) {
                throw ValidationException::withMessages(['is_active' => 'No puede desactivar una caja con una sesión abierta.']);
            }
            $this->ensureActiveLimit($companyId, (int) $data['branch_id'], (bool) $data['is_active'], $locked->id);
            if ($data['is_default']) {
                $this->clearDefault($companyId, (int) $data['branch_id'], $locked->id);
            }
            $locked->update($data);
        });

        return redirect()->route('settings.cash-registers.index')
            ->with('success', 'Caja actualizada correctamente.');
    }

    public function toggleStatus(CashRegister $cashRegister): RedirectResponse
    {
        $this->ensureActiveCompany($cashRegister);
        $companyId = $this->activeCompanyId();

        DB::transaction(function () use ($cashRegister, $companyId): void {
            $locked = CashRegister::query()->lockForUpdate()->findOrFail($cashRegister->id);
            if ($locked->is_active && $this->hasOpenSession($locked)) {
                throw ValidationException::withMessages(['cash_register' => 'No puede desactivar una caja con una sesión abierta.']);
            }
            $newStatus = !$locked->is_active;
            $this->ensureActiveLimit($companyId, (int) $locked->branch_id, $newStatus, $locked->id);
            $locked->update(['is_active' => $newStatus]);
        });

        return redirect()->route('settings.cash-registers.index')
            ->with('success', $cashRegister->fresh()->is_active ? 'Caja activada correctamente.' : 'Caja desactivada correctamente.');
    }

    private function activeCompanyId(): int
    {
        $companyId = session('active_company_id');
        abort_unless($companyId, 404);
        return (int) $companyId;
    }

    private function ensureActiveCompany(CashRegister $cashRegister): void
    {
        abort_unless((int) $cashRegister->company_id === $this->activeCompanyId(), 404);
    }

    private function activeBranches(int $companyId)
    {
        return Branch::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function cashSettings(int $companyId): CompanyCashSetting
    {
        return CompanyCashSetting::query()->where('company_id', $companyId)->first()
            ?? new CompanyCashSetting(['company_id' => $companyId, 'allow_multiple_registers' => false]);
    }

    private function ensureActiveLimit(int $companyId, int $branchId, bool $willBeActive, ?int $exceptId = null): void
    {
        if (!$willBeActive || $this->cashSettings($companyId)->allow_multiple_registers) {
            return;
        }
        $exists = CashRegister::query()->forCompany($companyId)->forBranch($branchId)->active()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->lockForUpdate()->exists();
        if ($exists) {
            throw ValidationException::withMessages(['is_active' => 'Actualmente sólo se permite una caja activa por sucursal.']);
        }
    }

    private function clearDefault(int $companyId, int $branchId, ?int $exceptId = null): void
    {
        CashRegister::query()->forCompany($companyId)->forBranch($branchId)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function hasOpenSession(CashRegister $cashRegister): bool
    {
        return $cashRegister->sessions()->whereIn('status', [CashSession::STATUS_OPEN, CashSession::STATUS_CLOSING])->exists();
    }
}
