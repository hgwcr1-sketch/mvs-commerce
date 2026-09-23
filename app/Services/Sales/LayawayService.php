<?php

namespace App\Services\Sales;

use App\Models\{CashSession,Company,CompanySequence,Customer,InventoryMovement,Layaway,LayawayAlert,LayawayPayment,PaymentMethod,Product,Sale,SaleItem,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LayawayService
{
    public function create(array $data, User $user, int $companyId, int $branchId): Layaway
    {
        $data['items'] = array_values(array_filter($data['items'], fn ($item) => !empty($item['product_id']) && !empty($item['quantity'])));

        return DB::transaction(function () use ($data, $user, $companyId, $branchId) {
            $company = Company::findOrFail($companyId);
            Customer::forCompany($companyId)->where('is_active', true)->findOrFail($data['customer_id']);
            $total = 0;
            $lines = [];
            foreach ($data['items'] as $line) {
                $product = Product::query()->with('unit:id,allows_decimals')->where('company_id', $companyId)->where('is_active', true)->findOrFail($line['product_id']);
                $qty = round((float) $line['quantity'], 4);
                if ($qty <= 0) {
                    throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor que cero.']);
                }
                if (! $product->unit?->allows_decimals && floor($qty) !== $qty) {
                    throw ValidationException::withMessages(['items' => "{$product->name} solo admite cantidades enteras."]);
                }
                $stock = DB::table('branch_product')->where('branch_id', $branchId)->where('product_id', $product->id)->lockForUpdate()->first();
                if (! $stock || (float) $stock->stock < $qty) {
                    throw ValidationException::withMessages(['items' => "Stock insuficiente para {$product->name}."]);
                }
                $unit = (float) $product->sale_price;
                $sub = round($unit * $qty, 4);
                $tax = round($sub * ((float) ($product->tax_rate ?? 0) / 100), 4);
                $lineTotal = round($sub + $tax, 4);
                $total += $lineTotal;
                $lines[] = compact('product', 'qty', 'unit', 'sub', 'tax', 'lineTotal', 'stock');
            }
            $layaway = Layaway::create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'customer_id' => $data['customer_id'],
                'created_by' => $user->id, 'number' => CompanySequence::nextLayawayNumber($companyId),
                'status' => Layaway::STATUS_ACTIVE, 'currency_code' => $company->currency ?? 'CRC',
                'total' => $total, 'paid_total' => 0, 'balance_due' => $total,
                'expires_at' => $data['expires_at'] ?? today()->addDays((int) ($company->layaway_validity_days ?? 30)),
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($lines as $line) {
                $layaway->items()->create([
                    'product_id' => $line['product']->id, 'description' => $line['product']->name,
                    'quantity' => $line['qty'], 'unit_price' => $line['unit'], 'tax_rate' => $line['product']->tax_rate ?? 0,
                    'subtotal' => $line['sub'], 'tax_total' => $line['tax'], 'total' => $line['lineTotal'],
                ]);
                $new = round((float) $line['stock']->stock - $line['qty'], 4);
                DB::table('branch_product')->where('id', $line['stock']->id)->update(['stock' => $new, 'updated_at' => now()]);
                InventoryMovement::create([
                    'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $line['product']->id,
                    'user_id' => $user->id, 'type' => 'layaway_reserve', 'quantity' => $line['qty'],
                    'previous_stock' => $line['stock']->stock, 'new_stock' => $new, 'reason' => 'Reserva por apartado',
                    'reference_type' => Layaway::class, 'reference_id' => $layaway->id, 'notes' => $layaway->number,
                ]);
            }
            if (! empty($data['payments']) || (float) ($data['initial_amount'] ?? 0) > 0) {
                $this->payLocked($layaway, $data, $user);
            }

            return $layaway->fresh(['items', 'payments']);
        });
    }

    /** @return array<int, LayawayPayment> */
    public function pay(Layaway $layaway, array $data, User $user): array
    {
        return DB::transaction(function () use ($layaway, $data, $user) {
            $locked = Layaway::lockForUpdate()->findOrFail($layaway->id);

            return $this->payLocked($locked, $data, $user);
        });
    }

    /** @return array<int, LayawayPayment> */
    private function payLocked(Layaway $layaway, array $data, User $user): array
    {
        if (! in_array($layaway->status, [Layaway::STATUS_ACTIVE, Layaway::STATUS_PAID], true) || $layaway->status === Layaway::STATUS_PAID) {
            throw ValidationException::withMessages(['layaway' => 'Este apartado no admite abonos.']);
        }
        if ($layaway->expires_at->isBefore(today())) {
            throw ValidationException::withMessages(['layaway' => 'El apartado está vencido.']);
        }

        $payments = $this->normalizePayments($data);
        if ($payments === []) {
            throw ValidationException::withMessages(['payments' => 'Debe indicar al menos un pago.']);
        }

        $methodIds = array_map(fn (array $payment) => (int) $payment['payment_method_id'], $payments);
        $methods = PaymentMethod::forCompany($layaway->company_id)->active()->whereIn('id', $methodIds)->get()->keyBy('id');

        $totalApplied = '0';
        $seenMethods = [];
        foreach ($payments as $index => $payment) {
            $amount = $this->decimal4($payment['amount'] ?? 0);
            if (bccomp($amount, '0', 4) <= 0) {
                throw ValidationException::withMessages(["payments.{$index}.amount" => 'El monto debe ser mayor que cero.']);
            }
            $methodId = (int) $payment['payment_method_id'];
            if (isset($seenMethods[$methodId])) {
                throw ValidationException::withMessages(['payments' => 'No puede repetir una forma de pago en la misma operación.']);
            }
            $seenMethods[$methodId] = true;
            $method = $methods->get($methodId);
            if (! $method) {
                throw ValidationException::withMessages(["payments.{$index}.payment_method_id" => 'La forma de pago no está activa o no pertenece a la empresa.']);
            }
            if (in_array($method->type, [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS], true)) {
                throw ValidationException::withMessages(["payments.{$index}.payment_method_id" => 'La forma de pago no es válida para apartados.']);
            }
            if ($method->requires_reference && trim((string) ($payment['reference'] ?? '')) === '') {
                throw ValidationException::withMessages(["payments.{$index}.reference" => "La referencia es obligatoria para {$method->name}."]);
            }
            $totalApplied = bcadd($totalApplied, $amount, 4);
        }

        $declared = $data['initial_amount'] ?? $data['amount'] ?? null;
        if ($declared !== null && $declared !== '') {
            if (bccomp($totalApplied, $this->decimal4($declared), 4) !== 0) {
                throw ValidationException::withMessages(['payments' => 'La suma de los pagos debe ser exactamente igual al monto a aplicar.']);
            }
        }
        if (bccomp($totalApplied, (string) $layaway->balance_due, 4) > 0) {
            $errorKey = isset($data['payments']) && is_array($data['payments']) ? 'payments' : 'amount';
            throw ValidationException::withMessages([$errorKey => 'El abono debe ser mayor que cero y no superar el saldo.']);
        }

        $needsCash = collect($payments)->contains(fn (array $payment) => (bool) $methods->get((int) $payment['payment_method_id'])?->affects_cash);
        $session = null;
        if (! empty($data['cash_session_id'])) {
            $session = CashSession::query()->forCompany($layaway->company_id)->forBranch($layaway->branch_id)
                ->where('status', CashSession::STATUS_OPEN)->where('open_guard', CashSession::OPEN_GUARD)
                ->find($data['cash_session_id']);
        }
        if ($needsCash && ! $session) {
            throw ValidationException::withMessages(['cash_session_id' => 'Seleccione una sesión de caja abierta.']);
        }

        $created = [];
        foreach ($payments as $payment) {
            $method = $methods->get((int) $payment['payment_method_id']);
            $amount = $this->decimal4($payment['amount']);
            $created[] = $layaway->payments()->create([
                'company_id' => $layaway->company_id,
                'branch_id' => $layaway->branch_id,
                'user_id' => $user->id,
                'cash_session_id' => $session?->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'affects_cash_snapshot' => (bool) $method->affects_cash,
                'cash_effect_amount' => $method->affects_cash ? $amount : '0.0000',
                'reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? $data['payment_notes'] ?? null,
                'paid_at' => now(),
            ]);
        }

        $paid = bcadd((string) $layaway->paid_total, $totalApplied, 4);
        $balance = bcsub((string) $layaway->total, $paid, 4);
        if (bccomp($balance, '0', 4) < 0) {
            $balance = '0.0000';
        }
        $isPaid = bccomp($balance, '0', 4) <= 0;
        $layaway->update([
            'paid_total' => $paid,
            'balance_due' => $balance,
            'status' => $isPaid ? Layaway::STATUS_PAID : Layaway::STATUS_ACTIVE,
            'paid_at' => $isPaid ? now() : null,
        ]);

        return $created;
    }

    /** @return array<int, array{payment_method_id: mixed, amount: mixed, reference: mixed, notes: mixed}> */
    private function normalizePayments(array $data): array
    {
        if (isset($data['payments']) && is_array($data['payments'])) {
            return array_values(array_filter($data['payments'], function ($payment) {
                return ! empty($payment['payment_method_id']) || ! empty($payment['amount']);
            }));
        }
        if (! empty($data['payment_method_id'])) {
            return [[
                'payment_method_id' => $data['payment_method_id'],
                'amount' => $data['amount'] ?? $data['initial_amount'] ?? 0,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['payment_notes'] ?? null,
            ]];
        }

        return [];
    }

    private function decimal4(mixed $value): string
    {
        $value = trim((string) ($value ?? '0'));
        if ($value === '' || ! is_numeric($value)) {
            return '0.0000';
        }

        return bcadd($value, '0', 4);
    }

    public function cancel(Layaway $layaway, User $user, string $reason): void
    {
        DB::transaction(function () use ($layaway, $user, $reason) {
            $locked = Layaway::lockForUpdate()->with('items.product')->findOrFail($layaway->id);
            if (! in_array($locked->status, [Layaway::STATUS_ACTIVE, Layaway::STATUS_PAID], true)) {
                throw ValidationException::withMessages(['layaway' => 'El apartado no puede cancelarse.']);
            }
            $this->release($locked, $user->id, 'layaway_cancel', 'Liberación por cancelación');
            $locked->update(['status' => Layaway::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);
        });
    }

    public function expireDue(): int
    {
        $count = 0;
        Layaway::query()->whereIn('status', [Layaway::STATUS_ACTIVE, Layaway::STATUS_PAID])->whereDate('expires_at', '<', today())->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$count) {
                    $locked = Layaway::lockForUpdate()->with('items.product')->find($row->id);
                    if (! $locked || ! in_array($locked->status, [Layaway::STATUS_ACTIVE, Layaway::STATUS_PAID], true) || ! $locked->expires_at->isBefore(today())) {
                        return;
                    }
                    $this->release($locked, null, 'layaway_expire', 'Liberación por vencimiento');
                    $locked->update(['status' => Layaway::STATUS_EXPIRED, 'expired_at' => now()]);
                    $count++;
                });
            }
        });

        return $count;
    }

    private function release(Layaway $layaway, ?int $userId, string $type, string $reason): void
    {
        foreach ($layaway->items as $item) {
            DB::table('branch_product')->insertOrIgnore(['branch_id' => $layaway->branch_id, 'product_id' => $item->product_id, 'stock' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $stock = DB::table('branch_product')->where('branch_id', $layaway->branch_id)->where('product_id', $item->product_id)->lockForUpdate()->first();
            $new = round((float) $stock->stock + (float) $item->quantity, 4);
            DB::table('branch_product')->where('id', $stock->id)->update(['stock' => $new, 'updated_at' => now()]);
            InventoryMovement::create(['company_id' => $layaway->company_id, 'branch_id' => $layaway->branch_id, 'product_id' => $item->product_id, 'user_id' => $userId, 'type' => $type, 'quantity' => $item->quantity, 'previous_stock' => $stock->stock, 'new_stock' => $new, 'reason' => $reason, 'reference_type' => Layaway::class, 'reference_id' => $layaway->id, 'notes' => $layaway->number]);
        }
    }

    public function deliver(Layaway $layaway, User $user): Sale
    {
        return DB::transaction(function () use ($layaway, $user) {
            $locked = Layaway::lockForUpdate()->with('items.product')->findOrFail($layaway->id);
            if ($locked->status !== Layaway::STATUS_PAID || (float) $locked->balance_due > 0) {
                throw ValidationException::withMessages(['layaway' => 'El apartado debe estar pagado antes de entregarse.']);
            }
            $sale = Sale::create(['company_id' => $locked->company_id, 'branch_id' => $locked->branch_id, 'user_id' => $user->id, 'customer_id' => $locked->customer_id, 'sale_number' => CompanySequence::nextPosNumber($locked->company_id), 'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET, 'sale_condition' => Sale::CONDITION_CASH, 'status' => Sale::STATUS_COMPLETED, 'currency_code' => $locked->currency_code, 'exchange_rate' => 1, 'subtotal' => $locked->items->sum('subtotal'), 'tax_total' => $locked->items->sum('tax_total'), 'discount_total' => 0, 'rounding_total' => 0, 'total' => $locked->total, 'paid_total' => $locked->total, 'balance_due' => 0, 'notes' => 'Entrega del apartado '.$locked->number, 'completed_at' => now()]);
            foreach ($locked->items as $item) {
                SaleItem::create(['sale_id' => $sale->id, 'product_id' => $item->product_id, 'product_code' => $item->product->internal_code, 'barcode' => $item->product->barcode, 'description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'gross_total' => $item->subtotal, 'discount_total' => 0, 'subtotal' => $item->subtotal, 'tax_rate' => $item->tax_rate, 'tax_total' => $item->tax_total, 'total' => $item->total, 'unit_cost' => $item->product->cost]);
            }
            $locked->update(['status' => Layaway::STATUS_DELIVERED, 'delivered_at' => now(), 'delivered_sale_id' => $sale->id]);

            return $sale;
        });
    }

    public function createUpcomingAlerts(): int
    {
        $count = 0;
        Layaway::query()->where('status', Layaway::STATUS_ACTIVE)->with('company.users')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $l) {
                $days = (int) ($l->company->layaway_alert_days ?? 5);
                if ($l->expires_at->between(today(), today()->addDays($days))) {
                    $alert = LayawayAlert::firstOrCreate(['layaway_id' => $l->id, 'type' => 'upcoming'], ['company_id' => $l->company_id, 'notified_at' => now()]);
                    if ($alert->wasRecentlyCreated) {
                        foreach ($l->company->users as $user) {
                            if ($user->hasPermission('apartados.ver', $l->company)) {
                                $user->notify(new \App\Notifications\LayawayUpcomingNotification($l));
                                $count++;
                            }
                        }
                    }
                }
            }
        });

        return $count;
    }
}
