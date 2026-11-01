<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromocodeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Promocode extends Model
{
    use HasFactory;

    public const SCOPE_STORE = 'store';
    public const SCOPE_PLATFORM = 'platform';

    protected $fillable = [
        'title',
        'code',
        'type',
        'value',
        'min_order_amount',
        'store_id',
        'scope',
        'eligible_amount_threshold',
        'source',
        'external_id',
        'max_uses',
        'max_uses_per_client',
        'used_count',
        'starts_at',
        'ends_at',
        'is_active',
        'binding_type',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'type' => PromocodeType::class,
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_uses' => 'integer',
            'max_uses_per_client' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // ==================== Relations ====================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promocode_products')
            ->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'promocode_categories')
            ->withTimestamps();
    }

    /**
     * Использования промокода — для проверки per-client лимита и учёта истории.
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PromocodeUsage::class);
    }

    // ==================== Scopes ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    public function scopeForStore($query, int $storeId)
    {
        return $query->where(function ($q) use ($storeId) {
            $q->where('store_id', $storeId)
                ->orWhereNull('store_id');
        });
    }

    // ==================== Methods ====================

    public function isValid(): bool
    {
        return $this->is_active
            && $this->starts_at->lte(now())
            && $this->ends_at->gte(now())
            && ($this->max_uses === null || $this->activeUsagesCount() < $this->max_uses);
    }

    /**
     * Сколько раз промокод расходует общий лимит max_uses: заказы, которые не отменены.
     *
     * used_count для лимита не годится: он растёт при каждом оформлении и при отмене
     * заказа не уменьшается.
     */
    public function activeUsagesCount(): int
    {
        return $this->usages()->active()->count();
    }

    public function isForStore(int $storeId): bool
    {
        return $this->store_id === null || $this->store_id === $storeId;
    }

    /**
     * Platform-wide промокод — действует на всю корзину пользователя
     * без привязки к конкретному магазину.
     */
    public function isPlatformWide(): bool
    {
        return $this->scope === self::SCOPE_PLATFORM;
    }

    /**
     * Проверить, подходит ли товар под привязку промокода.
     */
    public function matchesProduct(Product $product): bool
    {
        return match ($this->binding_type) {
            'products' => $this->products->contains('id', $product->id),
            'categories' => $this->categories->pluck('id')
                ->intersect($product->categories->pluck('id'))
                ->isNotEmpty(),
            default => true,
        };
    }
}
