<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LoyaltyPortalPostImage extends Model
{
    protected $fillable = ['loyalty_portal_post_id', 'company_id', 'path', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPortalPost::class, 'loyalty_portal_post_id');
    }

    public function resolvedPath(): ?string
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $this->path)), '/');
        if ($path !== '' && ! str_contains($path, '..') && Storage::disk('public')->exists($path)) {
            return $path;
        }

        return null;
    }

    public function resolvedUrl(): ?string
    {
        $path = $this->resolvedPath();

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
