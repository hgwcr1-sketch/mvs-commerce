<?php

namespace App\Services\Cobros;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Http;

final class MaestroRenewalAccessAdapter
{
    public function __construct(private readonly MaestroJwsCredentialProvider $credentials) {}
    public function access(User $user, Company $company): array
    {
        $jws=$this->credentials->for($user,$company); if(!$jws)return ['ok'=>false,'reason'=>'No existe una identidad Maestro autorizada para esta empresa.'];
        try{$response=Http::timeout((float)config('maestro.timeout',5.0))->withToken((string)config('maestro.token',''))->withHeaders(['X-Maestro-Authorization'=>$jws])->acceptJson()->post(rtrim((string)config('maestro.url',''),'/').'/api/v1/renewal/access',[]);}catch(\Throwable){return ['ok'=>false,'reason'=>'MVS Maestro no está disponible.'];}
        if(!$response->successful())return ['ok'=>false,'reason'=>'No fue posible iniciar la renovación en MVS Maestro.'];
        $data=$response->json('data'); if(!is_array($data)||!is_string($data['token']??null)||!preg_match('/\A[a-zA-Z0-9]{64}\z/',$data['token']))return ['ok'=>false,'reason'=>'MVS Maestro devolvió un token de renovación inválido.'];
        return ['ok'=>true,'url'=>$data['url']??null,'token'=>$data['token'],'expires_at'=>$data['expires_at']??null];
    }
}
