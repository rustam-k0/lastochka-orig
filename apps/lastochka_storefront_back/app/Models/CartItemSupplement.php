<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CartItemSupplement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'cart_item_id',
        'supplement_id',
    ];

    // ==================== Relations ====================

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function values(): BelongsToMany
    {
        return $this->belongsToMany(
            SupplementValue::class,
            'join_cart_item_supplements_supplement_values',
            'cart_item_supplement_id',
            'supplement_value_id',
        );
    }
}
