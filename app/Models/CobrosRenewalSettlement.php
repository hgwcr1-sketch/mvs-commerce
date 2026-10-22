<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CobrosRenewalSettlement extends Model { protected $fillable=['company_id','source_credit_id','renewal_id','pending_principal','pending_interest','pending_total','transferred_late_fee','settled_at']; protected function casts():array{return ['pending_principal'=>'decimal:4','pending_interest'=>'decimal:4','pending_total'=>'decimal:4','transferred_late_fee'=>'decimal:4','settled_at'=>'datetime'];} }
