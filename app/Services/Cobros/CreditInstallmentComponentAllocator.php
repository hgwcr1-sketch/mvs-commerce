<?php

namespace App\Services\Cobros;

use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditInstallmentComponent;
use App\Models\CobrosReceipt;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Allocator interno para cuotas desglosadas; no es invocado por el cobro público. */
class CreditInstallmentComponentAllocator
{
    private const ORDER = ['interest_current', 'interest_refinanced', 'principal_refinanced', 'principal_new'];

    /** @return array<int, CobrosCreditInstallmentApplication> */
    public function allocate(Company $company, CobrosReceipt $receipt, CobrosCreditInstallment $installment, string $amount): array
    {
        return DB::transaction(function () use ($company, $receipt, $installment, $amount): array {
            $locked = CobrosCreditInstallment::query()->lockForUpdate()->findOrFail($installment->id);
            $credit = $locked->credit()->withoutGlobalScopes()->first();
            if ($credit === null || (int) $credit->company_id !== (int) $company->id) {
                throw ValidationException::withMessages(['installment_id' => 'La cuota no pertenece a la empresa indicada.']);
            }
            $existing = CobrosCreditInstallmentApplication::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->where('cobros_receipt_id', $receipt->id)
                ->where('installment_id', $locked->id)->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                return $existing->all();
            }
            $components = CobrosCreditInstallmentComponent::query()->where('installment_id', $locked->id)
                ->where('credit_id', $credit->id)->orderByRaw("CASE kind WHEN 'interest_current' THEN 1 WHEN 'interest_refinanced' THEN 2 WHEN 'principal_refinanced' THEN 3 WHEN 'principal_new' THEN 4 ELSE 5 END")
                ->lockForUpdate()->get();
            if ($components->isEmpty() || bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages(['amount' => 'La cuota no tiene componentes aplicables.']);
            }
            $legacy = CobrosCreditInstallmentApplication::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->where('cobros_receipt_id', $receipt->id)->where('installment_id', $locked->id)->whereNull('component_id')->exists();
            if ($legacy) {
                throw ValidationException::withMessages(['application' => 'No se pueden mezclar aplicaciones legacy y desglosadas.']);
            }
            $remaining = $amount; $created = [];
            foreach ($components as $component) {
                $pending = bcsub((string) $component->original_amount, (string) $component->applied_amount, 4);
                if (bccomp($pending, '0', 4) <= 0 || bccomp($remaining, '0', 4) <= 0) continue;
                $use = bccomp($remaining, $pending, 4) < 0 ? $remaining : $pending;
                $component->forceFill(['applied_amount' => bcadd((string) $component->applied_amount, $use, 4)])->save();
                $created[] = CobrosCreditInstallmentApplication::create(['company_id' => $company->id, 'credit_id' => $credit->id, 'installment_id' => $locked->id, 'component_id' => $component->id, 'account_receivable_id' => $receipt->account_receivable_id, 'cobros_receipt_id' => $receipt->id, 'amount' => $use]);
                $remaining = bcsub($remaining, $use, 4);
            }
            if (bccomp($remaining, '0', 4) > 0) throw ValidationException::withMessages(['amount' => 'El importe supera el saldo de los componentes.']);
            $newApplied = bcadd((string) $locked->applied_amount, $amount, 4);
            $locked->forceFill(['applied_amount' => $newApplied, 'status' => bccomp($newApplied, (string) $locked->contractual_amount, 4) >= 0 ? CobrosCreditInstallment::STATUS_PAID : CobrosCreditInstallment::STATUS_PARTIAL])->save();
            return $created;
        });
    }
}
