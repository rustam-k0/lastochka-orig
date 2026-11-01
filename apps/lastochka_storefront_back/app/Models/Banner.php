<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Banner extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'store_id',
        'source',
        'external_id',
        'title',
        'image_path',
        'link_type',
        'link_product_id',
        'link_category_id',
        'display_category_id',
        'position',
        'is_active',
        'starts_at',
        'ends_at',
        'custom_checkboxes',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'link_type' => LinkType::class,
            'is_active' => 'boolean',
            'position' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'custom_checkboxes' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function linkProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'link_product_id');
    }

    public function linkCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'link_category_id');
    }

    /**
     * Категория, на странице которой показывается баннер
     * (NULL = баннер главной страницы).
     */
    public function displayCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'display_category_id');
    }

    // ==================== Scopes ====================

    /**
     * Только активные баннеры.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Баннеры главной страницы (без привязки к категории).
     */
    public function scopeForMainPage(Builder $query): Builder
    {
        return $query->whereNull('display_category_id');
    }

    /**
     * Баннеры страницы указанной категории.
     */
    public function scopeForDisplayCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('display_category_id', $categoryId);
    }

    /**
     * Баннеры места показа: кастомный чекбокс `$placement` включён.
     *
     * Ключ интерполируется в JSON-path, поэтому вызывающая сторона обязана
     * пропустить его через список `ModuleConfigService::bannerPlacements()`
     * (там regex слуга) — см. GetStoreBannersRequest.
     */
    public function scopeForPlacement(Builder $query, string $placement): Builder
    {
        return $query->where("custom_checkboxes->{$placement}", true);
    }

    /**
     * Фильтр по датам: starts_at <= now() <= ends_at (если указаны).
     */
    public function scopeWithinDates(Builder $query): Builder
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            });
    }
}
