<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CobrosMaestroCompanyBinding extends Model
{
    protected $table = 'cobros_maestro_company_bindings';
    protected $guarded = ['id'];
    protected function casts(): array { return ['approved_at' => 'datetime', 'revoked_at' => 'datetime']; }
}
