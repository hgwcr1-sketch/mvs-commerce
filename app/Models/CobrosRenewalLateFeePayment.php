<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CobrosRenewalLateFeePayment extends Model { protected $fillable=['obligation_id','cobros_receipt_id','amount']; protected function casts():array{return ['amount'=>'decimal:4'];} }
