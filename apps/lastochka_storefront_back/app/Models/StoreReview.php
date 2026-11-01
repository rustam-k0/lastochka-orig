<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class StoreReview extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'source',
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

    public function scopeForStore($query, Store $store)
    {
        return $query->where('store_id', $store->id);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }
}
