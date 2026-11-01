<?php

namespace App\Rules;

use App\Models\Product;
use App\Models\Shop;
use App\Support\NetworkCatalogMode;
use Illuminate\Contracts\Validation\Rule;

class ProductHasStockRule implements Rule
{

  /**
   * @param Shop|null $shop Магазин заказа/сборки. Если у магазина включена настройка
   *                        "Не учитывать остатки" (ignore_stock) — проверка наличия
   *                        пропускается, товар всегда считается доступным.
   */
  public function __construct(private ?Shop $shop = null)
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
    // Магазин не учитывает остатки ИЛИ сам товар в режиме ignore/fixed
    // (effective_stock_mode) — пропускаем проверку наличия при сборке.
    $product = Product::find($value);
    if (($this->shop && $this->shop->ignore_stock) || ($product && $product->effectiveStockIgnored())) {
      return true;
    }

    $shopId = $this->shop?->id;

    return Product::whereId($value)
      ->whereHas('remain', function ($query) use ($shopId) {
        // Сетевой каталог: остаток пер-магазин, спрашиваем именно магазин заказа —
        // глобальный скоуп onlyAuth (магазины авторизованного юзера) тут не при чём.
        if (NetworkCatalogMode::isEnabled() && $shopId !== null) {
          $query->withoutGlobalScopes()->where('remains.shop_id', $shopId)->where('quantity', '>', 0);

          return;
        }

        $query->where('quantity', '>', 0);
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
    return 'Товар отсутствует на складе';
  }
}
