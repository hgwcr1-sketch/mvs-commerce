<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
