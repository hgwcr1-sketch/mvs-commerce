<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\LoyaltyReward;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyRewardAvailabilityService
{
    /** Unidades consumidas por cada redención en la V1. */
    public const UNITS_PER_REDEMPTION = '1';

    private const SCALE = 4;

    /**
     * Consulta central y reutilizable de disponibilidad. Solo lectura:
     * el consumo atómico del cupo o del inventario corresponde al canje (F21).
     *
     * @return array{available:bool, reason:?string, mode:string}
     */
    public function evaluate(LoyaltyReward $reward, Company $company, Branch $branch): array
    {
        $this->validateCompanyAndBranch($reward, $company, $branch);

        if (! $reward->is_active) {
            return $this->result($reward, false, 'inactive');
        }

        return match ($reward->availability_mode) {
            LoyaltyReward::MODE_LIMITED => $this->evaluateLimited($reward),
            LoyaltyReward::MODE_PRODUCT => $this->evaluateProduct($reward, $branch),
            default => $this->result($reward, true, null),
        };
    }

    public function isAvailable(LoyaltyReward $reward, Company $company, Branch $branch): bool
    {
        return $this->evaluate($reward, $company, $branch)['available'];
    }

    private function evaluateLimited(LoyaltyReward $reward): array
    {
        $quota = (string) ($reward->stock_quantity ?? '0');

        if (bccomp($quota, '0', self::SCALE) <= 0) {
            return $this->result($reward, false, 'insufficient_quota');
        }

        return $this->result($reward, true, null);
    }

    private function evaluateProduct(LoyaltyReward $reward, Branch $branch): array
    {
        $stock = DB::table('branch_product')
            ->where('branch_id', $branch->id)
            ->where('product_id', $reward->product_id)
            ->value('stock');

        if ($stock === null || bccomp((string) $stock, self::UNITS_PER_REDEMPTION, self::SCALE) < 0) {
            return $this->result($reward, false, 'out_of_stock');
        }

        return $this->result($reward, true, null);
    }

    private function validateCompanyAndBranch(LoyaltyReward $reward, Company $company, Branch $branch): void
    {
        if ((int) $reward->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['reward' => 'El premio no pertenece a la empresa actual.']);
        }

        if ((int) $branch->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['branch' => 'La sucursal no pertenece a la empresa actual.']);
        }
    }

    private function result(LoyaltyReward $reward, bool $available, ?string $reason): array
    {
        return ['available' => $available, 'reason' => $reason, 'mode' => $reward->availability_mode];
    }
}
