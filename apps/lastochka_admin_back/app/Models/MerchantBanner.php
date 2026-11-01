<?php

namespace App\Models;

use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin IdeHelperMerchantBanner
 */
class MerchantBanner extends Model
{
    use HasFactory, ModelTools, HasImage, SoftDeletes;

    const table = "merchant_banners";
    protected $table = self::table;

    const LINK_TYPE_NONE = 'none';
    const LINK_TYPE_PRODUCT = 'product';
    const LINK_TYPE_CATEGORY = 'category';

    protected $fillable = [
        'title',
        'link_type',
        'product_id',
        'category_id',
        'display_category_id',
        'shop_id',
        'is_active',
        'position',
        'custom_checkboxes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
        'product_id' => 'integer',
        'category_id' => 'integer',
        'display_category_id' => 'integer',
        'shop_id' => 'integer',
    ];

    protected $attributes = [
        'link_type' => self::LINK_TYPE_NONE,
        'is_active' => true,
        'position' => 0,
    ];

    /**
     * Получение магазина
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Получение связанного товара
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Получение связанной категории
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Категория, на странице которой показывается баннер
     * (NULL = баннер главной страницы)
     */
    public function displayCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'display_category_id');
    }

    /**
     * Получение изображения баннера
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
            ['head_id', Status::CODE_MERCHANT_BANNER_IMG],
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
                ['head_id', Status::CODE_MERCHANT_BANNER_IMG],
                ['table_name', $this->getTable()],
            ]);
    }

    /**
     * Scope: только активные баннеры
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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
        $query->forCurrentUser();

        $shopId = $args['shop_id'] ?? request()->input('shop_id');
        $isActive = $args['is_active'] ?? request()->input('is_active');
        $linkType = $args['link_type'] ?? request()->input('link_type');
        $search = $args['search'] ?? request()->input('search');

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        if ($isActive !== null) {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        if ($linkType !== null) {
            $query->where('link_type', $linkType);
        }

        if ($search !== null && $search !== '') {
            $query->where('title', 'like', '%' . $search . '%');
        }

        return $query;
    }

    /**
     * Scope: сортировка по умолчанию (position ASC, created_at DESC)
     */
    public function scopeSorter(Builder $query, ?array $args = null): Builder
    {
        $ordering = $args['ordering'] ?? request()->input('ordering');

        if (!empty($ordering)) {
            foreach ($ordering as $order) {
                $query->orderBy($order['column'], $order['order'] ?? 'asc');
            }
        } else {
            $query->orderBy('position', 'asc')->orderBy('created_at', 'desc');
        }

        return $query;
    }

    /**
     * Очистка нерелевантных полей в зависимости от link_type
     */
    public function clearIrrelevantLinkFields(): void
    {
        switch ($this->link_type) {
            case self::LINK_TYPE_NONE:
                $this->product_id = null;
                $this->category_id = null;
                break;
            case self::LINK_TYPE_PRODUCT:
                $this->category_id = null;
                break;
            case self::LINK_TYPE_CATEGORY:
                $this->product_id = null;
                break;
        }
    }
}
