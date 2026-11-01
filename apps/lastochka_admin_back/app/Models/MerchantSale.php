<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperMerchantSale
 */
class MerchantSale extends Model
{
    use HasFactory, ModelTools, HasImage, SoftDeletes;

    const table = "merchant_sales";
    protected $table = self::table;

    const STATUS_UPCOMING = 'upcoming';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'title',
        'description',
        'shop_id',
        'discount_percent',
        'starts_at',
        'ends_at',
        'is_active',
        'custom_checkboxes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'discount_percent' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->link = self::getUniqueLink($model->title);
        });
        static::updating(function ($model) {
            $model->link = self::getUniqueLink($model->title, $model->id);
        });
    }

    /**
     * Получение магазина
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Получение товаров акции
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'merchant_sale_products',
            'merchant_sale_id',
            'product_id'
        )->withPivot('created_at');
    }

    /**
     * Получение изображения акции
     */
    public function image(): HasOneThrough
    {
        return $this->hasOneThrough(
            Img::class,
            JoinImg::class,
            'item_id',
            'id',
            'id',
            'img_id'
        )->where([
            ['head_id', Status::CODE_MERCHANT_SALE_IMG],
            ['table_name', $this->getTable()],
        ]);
    }

    /**
     * Получение связки изображения
     */
    public function joinImage(): BelongsTo
    {
        return $this->belongsTo(JoinImg::class, 'id', 'item_id')
            ->where([
                ['head_id', Status::CODE_MERCHANT_SALE_IMG],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Вычисляемый статус акции
     */
    public function getStatusAttribute(): string
    {
        $now = now();

        if ($this->starts_at > $now) {
            return self::STATUS_UPCOMING;
        }

        if ($this->ends_at < $now) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }

    /**
     * Количество товаров в акции
     */
    public function getProductsCountAttribute(): int
    {
        return $this->products()->count();
    }

    /**
     * Scope: только активные акции (по флагу is_active и датам)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    /**
     * Scope: предстоящие акции
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>', now());
    }

    /**
     * Scope: истёкшие акции
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('ends_at', '<', now());
    }

    /**
     * Scope: фильтрация по статусу
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null) {
            return $query;
        }

        return match ($status) {
            self::STATUS_UPCOMING => $query->upcoming(),
            self::STATUS_ACTIVE => $query->active(),
            self::STATUS_EXPIRED => $query->expired(),
            default => $query,
        };
    }

    /**
     * Scope: фильтрация по магазинам текущего пользователя
     * Админ видит всё, селлер — только свои магазины
     */
    public function scopeForCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if (!$user || $user->isGlobalScope()) {
            return $query;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        return $query->whereIn('shop_id', $shopIds);
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        // Автоматически применяем фильтр по пользователю
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
            $query->where('title', 'like', '%' . $search . '%');
        }

        return $query;
    }

    /**
     * Синхронизация товаров акции
     */
    public function syncProducts(array $productIds): void
    {
        $this->products()->sync($productIds);
    }
}
