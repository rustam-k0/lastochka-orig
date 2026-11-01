<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Просмотры карточки товара пользователем — источник автогруппы
 * «Смотрел, но не купил». Одна строка на пару (user_id, product_id).
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property int $views_count
 * @property \Illuminate\Support\Carbon|null $first_viewed_at
 * @property \Illuminate\Support\Carbon|null $last_viewed_at
 * @property \Illuminate\Support\Carbon|null $cart_removed_at
 */
final class ProductView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'product_id',
        'views_count',
        'first_viewed_at',
        'last_viewed_at',
        'cart_removed_at',
    ];

    protected function casts(): array
    {
        return [
            'views_count' => 'integer',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'cart_removed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
