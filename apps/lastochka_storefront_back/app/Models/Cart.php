<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Cart extends Model
{
    use HasFactory;

    /**
     * Полный набор relation'ов для сериализации корзины через CartResource/ClientCartResource.
     * Использовать в каждом Action, который возвращает Cart наружу — чтобы выдача всегда
     * содержала supplements и promocodes (иначе CartItemResource отдаёт supplementsTotal=0).
     */
    public const RESPONSE_RELATIONS = [
        'items.product.images',
        'items.product.supplements.values',
        'items.store',
        'items.supplements.supplement',
        'items.supplements.values',
        'items.childItems.sourceSupplementValue.product.images',
        'promocodes.promocode',
    ];

    protected $fillable = [
        'user_id',
    ];

    // ==================== Relations ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function promocodes(): HasMany
    {
        return $this->hasMany(CartPromocode::class);
    }

    // ==================== Scopes ====================

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }
}
