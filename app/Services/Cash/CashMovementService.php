<?php

namespace App\Services\Cash;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashMovementService
{
    public function __construct(private readonly CashExpectedAmountService $expectedAmountService) {}

    /** @return array{movement: CashMovement, duplicate: bool} */
    public function create(array $data, User $user, int $companyId, int $branchId, int $cashSessionId): array
    {
        return DB::transaction(function () use ($data, $user, $companyId, $branchId, $cashSessionId) {
            $company = Company::query()->where('is_active', true)->find($companyId);
            $branch = Branch::query()->where('company_id', $companyId)->where('is_active', true)->find($branchId);

            if ($company === null || $branch === null
                || ! $user->companies()->whereKey($companyId)->exists()
                || ! $user->branches()->whereKey($branchId)->exists()
                || ! $user->hasPermission('caja.movimientos', $company)) {
                throw ValidationException::withMessages(['cash_session_id' => 'La empresa o sucursal activa no está autorizada.']);
            }

            $session = CashSession::query()
                ->forCompany($companyId)
                ->forBranch($branchId)
                ->whereKey($cashSessionId)
                ->lockForUpdate()
                ->first();

            if ($session === null
                || $session->status !== CashSession::STATUS_OPEN
                || $session->open_guard !== CashSession::OPEN_GUARD) {
                throw ValidationException::withMessages(['cash_session_id' => 'La sesión de caja ya no está abierta.']);
            }

            $registerIsActive = $session->cashRegister()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->exists();

            if (! $registerIsActive) {
                throw ValidationException::withMessages(['cash_session_id' => 'La caja de esta sesión no está activa.']);
            }

            $settings = CompanyCashSetting::query()->where('company_id', $companyId)->firstOrFail();
            if ($settings->session_mode === CompanyCashSetting::SESSION_MODE_INDIVIDUAL
                && $session->opened_by !== $user->id) {
                throw ValidationException::withMessages(['cash_session_id' => 'Solo el cajero que abrió la sesión puede registrar movimientos.']);
            }

            $existing = CashMovement::forSession($session->id)
                ->where('request_token', $data['request_token'])
                ->first();

            if ($existing !== null) {
                return ['movement' => $existing, 'duplicate' => true];
            }

            $direction = $data['type'] === CashMovement::TYPE_ENTRY
                ? CashMovement::DIRECTION_IN
                : CashMovement::DIRECTION_OUT;
            $amount = (float) $data['amount'];

            if ($direction === CashMovement::DIRECTION_OUT) {
                $expected = $this->expectedAmountService->calculate($session);
                if ($amount > $expected) {
                    throw ValidationException::withMessages([
                        'amount' => 'El monto supera el efectivo esperado disponible de ₡'.number_format($expected, 0, ',', '.'),
                    ]);
                }
            }

            $now = now();
            $movement = CashMovement::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'cash_register_id' => $session->cash_register_id,
                'cash_session_id' => $session->id,
                'type' => $data['type'],
                'direction' => $direction,
                'amount' => $amount,
                'concept' => $this->concept($data['type']),
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'request_token' => $data['request_token'],
                'created_by' => $user->id,
                'occurred_at' => $now,
            ]);

            CashSessionEvent::create([
                'cash_session_id' => $session->id,
                'event_type' => $this->eventType($data['type']),
                'user_id' => $user->id,
                'occurred_at' => $now,
                'payload' => [
                    'cash_movement_id' => $movement->id,
                    'type' => $movement->type,
                    'direction' => $movement->direction,
                    'amount_crc' => number_format($amount, 4, '.', ''),
                    'reason' => $movement->reason,
                    'notes' => $movement->notes,
                ],
            ]);

            return ['movement' => $movement, 'duplicate' => false];
        });
    }

    private function concept(string $type): string
    {
        return match ($type) {
            CashMovement::TYPE_ENTRY => 'Entrada de efectivo',
            CashMovement::TYPE_EXIT => 'Salida de efectivo',
            CashMovement::TYPE_WITHDRAWAL => 'Retiro de efectivo',
        };
    }

    private function eventType(string $type): string
    {
        return match ($type) {
            CashMovement::TYPE_ENTRY => CashSessionEvent::TYPE_ENTRY,
            CashMovement::TYPE_EXIT => CashSessionEvent::TYPE_EXIT,
            CashMovement::TYPE_WITHDRAWAL => CashSessionEvent::TYPE_WITHDRAWAL,
        };
    }
}
