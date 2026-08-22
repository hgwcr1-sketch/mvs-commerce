<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Builder; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
class Layaway extends Model {
 public const STATUS_ACTIVE='active', STATUS_PAID='paid', STATUS_DELIVERED='delivered', STATUS_EXPIRED='expired', STATUS_CANCELLED='cancelled';
 protected $fillable=['company_id','branch_id','customer_id','created_by','delivered_sale_id','number','status','currency_code','total','paid_total','balance_due','expires_at','paid_at','delivered_at','cancelled_at','expired_at','cancelled_by','cancel_reason','notes'];
 protected function casts():array{return['total'=>'decimal:4','paid_total'=>'decimal:4','balance_due'=>'decimal:4','expires_at'=>'date','paid_at'=>'datetime','delivered_at'=>'datetime','cancelled_at'=>'datetime','expired_at'=>'datetime'];}
 public function company():BelongsTo{return $this->belongsTo(Company::class);} public function customer():BelongsTo{return $this->belongsTo(Customer::class);} public function branch():BelongsTo{return $this->belongsTo(Branch::class);} public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by');} public function sale():BelongsTo{return $this->belongsTo(Sale::class,'delivered_sale_id');} public function items():HasMany{return $this->hasMany(LayawayItem::class);} public function payments():HasMany{return $this->hasMany(LayawayPayment::class);}
 public function scopeForCompany(Builder $q,int $id):Builder{return $q->where('company_id',$id);} public function scopeForBranch(Builder $q,int $id):Builder{return $q->where('branch_id',$id);}
}
