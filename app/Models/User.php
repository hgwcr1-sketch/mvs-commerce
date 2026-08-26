<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'phone',
    'photo',
    'password',
    'is_active',
    'is_platform_admin',
    'last_login_at',
])]

#[Hidden([
    'password',
    'remember_token',
])]

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Sucursales asignadas al usuario.
     */
    public function branches()
    {
        return $this->belongsToMany(Branch::class)
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_active && $this->is_platform_admin;
    }

    public function updateLastLogin(): void
    {
        $this->update([
            'last_login_at' => now(),
        ]);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Empresas a las que tiene acceso este usuario.
     */
    public function companies()
    {
        return $this->belongsToMany(Company::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Roles del usuario dentro de empresas.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'company_user')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /**
     * Empresas que pertenecen al usuario.
     */
    public function ownedCompanies()
    {
        return $this->hasMany(Company::class, 'owner_user_id');
    }

    public function companyAllowance()
    {
        return $this->hasOne(CompanyAllowance::class);
    }

    public function availableCompanySlots(): int
    {
        $allowed = $this->companyAllowance?->allowed_companies ?? 0;

        $used = $this->ownedCompanies()->count();

        return max(0, $allowed - $used);
    }

    public function canCreateCompany(): bool
    {
        return $this->availableCompanySlots() > 0;
    }

    public function roleInCompany(Company $company): ?Role
    {
        $companyAccess = $this->companies()
            ->where('companies.id', $company->id)
            ->first();

        if (! $companyAccess || ! $companyAccess->pivot->role_id) {
            return null;
        }

        return Role::where('company_id', $company->id)
            ->find($companyAccess->pivot->role_id);
    }

    public function hasPermission(string $permission, Company $company): bool
    {
        $role = $this->roleInCompany($company);

        if (! $role || ! $role->is_active) {
            return false;
        }

        return $role->permissions()
            ->where('permissions.name', $permission)
            ->where('permissions.is_active', true)
            ->exists();
    }

    public function openedCashSessions()
    {
        return $this->hasMany(CashSession::class, 'opened_by');
    }

    public function cashMovements()
    {
        return $this->hasMany(CashMovement::class, 'created_by');
    }
}
