<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LoyaltyPortalSetting extends Model
{
    protected $fillable = [
        'company_id',
        'is_active',
        'show_active_offers',
        'portal_name',
        'welcome_message',
        'portal_logo',
        'portal_icon',
        'brand_primary_color',
        'brand_accent_color',
        'instagram_url',
        'facebook_url',
        'tiktok_url',
    ];

    protected $casts = ['is_active' => 'boolean', 'show_active_offers' => 'boolean'];

    public function displayName(Company $company): string
    {
        return trim((string) $this->portal_name) ?: $company->trade_name;
    }

    public function logoUrl(Company $company): ?string
    {
        $path = $this->existingPublicPath($this->portal_logo);
        if ($path) {
            return Storage::disk('public')->url($path);
        }

        $companyLogo = ltrim(str_replace('\\', '/', trim((string) $company->logo)), '/');

        return $companyLogo !== '' && ! str_contains($companyLogo, '..') ? asset('storage/'.$companyLogo) : null;
    }

    public function iconUrl(): ?string
    {
        $path = $this->existingPublicPath($this->portal_icon);

        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function existingPublicPath(?string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');

        return $path !== '' && ! str_contains($path, '..') && Storage::disk('public')->exists($path) ? $path : null;
    }
}
