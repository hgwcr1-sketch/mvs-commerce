<?php

declare(strict_types=1);

namespace App\Services\Cobros;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosReceipt;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

/** Reutiliza únicamente un pago confirmado, jamás registra dinero nuevo. */
final class VerifiedRenewalPartialPayment
{
    public function verify(Company $company, CobrosCredit $credit, string $receiptNumber): CobrosReceipt
    {
        $receiptNumber = trim($receiptNumber);
        if ($receiptNumber === '') {
            throw ValidationException::withMessages(['receipt_number' => 'Debe indicar el recibo del abono ya confirmado.']);
        }
        $receipt = CobrosReceipt::query()->forCompany((int) $company->id)
            ->where('account_receivable_id', $credit->account_receivable_id)
            ->where('customer_id', $credit->customer_id)
            ->where('receipt_number', $receiptNumber)
            ->whereNull('annulled_at')
            ->first();
        if (! $receipt || ! $receipt->payment_id) {
            throw ValidationException::withMessages(['receipt_number' => 'El recibo no corresponde a un pago válido y confirmado de este crédito.']);
        }
        $applied = CobrosCreditInstallmentApplication::query()
            ->where('company_id', $company->id)->where('credit_id', $credit->id)
            ->where('cobros_receipt_id', $receipt->id)->sum('amount');
        if (bccomp((string) $applied, '0', 4) <= 0) {
            throw ValidationException::withMessages(['receipt_number' => 'El recibo no aplicó ningún abono al capital o intereses del crédito (puede haber cubierto solo mora).']);
        }
        if (CobrosCreditRenewalRequest::query()->where('partial_payment_receipt_id', $receipt->id)->exists()) {
            throw ValidationException::withMessages(['receipt_number' => 'Este recibo ya está vinculado a otra renovación.']);
        }
        $currentBalance = (string) $credit->account()->firstOrFail()->balance_due;
        if (bccomp($currentBalance, (string) $receipt->balance_after, 4) !== 0) {
            throw ValidationException::withMessages(['receipt_number' => 'El saldo cambió desde este recibo. Confirme el saldo actual antes de renovar.']);
        }
        $latest = CobrosReceipt::query()->forCompany((int) $company->id)
            ->where('account_receivable_id', $credit->account_receivable_id)
            ->whereNull('annulled_at')->orderByDesc('id')->first();
        if (! $latest || (int) $latest->id !== (int) $receipt->id) {
            throw ValidationException::withMessages(['receipt_number' => 'Se registraron otros pagos después de este recibo. Utilice el más reciente y vuelva a cotizar.']);
        }
        return $receipt;
    }
}
