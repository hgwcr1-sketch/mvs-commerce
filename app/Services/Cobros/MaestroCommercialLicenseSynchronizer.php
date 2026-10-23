<?php

namespace App\Services\Cobros;

use App\Models\CobrosMaestroCompanyBinding;
use App\Models\CobrosMaestroIdentityBinding;
use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MaestroCommercialLicenseSynchronizer
{
    public function sync(User $user, Company $company, array $accreditation): ?CompanyLicense
    {
        $license = $accreditation['license'] ?? null;
        if ($license === null) return null;
        if (! is_array($license)) throw new InvalidArgumentException('Bloque comercial inválido.');
        $companyBinding = CobrosMaestroCompanyBinding::query()->where('company_id',$company->id)->where('source_system','cobros')->where('status','approved')->whereNotNull('approved_at')->whereNull('revoked_at')->first();
        $identity = CobrosMaestroIdentityBinding::query()->where('user_id',$user->id)->where('company_id',$company->id)->where('source_system','cobros')->where('status','approved')->whereNotNull('approved_at')->whereNull('revoked_at')->first();
        if (! $companyBinding || ! $identity || (string)($accreditation['company_id']??'') !== (string)$companyBinding->maestro_company_id || (string)($accreditation['application_id']??'') !== (string)$companyBinding->maestro_application_id || (string)($accreditation['installation_id']??'') !== (string)$companyBinding->maestro_installation_id) return null;
        foreach (['external_license_id','version','status','plan'] as $field) if (! isset($license[$field]) || trim((string)$license[$field]) === '') throw new InvalidArgumentException("Licencia comercial incompleta: {$field}.");
        if (! in_array($license['status'], CompanyLicense::STATUSES, true)) throw new InvalidArgumentException('Estado comercial inválido.');
        $dates=[]; foreach(['starts_at','expires_at','grace_until'] as $field) if ($license[$field] ?? null) $dates[$field]=CarbonImmutable::parse((string)$license[$field]);
        return DB::transaction(function() use($company,$license,$dates) { $current=$company->license()->lockForUpdate()->first(); if($current && ($current->maestro_license_id===null || $current->maestro_license_id!==(string)$license['external_license_id'])) return null; $a=['maestro_license_id'=>(string)$license['external_license_id'],'maestro_license_version'=>(string)$license['version'],'maestro_license_modality'=>$license['modality']??null,'maestro_license_limits'=>$license['limits']??null,'maestro_license_synced_at'=>now(),'status'=>$license['status'],'plan'=>(string)$license['plan'],...$dates]; if(!$current)return CompanyLicense::create(['company_id'=>$company->id,...$a]); $current->update($a); return $current->fresh(); });
    }
}
