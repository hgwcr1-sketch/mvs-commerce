<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 7B — identidad global CobrOS/Buró (SIN company_id).
 * Clave: country_code + identification_type + normalized_identification.
 * El nombre NUNCA participa en la identidad ni se deduplica por nombre.
 */
class CobrosBureauIdentity extends Model
{
    protected $table = 'cobros_bureau_identities';

    protected $fillable = [
        'country_code',
        'identification_type',
        'normalized_identification',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(CobrosBureauIdentityDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CobrosBureauEvent::class);
    }
}
