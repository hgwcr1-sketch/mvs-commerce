<?php

namespace App\Http\Requests;

use App\Models\CompanyCashSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $acceptsUsd = CompanyCashSetting::where('company_id', session('active_company_id'))->value('accepts_usd') ?? false;
        $this->merge([
            'usd_exchange_rate' => $acceptsUsd ? $this->input('usd_exchange_rate') : null,
            'opening_amount_usd' => $acceptsUsd ? ($this->input('opening_amount_usd') ?? 0) : 0,
        ]);
    }

    public function rules(): array
    {
        $companyId=(int)session('active_company_id'); $branchId=(int)session('active_branch_id');
        $settings=CompanyCashSetting::where('company_id',$companyId)->first(); $accepts=(bool)$settings?->accepts_usd;
        $rate=['nullable','numeric','gt:0'];
        if($accepts){$rate[0]='required'; if($settings->usd_exchange_rate_min!==null)$rate[]='gte:'.$settings->usd_exchange_rate_min; if($settings->usd_exchange_rate_max!==null)$rate[]='lte:'.$settings->usd_exchange_rate_max;}
        return [
            'cash_register_id'=>['required','integer',Rule::exists('cash_registers','id')->where(fn($q)=>$q->where('company_id',$companyId)->where('branch_id',$branchId)->where('is_active',true))],
            'opening_amount'=>['required','numeric','min:0','regex:/^\d+$/'],
            'usd_exchange_rate'=>$rate,
            'opening_amount_usd'=>['nullable','numeric','min:0'],
            'confirmation'=>['accepted'],
        ];
    }

    public function messages(): array { return ['cash_register_id.required'=>'Debe seleccionar una caja.','cash_register_id.exists'=>'La caja no está disponible en la sucursal activa.','opening_amount.required'=>'Debe indicar el fondo inicial.','opening_amount.min'=>'El fondo inicial no puede ser negativo.','opening_amount.regex'=>'El fondo CRC debe indicarse sin decimales.','usd_exchange_rate.required'=>'Debe indicar el tipo de cambio USD.','usd_exchange_rate.gte'=>'El tipo de cambio es menor al mínimo permitido.','usd_exchange_rate.lte'=>'El tipo de cambio supera el máximo permitido.','confirmation.accepted'=>'Debe confirmar que el fondo inicial contado es correcto.']; }
}
