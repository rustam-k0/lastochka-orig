<?php

namespace App\Models;

use App\Services\ShopService;
use App\Support\NetworkCatalogMode;
use App\Traits\HasImage;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperProduct
 */
class Product extends Model
{
  use ModelTools, HasImage, SoftDeletes;
  
  protected $fillable = [
    'name',
    'article',
    'barcode',
    'external_id',
    'description',
    'shop_id',
    'ready_fixed_time',
    // Другие поля, которые могут быть в модели
  ];


  public function prices(): ?HasMany
  {
    return $this->hasMany(Price::class)->whereTypeId(Type::PRICE_DEFAULT);
  }

  public function price(): ?HasOne
  {
    return $this->hasOne(Price::class)->whereTypeId(Type::PRICE_DEFAULT);
  }

  public function remains(): ?HasMany
  {
    return $this->hasMany(Remain::class);
  }
  public function remain(): ?HasOne
  {
    return $this->hasOne(Remain::class);
  }

  /**
   * Базовая цена товара в конкретном магазине (сетевой каталог: цены пер-магазин).
   */
  public function priceForShop(int $shopId): ?Price
  {
    return $this->prices()->where('shop_id', $shopId)->first();
  }

  /**
   * Остаток товара в конкретном магазине.
   *
   * Скоуп currentShop сознательно не применяем: спрашиваем про явно заданный
   * магазин (магазин заказа), а не про магазин, привязанный к клиенту.
   */
  public function remainForShop(int $shopId): ?Remain
  {
    return $this->remains()->where('shop_id', $shopId)->first();
  }

  /**
   * Получает цену товара в определенном магазине
   *
   * Легаси: первая цена карточки (у пер-магазинного товара она одна).
   * Сетевой каталог: у товара N цен, поэтому ОБЯЗАТЕЛЕН контекст магазина —
   * $shopId передаёт вызывающий (магазин заказа), fallback на текущий
   * магазин клиента (ShopService).
   *
   * @return Price|null
   */
  public function getAuthPrice(?int $shopId = null): ?Price
  {
    if (NetworkCatalogMode::isEnabled()) {
      $shopId ??= ShopService::getCurrentShopId();

      return $shopId !== null ? $this->priceForShop($shopId) : null;
    }

    return $this->prices()->first();
  }

  public function categories(): ?BelongsToMany
  {
    return $this->belongsToMany(Category::class);
  }

  public function cookTime(): ?HasOne
  {
    return $this->hasOne(CookTime::class);
  }

  /**
   * Получает остаток товара в определенном магазине
   *
   * Легаси: остаток «текущего магазина» (магазин клиента, иначе магазин
   * по умолчанию). Сетевой каталог: остаток магазина заказа — товар глобален,
   * а остатки пер-магазин, поэтому магазин клиента здесь ни при чём.
   *
   * @return Remain|null
   */
  public function getAuthRemains(?int $shopId = null): ?Remain
  {
    if (NetworkCatalogMode::isEnabled() && $shopId !== null) {
      return $this->remainForShop($shopId);
    }

    return $this->remains()->currentShop()->first();
  }

  public function reviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
  {
    return $this->morphMany(Review::class,'reviewable');
  }


  public function supplements(): HasMany
  {
    return $this->hasMany(Supplement::class);
  }

  /**
   * Возвращает true, если остаток товара не блокирует заказ
   * (effective_stock_mode = ignore или fixed).
   */
  public function effectiveStockIgnored(): bool
  {
    return in_array($this->effective_stock_mode, ['ignore', 'fixed'], true);
  }

}
