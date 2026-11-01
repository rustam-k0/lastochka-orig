<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin IdeHelperMerchantPromocode
 */
class MerchantPromocode extends Model
{
    use HasFactory, ModelTools, SoftDeletes;

    const table = "merchant_promocodes";
    protected $table = self::table;

    const STATUS_ACTIVE = 'active';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_INACTIVE = 'inactive';

    /**
     * Scope промокода: привязка к магазину vs платформе (маркетплейс-режим).
     */
    const SCOPE_STORE = 'store';
    const SCOPE_PLATFORM = 'platform';

    protected $fillable = [
        'shop_id',
        'scope',
        'title',
        'code',
        'discount_percent',
        'discount_type',
        'discount_value',
        'binding_type',
        'valid_from',
        'valid_to',
        'is_active',
        'max_uses',
        'max_uses_per_client',
    ];

    protected $attributes = [
        'scope' => self::SCOPE_STORE,
        'is_active' => true,
        'used_count' => 0,
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'is_active' => 'boolean',
        'discount_percent' => 'integer',
        'discount_value' => 'integer',
        'shop_id' => 'integer',
        'max_uses' => 'integer',
        'max_uses_per_client' => 'integer',
        'used_count' => 'integer',
    ];

    /**
     * Освобождает код, занятый мягко удалённым промокодом того же магазина:
     * unique(shop_id, code) не учитывает deleted_at, и без этого INSERT/UPDATE падает с 1062.
     * Через query builder, чтобы не слать delete-событие в outbox: витрина удалила промокод
     * ещё при мягком удалении.
     */
    public static function releaseTrashedCode(?int $shopId, string $code): void
    {
        static::onlyTrashed()
            ->where('shop_id', $shopId)
            ->where('code', $code)
            ->toBase()
            ->delete();
    }

    /**
     * Является ли промокод platform-wide (действует на всю корзину).
     */
    public function isPlatformWide(): bool
    {
        return $this->scope === self::SCOPE_PLATFORM;
    }

    /**
     * Получение магазина
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Привязанные товары
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'merchant_promocode_products')
            ->withTimestamps();
    }

    /**
     * Привязанные категории
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'merchant_promocode_categories')
            ->withTimestamps();
    }

    /**
     * Действует ли промокод сейчас
     */
    public function getIsValidAttribute(): bool
    {
        return $this->is_active
            && $this->valid_from <= now()->startOfDay()
            && $this->valid_to >= now()->startOfDay();
    }

    /**
     * Статус промокода
     */
    public function getStatusAttribute(): string
    {
        if (!$this->is_active) {
            return self::STATUS_INACTIVE;
        }

        $now = now()->startOfDay();

        if ($this->valid_to < $now) {
            return self::STATUS_EXPIRED;
        }

        if ($this->valid_from > $now) {
            return self::STATUS_SCHEDULED;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Scope: только действующие (active + valid dates)
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('valid_from', '<=', now()->startOfDay())
            ->where('valid_to', '>=', now()->startOfDay());
    }

    /**
     * Scope: активные по датам
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('valid_from', '<=', now()->startOfDay())
            ->where('valid_to', '>=', now()->startOfDay());
    }

    /**
     * Scope: запланированные
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('valid_from', '>', now()->startOfDay());
    }

    /**
     * Scope: истёкшие
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('valid_to', '<', now()->startOfDay());
    }

    /**
     * Scope: фильтрация по статусу
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        return match (strtolower($status)) {
            self::STATUS_ACTIVE => $query->active(),
            self::STATUS_SCHEDULED => $query->scheduled(),
            self::STATUS_EXPIRED => $query->expired(),
            self::STATUS_INACTIVE => $query->where('is_active', false),
            default => $query,
        };
    }

    /**
     * Scope: фильтрация по магазинам текущего пользователя
     * Админ видит всё (включая platform-wide), селлер — только свои магазины.
     * Platform-wide промокоды (shop_id=NULL) видны только админам.
     */
    public function scopeForCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if (!$user || $user->isGlobalScope()) {
            return $query;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        // whereIn не матчит NULL, поэтому scope=platform автоматически
        // скрыт от селлеров.
        return $query->whereIn('shop_id', $shopIds);
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $query->forCurrentUser();

        $shopId = $args['shop_id'] ?? request()->input('shop_id');
        $isActive = $args['is_active'] ?? request()->input('is_active');
        $status = $args['status'] ?? request()->input('status');
        $search = $args['search'] ?? request()->input('search');

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($status !== null) {
            $query->status(strtolower($status));
        }

        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('code', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    /**
     * Scope: сортировка
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? request()->input('ordering');

        if (!empty($ordering)) {
            foreach ($ordering as $order) {
                $query->orderBy($order['orderColumn'], $order['orderBy'] ?? 'asc');
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }
}
