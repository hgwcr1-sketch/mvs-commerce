<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LoyaltyPortalPost extends Model
{
    public const TYPES = ['new_product', 'offer', 'promotion', 'notice'];

    protected $fillable = ['company_id', 'product_id', 'type', 'title', 'message', 'image', 'starts_at', 'ends_at', 'is_active', 'is_featured', 'sort_order'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function resolvedImagePath(): ?string
    {
        $productImage = (int) ($this->product?->company_id ?? 0) === (int) $this->company_id
            ? $this->product?->image
            : null;

        foreach ([$this->image, $productImage] as $path) {
            $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
            if ($path !== '' && ! str_contains($path, '..') && Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function resolvedImageUrl(): ?string
    {
        $path = $this->resolvedImagePath();

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
