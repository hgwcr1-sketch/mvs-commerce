<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCobrosTenant;
use App\Models\Concerns\HasCobrosRoute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CobrosRouteUser extends Pivot
{
    use BelongsToCobrosTenant, HasCobrosRoute;

    protected $table = 'cobros_route_user';

    public $incrementing = true;

    protected $fillable = ['company_id', 'cobros_route_id', 'user_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $membership) {
            if (! DB::table('company_user')->where('company_id', $membership->company_id)->where('user_id', $membership->user_id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'El usuario no pertenece a la empresa de la ruta.']);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
