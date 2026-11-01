<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Favorite extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'product_id',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ==================== Boot ====================

    protected static function booted(): void
    {
        self::creating(function (Favorite $favorite) {
            $favorite->created_at = $favorite->freshTimestamp();
        });
    }
}
