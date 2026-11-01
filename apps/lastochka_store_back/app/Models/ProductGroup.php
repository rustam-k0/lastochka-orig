<?php

namespace App\Models;

use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductGroup extends Model
{
  use HasFactory, ModelTools, SoftDeletes;

  protected static function booted(): void
  {
    static::addGlobalScope('onlyActive', function ($builder) {
      $builder->where('status_id', Status::CODE_ACTIVE);
    });
  }

  public function products(): ?BelongsToMany
  {
    return $this->belongsToMany(Product::class);
  }

  public function getLimitedProducts(): mixed
  {
    return $this->getProducts()?->limit(10)->get();
  }

  /**
   * Формирование товаров из товарной группы
   * @return mixed
   */
  public function getProducts(): mixed
  {
    $products = null;
    //Товары закрепленные в админ панели
    if ($this->type_id == Type::PRODUCT_GROUP_PRODUCTS) {
      $products = $this->products();
    }

    //Отфильтрованные товары с помощью json поля filters
    if ($this->type_id == Type::PRODUCT_GROUP_FILTER) {
      $filters = json_decode($this->filters, true);
      //Если не удалось спарсить json, возвращаем запрос который всегда вернет 0 строк
      $products = $filters
        ? Product::filter($filters)
        : Product::whereNull('id');
    }

    return $products ?? null;
  }

  /**
   * Получить количество товаров в товарной группе
   */
  public function getProductsCount()
  {
    return $this->getProducts()?->count();
  }
}
