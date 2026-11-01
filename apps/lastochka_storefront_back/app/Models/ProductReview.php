<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductReview extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'source',
        'store_id',
        'user_id',
        'order_id',
        'rating',
        'description',
        'author_name',
        'author_phone',
        'is_published',
        'published_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ==================== Scopes ====================

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForProduct($query, Product $product)
    {
        return $query->where('product_id', $product->id);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }
}
