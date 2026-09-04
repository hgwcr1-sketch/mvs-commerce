<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class LoyaltyPortalPost extends Model
{
    public const TYPES = ['new_product', 'offer', 'promotion', 'notice'];

    public const CTA_LABELS = [
        'buy' => 'Comprar',
        'product' => 'Ver producto',
        'whatsapp' => 'WhatsApp',
        'reserve' => 'Reservar',
        'more' => 'Ver más',
        'external' => 'URL externa',
    ];

    protected $fillable = ['company_id', 'product_id', 'type', 'title', 'message', 'cta_type', 'cta_url', 'image', 'starts_at', 'ends_at', 'is_active', 'is_featured', 'sort_order'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'is_featured' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(LoyaltyPortalPostImage::class, 'loyalty_portal_post_id')->orderBy('sort_order')->orderBy('id');
    }

    public function resolvedImagePaths(): array
    {
        $ownImage = ltrim(str_replace('\\', '/', trim((string) $this->image)), '/');
        if ($ownImage !== '' && ! str_contains($ownImage, '..') && Storage::disk('public')->exists($ownImage)) {
            return [$ownImage];
        }

        $productImage = (int) ($this->product?->company_id ?? 0) === (int) $this->company_id
            ? $this->product?->image
            : null;
        $paths = [];
        if ($productImage) {
            $path = ltrim(str_replace('\\', '/', trim((string) $productImage)), '/');
            if ($path !== '' && ! str_contains($path, '..') && Storage::disk('public')->exists($path)) {
                $paths[] = $path;
            }
        }

        foreach ($this->images as $image) {
            $path = $image->resolvedPath();
            if ($path) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    public function resolvedImagePath(): ?string
    {
        return $this->resolvedImagePaths()[0] ?? null;
    }

    public function resolvedImageUrls(): array
    {
        return array_values(array_filter(array_map(
            fn ($path) => Storage::disk('public')->url($path),
            $this->resolvedImagePaths()
        )));
    }

    public function resolvedImageUrl(?string $path = null): ?string
    {
        $path = $path ?: $this->resolvedImagePaths()[0] ?? null;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function ctaLabel(): ?string
    {
        return self::CTA_LABELS[$this->cta_type] ?? null;
    }
}
