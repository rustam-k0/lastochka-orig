<?php

namespace App\Models;

use App\Services\Stock\StockMode;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperProduct
 */
class Product extends Model
{
  use HasFactory, ModelTools, HasImage, SoftDeletes;

  protected $fillable = [
    'title',
    'articul',
    'code',
    'plu',
    'description',
    'additional_info',
    'meta_title',
    'meta_description',
    'meta_keywords',
    'status_id',
    'publish_after_unlock', //Публиковать после разблокировки
    'weight', // Вес товара (Необходимо только в случае если товар штучный)
    'info_weight',
    'info_weight_type_id',
    'base_unit_type_id',
    'manual_quantum_step',
    'manual_quantum_unit_type_id',
    'shop_id',
    'barcode',
    'barcodes',
    'is_culinary',
    'sugar_free',
    'on_search_page',
    'served_warm',
    'pickup_only',
    'adult_only',
    'is_marked', // Маркируемый товар (Честный знак, MID-266)
    'is_new', // «Новинка»: наполняет автоматическую товарную группу fill_type=is_new
    'is_hurry_up', // «Успей купить»: наполняет автоматическую товарную группу fill_type=is_hurry_up
    'vat_code',
    'cost_price',
    'brand_id',
    'video_path',
    'parent_id',
    'is_configurable',
    'unit_type_id',
    'category_1c_id',
    'pending_category_1c_external_id',
    'stock_mode',
    'fixed_stock_quantity',
    'fixed_stock_unit_type_id',
    'effective_stock_mode',
    'effective_fixed_stock',
    'ready_fixed_time',
    'custom_checkboxes',
  ];

  protected $casts = [
    'is_culinary' => 'boolean',
    'sugar_free' => 'boolean',
    'on_search_page' => 'boolean',
    'served_warm' => 'boolean',
    'pickup_only' => 'boolean',
    'adult_only' => 'boolean',
    'is_marked' => 'boolean',
    'is_new' => 'boolean',
    'is_hurry_up' => 'boolean',
    'hidden_at' => 'datetime',
    'pickup_only_at' => 'datetime',
    'is_configurable' => 'boolean',
    'barcodes' => 'array',
    'base_unit_type_id' => 'integer',
    'manual_quantum_step' => 'integer',
    'manual_quantum_unit_type_id' => 'integer',
    'vat_code' => 'integer',
    'fixed_stock_quantity' => 'integer',
    'fixed_stock_unit_type_id' => 'integer',
  ];

  protected static function boot()
  {
    parent::boot();
    static::creating(function ($model){
      if (!$model->unit_type_id) {
        $model->unit_type_id = Type::UNIT_PIECE;
      }
      if (!$model->status_id) {
        $model->status_id = Status::CODE_NOT_PUBLISHED;
      }
    });
  }

  /**
   * Единая точка ведения даты скрытия товара (hidden_at): стоп-лист
   * «не отображать» показывает и сортирует по ней.
   *
   * Мутатор, а не observer: срабатывает на любом пути Eloquent-записи,
   * включая сохранение через withoutEvents() в консьюмерах RabbitMQ.
   * Массовые Query Builder-апдейты (Product::where()->update()) мутаторы
   * обходят — там дату проставлять явно (таких путей для status_id сейчас нет).
   */
  public function setStatusIdAttribute($value): void
  {
    $new = $value !== null ? (int) $value : null;
    $current = isset($this->attributes['status_id']) ? (int) $this->attributes['status_id'] : null;

    if ($new !== $current) {
      $this->attributes['hidden_at'] = $new === Status::CODE_ACTIVE ? null : now();
    }

    $this->attributes['status_id'] = $new;
  }

  /**
   * Дата включения «только самовывоз» (pickup_only_at) — стоп-лист
   * «не доставлять» показывает и сортирует по ней.
   */
  public function setPickupOnlyAttribute($value): void
  {
    $new = (bool) $value;
    $current = (bool) ($this->attributes['pickup_only'] ?? false);

    if ($new !== $current) {
      $this->attributes['pickup_only_at'] = $new ? now() : null;
    }

    $this->attributes['pickup_only'] = $new;
  }

  public function prices(): ?HasMany
  {
    return $this->hasMany(Price::class)->whereTypeId(Type::PRICE_DEFAULT);
  }

  public function defaultPrice(): ?HasOne
  {
    return $this->hasOne(Price::class)->whereTypeId(Type::PRICE_DEFAULT);
  }

  public function remains(): ?HasMany
  {
    return $this->hasMany(Remain::class);
  }

  /**
   * Ступени «цены от количества» товара по всем магазинам (MID-308).
   */
  public function priceTiers(): HasMany
  {
    return $this->hasMany(PriceTier::class)->orderBy('shop_id')->orderBy('min_quantity');
  }

  public function brand(): BelongsTo
  {
    return $this->belongsTo(Brand::class);
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(self::class, 'parent_id');
  }

  public function children(): HasMany
  {
    return $this->hasMany(self::class, 'parent_id');
  }

  public function categories(): BelongsToMany
  {
    return $this->belongsToMany(Category::class)
      ->withPivot('position')
      ->orderBy('category_product.position');
  }

  public function category1c(): BelongsTo
  {
    return $this->belongsTo(Category1c::class, 'category_1c_id');
  }

  public function attributes(): ?HasMany
  {
    return $this->hasMany(ProductAttribute::class);
  }

  public function images(): ?HasManyThrough
  {
    return $this->hasManyThrough(Img::class, JoinImg::class,
      'item_id',
      'id',
      'id',
      'img_id')
      ->where([
        ["head_id", Status::CODE_SLIDER_IMG],
        ["table_name", $this->getTable()]
      ])
      ->orderBy(JoinImg::table . '.id');
  }

  public function joinImages(): ?HasMany
  {
    return $this->hasMany(JoinImg::class, "item_id", "id")->where([
      ["head_id", Status::CODE_SLIDER_IMG],
      ["table_name", $this->getTable()]
    ]);
  }

  public function cookTime(): ?HasOne
  {
    return $this->hasOne(CookTime::class);
  }


  public function scopeShop(Builder $query): Builder
  {
    $shop_id = \Auth::user()->shops()->first()?->shop_id;
    if (\Auth::user()->isGlobalScope()) {
      return $query;
    }

    return $query->where('shop_id',$shop_id);
  }

  /**
   * Товар «есть в магазине»: по нему заведена цена ЛИБО остаток этого
   * магазина. В сетевом каталоге карточка принадлежит якорному магазину
   * (products.shop_id), поэтому фильтр списка админки по shop_id @eq
   * отдавал бы пустую выдачу для любого не-якорного магазина — наличие
   * товара в магазине определяют строки prices/remains.
   *
   * null = фильтр не задан (тот же null-safe паттерн, что у scopeHasImg:
   * Lighthouse @scope при явном null всё равно вызывает scope).
   */
  public function scopeAvailableInShop(Builder $query, ?int $shopId = null): Builder
  {
    if ($shopId === null) {
      return $query;
    }

    return $query->where(function (Builder $q) use ($shopId) {
      $q->whereHas('remains', fn ($r) => $r->where('shop_id', $shopId))
        ->orWhereHas('prices', fn ($p) => $p->where('shop_id', $shopId));
    });
  }

  public function scopeConfigurable(Builder $query): Builder
  {
    return $query->where('is_configurable', true);
  }

  public function scopeSimple(Builder $query): Builder
  {
    return $query->where('is_configurable', false)->whereNull('parent_id');
  }

  public function scopeVariants(Builder $query): Builder
  {
    return $query->whereNotNull('parent_id');
  }

  public function scopeExcludeChildren(Builder $query, bool $excludeChildren = true): Builder
  {
    if ($excludeChildren) {
      return $query->whereNull('parent_id');
    }
    return $query;
  }

  public function scopeOnlyChildren(Builder $query, bool $onlyChildren = true): Builder
  {
    if ($onlyChildren) {
      return $query->whereNotNull('parent_id');
    }
    return $query;
  }

  public function scopeHasImg(Builder $query, ?bool $hasImg = null): Builder
  {
    // null = фильтр не задан (аргумент не передан или передан явный null) —
    // не фильтруем. Lighthouse @scope при явном `has_img: null` всё равно
    // вызывает scope и передаёт null, поэтому сигнатура nullable.
    if ($hasImg === null) {
      return $query;
    }
    if ($hasImg) {
      return $query->whereHas('img');
    }
    return $query->whereDoesntHave('img');
  }

  public function scopeHasReviews(Builder $query, ?bool $hasReviews = null): Builder
  {
    // null = фильтр не задан; тот же null-safe паттерн, что у scopeHasImg.
    if ($hasReviews === null) {
      return $query;
    }
    if ($hasReviews) {
      return $query->whereHas('reviews');
    }
    return $query->whereDoesntHave('reviews');
  }

  public function scopeFillPercentLt(Builder $query, ?int $percent = null): Builder
  {
    // null = фильтр не задан; Lighthouse @scope при явном null всё равно
    // вызывает scope (тот же паттерн, что scopeHasImg).
    if ($percent === null) {
      return $query;
    }

    return $query->where('fill_percent', '<', $percent);
  }

  public function scopeFillPercentGte(Builder $query, ?int $percent = null): Builder
  {
    // null = фильтр не задан; тот же null-safe паттерн, что у scopeFillPercentLt/scopeHasImg.
    if ($percent === null) {
      return $query;
    }

    return $query->where('fill_percent', '>=', $percent);
  }

  /**
   * Весовой товар (продаётся за вес/объём, не поштучно) — единый источник
   * истины для всей квант-логики. Опирается на info_weight_type_id: товар
   * весовой, если единица задана и это не «штука».
   *
   * info_weight_type_id nullable и не имеет boot-дефолта, поэтому NULL
   * трактуется как «не весовой» явно — без этого `!= PIECE` дал бы в PHP
   * true для NULL (а в SQL — наоборот), что рассинхронило бы isWeight() и
   * scopeWeight().
   */
  public function isWeight(): bool
  {
    return $this->info_weight_type_id !== null
      && (int) $this->info_weight_type_id !== Type::INFO_WEIGHT_PIECE;
  }

  public function scopeWeight(Builder $query): Builder
  {
    return $query->whereNotNull('info_weight_type_id')
      ->where('info_weight_type_id', '!=', Type::INFO_WEIGHT_PIECE);
  }


  public function supplements(): HasMany
  {
    return $this->hasMany(Supplement::class);
  }

  public function documents(): HasMany
  {
    return $this->hasMany(ProductDocument::class)->orderBy('position');
  }

  public function merchantSales(): BelongsToMany
  {
    return $this->belongsToMany(
      MerchantSale::class,
      'merchant_sale_products',
      'product_id',
      'merchant_sale_id'
    );
  }

  public function merchantProductGroups(): BelongsToMany
  {
    return $this->belongsToMany(
      MerchantProductGroup::class,
      'merchant_product_group_items',
      'product_id',
      'merchant_product_group_id'
    );
  }

  /**
   * Получение отзывов на товар
   */
  public function reviews(): HasMany
  {
    return $this->hasMany(ProductReview::class);
  }

  public function attributeChangesLog(): HasMany
  {
    return $this->hasMany(ProductAttributeChangesLog::class);
  }

  /**
   * Итоговая цена с fallback на parent'а (для вариативных товаров).
   */
  public function getEffectivePriceAttribute(): ?float
  {
    $ownPrice = $this->defaultPrice?->value;
    if ($ownPrice !== null) {
      return (float) $ownPrice;
    }

    $parentPrice = $this->parent?->defaultPrice?->value;

    return $parentPrice !== null ? (float) $parentPrice : null;
  }

  /**
   * Итоговая галерея изображений с fallback на parent'а.
   */
  public function getDisplayImagesAttribute(): \Illuminate\Support\Collection
  {
    $own = $this->images ?? collect();
    if ($own->isNotEmpty()) {
      return $own;
    }

    return $this->parent?->images ?? collect();
  }

  /**
   * Количество товара в группе (из pivot).
   */
  public function getPivotQuantityAttribute(): ?int
  {
      return $this->pivot?->quantity;
  }

  /** Курьерка/purchase: режим, при котором остаток не блокирует (ignore или fixed). */
  public function effectiveStockIgnored(): bool
  {
      return in_array($this->effective_stock_mode, StockMode::ignoringModes(), true);
  }
}
