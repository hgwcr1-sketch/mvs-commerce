<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_id',
    'name',
    'description',
    'is_active',
])]

class Role extends Model
{
    /**
     * Conversión automática de atributos.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Empresa a la que pertenece este rol.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Permisos asignados a este rol.
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class)
            ->withTimestamps();
    }

    /**
     * Usuarios que tienen este rol dentro de la empresa.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot('company_id')
            ->withTimestamps();
    }
}