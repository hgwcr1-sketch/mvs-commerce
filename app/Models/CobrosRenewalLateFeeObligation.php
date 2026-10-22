<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CobrosRenewalLateFeeObligation extends Model {
    public const STATUS_PENDING='pending'; public const STATUS_PAID='paid';
    protected $fillable=['company_id','source_credit_id','renewal_id','original_amount','applied_amount','status','frozen_at'];
    protected function casts():array{return ['original_amount'=>'decimal:4','applied_amount'=>'decimal:4','frozen_at'=>'datetime'];}
    public function sourceCredit(){return $this->belongsTo(CobrosCredit::class,'source_credit_id');}
    public function payments(){return $this->hasMany(CobrosRenewalLateFeePayment::class,'obligation_id');}
}
