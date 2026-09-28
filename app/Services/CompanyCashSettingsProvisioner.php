<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyCashSetting;

class CompanyCashSettingsProvisioner
{
    public function provision(Company $company): CompanyCashSetting
    {
        return CompanyCashSetting::firstOrCreate(
            ['company_id' => $company->id],
            [
                'require_open_session' => false,
                'allow_multiple_registers' => false,
                'session_mode' => CompanyCashSetting::SESSION_MODE_INDIVIDUAL,
                'difference_tolerance' => 0,
                'require_difference_authorization' => false,
                'auto_print_closure' => false,
                'blind_closing' => true,
                'accepts_usd' => false,
                'usd_exchange_rate_min' => null,
                'usd_exchange_rate_max' => null,
                'usd_change_policy' => CompanyCashSetting::USD_CHANGE_CRC_ONLY,
                'closure_email_recipients' => null,
            ],
        );
    }
}
