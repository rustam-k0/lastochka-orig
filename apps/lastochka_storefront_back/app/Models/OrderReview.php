<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OrderReview extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'user_id',
        'rating',
        'description',
        'author_name',
        'author_phone',
        'source',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    // ==================== Relations ====================

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== Scopes ====================

    public function scopeForOrder($query, Order $order)
    {
        return $query->where('order_id', $order->id);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeByRating($query, int $rating)
    {
        return $query->where('rating', $rating);
    }
}
