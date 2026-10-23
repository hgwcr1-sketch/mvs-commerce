<?php

namespace App\Services\Cobros;

use App\Contracts\Cobros\MaestroAccreditationEvidenceVerifier;
use App\Models\CobrosMaestroCompanyBinding;
use App\Models\CobrosMaestroIdentityBinding;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MaestroAccreditationSynchronizer
{
    public function __construct(private readonly MaestroAccreditationEvidenceVerifier $verifier) {}

    public function sync(User $user, Company $company, array $evidence): ?CobrosMaestroIdentityBinding
    {
        $verified = $this->verifier->verify($evidence);
        return $verified === null ? null : $this->syncVerified($user, $company, $verified);
    }

    public function syncVerified(User $user, Company $company, array $verified): ?CobrosMaestroIdentityBinding
    {
        foreach (['application_id','installation_id','source_system','external_user_id','accreditation_id','status','company_id','maestro_client_id'] as $field) {
            if (! isset($verified[$field]) || (string) $verified[$field] === '') throw new InvalidArgumentException('Evidencia de acreditación incompleta.');
        }
        if ($verified['status'] !== 'approved' || $verified['source_system'] !== 'cobros') throw new InvalidArgumentException('Evidencia de acreditación fuera de contexto.');
        if (! CobrosMaestroCompanyBinding::query()->where('company_id',$company->id)->where('maestro_company_id',$verified['company_id'])->where('maestro_application_id',$verified['application_id'])->where('maestro_installation_id',$verified['installation_id'])->where('source_system','cobros')->where('status','approved')->whereNotNull('approved_at')->whereNull('revoked_at')->exists()) throw new InvalidArgumentException('Correspondencia empresarial no autorizada.');
        if ((string)$verified['application_id'] !== (string)config('maestro.identity.application_id','') || (string)$verified['installation_id'] !== (string)config('maestro.identity.installation_id','')) throw new InvalidArgumentException('Evidencia de acreditación fuera de contexto.');
        $now = now();
        if (! empty($verified['valid_until']) && $now->greaterThan($verified['valid_until'])) throw new InvalidArgumentException('Evidencia de acreditación vencida.');
        return DB::transaction(function () use ($user,$company,$verified,$now) {
            $binding = CobrosMaestroIdentityBinding::query()->where('user_id',$user->id)->where('company_id',$company->id)->where('maestro_application_id',$verified['application_id'])->where('maestro_installation_id',$verified['installation_id'])->lockForUpdate()->first();
            if ($binding?->revoked_at !== null) throw new InvalidArgumentException('La identidad revocada no puede reactivarse.');
            $attributes = ['user_id'=>$user->id,'company_id'=>$company->id,'maestro_company_id'=>$verified['company_id'],'maestro_application_id'=>$verified['application_id'],'maestro_installation_id'=>$verified['installation_id'],'source_system'=>'cobros','external_user_id'=>$verified['external_user_id'],'accreditation_id'=>$verified['accreditation_id'],'status'=>'approved','approved_at'=>$verified['approved_at'] ?? $now,'valid_from'=>$verified['valid_from'] ?? null,'valid_until'=>$verified['valid_until'] ?? null];
            if ($binding) { $binding->update($attributes); return $binding->refresh(); }
            return CobrosMaestroIdentityBinding::create($attributes);
        });
    }
}
