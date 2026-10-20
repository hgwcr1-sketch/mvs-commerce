<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CobrosMaestroIdentityBinding extends Model
{
    protected $table = 'cobros_maestro_identity_bindings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'valid_from' => 'datetime', 'valid_until' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
