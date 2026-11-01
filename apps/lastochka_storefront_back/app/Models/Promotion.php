<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Promotion extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'source',
        'external_id',
        'title',
        'slug',
        'description',
        'image_path',
        'discount_percent',
        'starts_at',
        'ends_at',
        'is_active',
        'position',
        'custom_checkboxes',
    ];

    /**
     * Резолв по slug в implicit route model binding ({promotion} в routes/api.php)
     * вместо id — slug генерируется на admin_back глобально уникальным
     * (ModelTools::getUniqueLink()), доп. scope не нужен.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'discount_percent' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'position' => 'integer',
            'custom_checkboxes' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_products')
            ->withTimestamps();
    }

    // ==================== Scopes ====================

    /**
     * Активные акции (is_active=true и текущая дата в диапазоне starts_at..ends_at).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    /**
     * Акции для конкретного магазина.
     */
    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * Фильтрация акций по типу пользователя (B2B/B2C).
     *
     * Зеркалит Store::scopeForUserType(): при включённом модуле `b2b`
     * не-B2B пользователь/гость не должен видеть акции B2B-магазинов.
     */
    public function scopeForUserType(Builder $query, ?User $user): Builder
    {
        if (! app(ModuleConfigService::class)->isB2bEnabled()) {
            return $query;
        }

        if ($user?->is_b2b) {
            return $query;
        }

        return $query->whereHas('store', fn (Builder $q) => $q->where('is_b2b', false));
    }

    /**
     * Сортировка по позиции, затем по дате создания.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderByDesc('created_at');
    }

    // ==================== Business logic ====================

    /**
     * Видна ли акция данному пользователю (B2B/B2C).
     *
     * Единственный источник правды для проверки одного экземпляра — зеркалит
     * условие scopeForUserType(), включая fail-closed поведение: если магазин
     * недоступен (soft-deleted/удалён), акция считается НЕвидимой не-B2B
     * пользователю, а не видимой по умолчанию.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! app(ModuleConfigService::class)->isB2bEnabled()) {
            return true;
        }

        if ($user?->is_b2b) {
            return true;
        }

        return $this->store !== null && ! $this->store->is_b2b;
    }

    // ==================== Accessors ====================

    /**
     * Количество товаров в акции.
     */
    public function getProductsCountAttribute(): int
    {
        return $this->products()->count();
    }
}
