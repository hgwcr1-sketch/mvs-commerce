<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class LayawayAlert extends Model {protected $fillable=['layaway_id','company_id','type','notified_at']; protected function casts():array{return['notified_at'=>'datetime'];}}
