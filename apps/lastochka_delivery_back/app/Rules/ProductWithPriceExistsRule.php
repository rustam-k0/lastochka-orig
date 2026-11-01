<?php

namespace App\Rules;

use App\Models\AssemblyItem;
use App\Models\Order;
use App\Models\Product;
use App\Support\NetworkCatalogMode;
use Illuminate\Contracts\Validation\Rule;

class ProductWithPriceExistsRule implements Rule
{

  /**
   * Create a new rule instance.
   *
   * @param int|null $shopId Магазин заказа/сборки. В режиме сетевого каталога цена
   *                         пер-магазин (prices.shop_id), поэтому проверять наличие
   *                         цены надо именно в магазине заказа, а не «хоть где-то».
   * @return void
   */
  public function __construct(private readonly ?int $shopId = null)
  {

  }

  /**
   * Determine if the validation rule passes.
   *
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {

    //Продается ли товар в данном магазине
    return Product::whereId($value)
      ->onlyActive()
      ->whereHas('price', function ($q) {
        if (NetworkCatalogMode::isEnabled() && $this->shopId !== null) {
          $q->where('prices.shop_id', $this->shopId);
        }
      })
      ->exists();

  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'Товар не найден';
  }
}
