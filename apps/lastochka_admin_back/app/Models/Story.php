<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperStory
 */
class Story extends Model
{
    use ModelTools, HasImage, SoftDeletes;

    const table = "stories";
    protected $table = self::table;

    const LINK_TYPE_NONE = 'none';
    const LINK_TYPE_PRODUCT = 'product';
    const LINK_TYPE_SHOP = 'shop';
    const LINK_TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'title',
        'expires_at',
        'link_type',
        'product_id',
        'shop_id',
        'external_url',
        'position',
        'is_active',
        'is_quick_category',
        'custom_checkboxes',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'is_quick_category' => 'boolean',
        'position' => 'integer',
    ];

    protected $attributes = [
        'link_type' => self::LINK_TYPE_NONE,
        'position' => 0,
        'is_active' => true,
        'is_quick_category' => false,
    ];

    /**
     * Получение связанного товара
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Получение связанного магазина
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Получение thumbnail изображения
     */
    public function thumbnailImage(): HasOneThrough
    {
        return $this->hasOneThrough(
            Img::class,
            JoinImg::class,
            'item_id',
            'id',
            'id',
            'img_id'
        )->where([
            ['head_id', Status::CODE_STORY_THUMBNAIL],
            ['table_name', $this->getTable()],
        ]);
    }

    /**
     * Получение связки thumbnail изображения
     */
    public function joinThumbnailImage(): BelongsTo
    {
        return $this->belongsTo(JoinImg::class, 'id', 'item_id')
            ->where([
                ['head_id', Status::CODE_STORY_THUMBNAIL],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Получение slider изображений
     */
    public function images(): HasManyThrough
    {
        return $this->hasManyThrough(
            Img::class,
            JoinImg::class,
            'item_id',
            'id',
            'id',
            'img_id'
        )->where([
            ['head_id', Status::CODE_STORY_SLIDER],
            ['table_name', $this->getTable()],
        ]);
    }

    /**
     * Получение связок slider изображений
     */
    public function joinImages(): HasMany
    {
        return $this->hasMany(JoinImg::class, 'item_id', 'id')
            ->where([
                ['head_id', Status::CODE_STORY_SLIDER],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Scope: только активные истории
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope: только истёкшие истории
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: фильтрация
     */
    public function scopeFilter(Builder $query, ?array $args = null): Builder
    {
        $isActive = $args['is_active'] ?? request()->input('is_active');
        $search = $args['search'] ?? request()->input('search');
        $expired = $args['expired'] ?? request()->input('expired');

        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($search !== null && $search !== '') {
            $query->where('title', 'like', '%' . $search . '%');
        }

        if ($expired !== null) {
            if (filter_var($expired, FILTER_VALIDATE_BOOLEAN)) {
                $query->expired();
            } else {
                $query->where('expires_at', '>', now());
            }
        }

        return $query;
    }

    /**
     * Очистка нерелевантных полей при смене link_type
     */
    public function clearIrrelevantLinkFields(): void
    {
        $updates = [];

        if ($this->link_type !== self::LINK_TYPE_PRODUCT && $this->product_id !== null) {
            $updates['product_id'] = null;
        }

        if ($this->link_type !== self::LINK_TYPE_SHOP && $this->shop_id !== null) {
            $updates['shop_id'] = null;
        }

        if ($this->link_type !== self::LINK_TYPE_EXTERNAL && $this->external_url !== null) {
            $updates['external_url'] = null;
        }

        if (!empty($updates)) {
            $this->update($updates);
        }
    }
}
