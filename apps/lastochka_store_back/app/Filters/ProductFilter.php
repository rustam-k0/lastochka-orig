<?php

namespace App\Filters;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;

class ProductFilter extends QueryFilter
{
  public function category_link($value): void
  {
    $this->builder->whereHas('categories',function ($query)use ($value){
      $query->whereLink($value);
    });
  }

  public function category_id($value): void
  {
    $this->builder->whereRelation('categories','categories.id',$value);
  }

  public function shop_id($value): void
  {
    $this->builder->where('shop_id',$value);
  }

  public function brands($value): void
  {
    $this->builder->whereIn('brand_id',$value);
  }
  public function countries($value): void
  {
    $this->builder->whereIn('country_id',$value);
  }

  public function price_min($value): void
  {
    $this->builder->whereHas('price',function ($query)use ($value){
      $query->where('value','>=',$value);
    });
  }

  public function price_max($value): void
  {
    $this->builder->whereHas('price',function ($query)use ($value){
      $query->where('value','<=',$value);
    });
  }

  public function product_group_id($value): void
  {
    $group = ProductGroup::find($value);

    $products_id = $group?->getProducts()?->pluck((new Product())->getTable().'.id')->toArray();

    //Если в продуктовой группе нет товаров, то выполняем запрос, который возвращает 0 строк
    if (!$products_id) {
      $this->builder->whereNull('id');
      return;
    }

    $this->builder->whereIn((new Product())->getTable().'.id',$products_id);

  }

  /**
   * Поиск похожих товаров
   */
  public function similar_id($value): void
  {
    $product = Product::with('categories')->find($value);

    $categories_id = $product?->categories?->pluck('id')->toArray();

    // Если у товара нет категорий, то выполняем запрос, который возвращает 0 строк
    if (!$categories_id ||!count($categories_id)) {
      return;
    }

    $this->builder->whereHas('categories',function ($query)use ($categories_id){
      $query->whereIn((new Category())->getTable().'.id',$categories_id);
    })
      ->where((new Product())->getTable().'.id','!=',$value);

  }

  public function is_cooking($value)
  {
    if (!$value) {
      return;
    }

    $this->builder->whereRelation('categories','is_cooking',true);
  }

  public function discount($value)
  {
    if (!$value) {
      return;
    }

    $this->builder->whereRelation('price','price_without_discount','!=',null);
  }

  public function barcode($value)
  {
    $this->builder->where(function ($query)use ($value){
      $query->whereBarcode($value)->orWhere('barcode',substr($value, 0, 7));
    });
  }

}
