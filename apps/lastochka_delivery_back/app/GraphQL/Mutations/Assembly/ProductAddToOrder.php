<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;

final class ProductAddToOrder
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $assembly = Assembly::with('order')->find($args['assembly_id']);
    $order = $assembly->order;
    $product = Product::find($args['product_id']);
    $shop_id = $order->shop_id;
    $price = $product->prices()->where('shop_id', $shop_id)->first();
    $quantity = $args['quantity'];

    $price_during_order = $price->value;
    $full_price = $price->value * $quantity;

    // MID-308: один экземпляр сервиса на мутацию. Сам расчёт свежего снапшота
    // выполняется только в ветке создания НОВОЙ позиции (см. ниже): при слиянии
    // с существующей добавкой снапшот магазина не нужен, ступень пересчитывается
    // по снапшоту, который уже лежит в этой позиции.
    $tier_pricing = new OrderItemTierPricing();

    \DB::transaction(function () use ($order, $product, $quantity, $full_price, $price_during_order, $assembly, $shop_id, $tier_pricing) {
      // Тот же товар уже добавлен в этой сборке и ещё не собран — увеличиваем
      // количество, а не плодим строки. Прод-заказ 208-1: сборщик трижды
      // добавил товар замены и получил три позиции по 1 шт с раздутым amount,
      // а syncItemsToParent матчит строки один к одному (заказы 386, 370).
      $existing = $this->findMergeTarget($order, $assembly, $product);

      if ($existing) {
        $this->mergeIntoExistingItem($order, $existing, $quantity, $tier_pricing);
      } else {
        // MID-308: новая позиция сборщика получает свежий снапшот из живой лесенки
        // магазина заказа; цена за единицу берётся по ступени для добавленного
        // количества. base_price_old = null, base_is_eligible = true: курьерка не
        // знает об акциях на товар. Лесенки нет или модуль выключен (флаг гейтит
        // именно построение новых снапшотов): $tier_attributes пуст, позиция
        // создаётся прежней формулой.
        $tier_snapshot = $tier_pricing->freshSnapshot($product, $shop_id, (float) $price_during_order, null, true);
        $tier_attributes = [];

        if ($tier_snapshot !== null) {
          $tier_quote = $tier_snapshot->quote((float) $quantity);

          $price_during_order = $tier_quote->unitPrice;
          $full_price = round($tier_quote->unitPrice * $quantity, 2);
          $tier_attributes = [
            'price_tiers' => $tier_snapshot->toArray(),
            'price_old' => $tier_quote->unitPriceOld,
            'is_eligible' => $tier_quote->isEligible,
          ];
        }

        // Увеличить сумму заказа
        $order->increment('amount', $full_price);

        // Создать позицию в заказе
        $item = $order->orderItems()->create(array_merge([
          'product_id' => $product->id,
          'quantity' => $quantity,
          'price' => $full_price,
          'status_id' => Status::ORDER_ITEM_WAITING,
          'price_during_order' => $price_during_order,
          'is_supplemented' => true,
        ], $tier_attributes));

        // Создать позицию в сборке
        $assembly->assemblyItems()->create([
          'order_item_id' => $item->id,
          'status_id' => Status::PRODUCT_WAIT,
          'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
        ]);
      }

      // Остаток здесь не трогаем: добавленная позиция получает assembly_item
      // и списывается при AssemblyFinish вместе с остальными собранными — по
      // фактическому весу или порциям в единице остатка. Списание ещё и здесь
      // давало двойной расход, который не возвращался при отмене позиции.
    });

    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  /**
   * Позиция, в которую сливается повторное добавление того же товара.
   *
   * Сливаем только с добавкой сборщика (is_supplemented) из ЭТОЙ сборки,
   * которая ещё ждёт сборки. Позицию, которую заказал клиент, трогать нельзя:
   * её quantity — это заказанное, на нём стоят сверка «заказано/собрано»,
   * возвраты и выгрузка в 1С. Уже собранная и отменённая добавки тоже
   * исключены: у первой количество зафиксировано подтверждением, у второй
   * позиция закрыта причиной отмены.
   *
   * Флаг is_supplemented перегружен: admin_back тем же полем помечает позиции
   * витрины, у которых есть добавки (OrderEventTransformer:
   * `is_supplemented => !empty($itemData['supplements'])`). Поэтому позиции с
   * добавками из мержа исключены — иначе кофе с сиропом получил бы чужое
   * количество, а добавки считаются по количеству позиции.
   *
   * lockForUpdate: двойной тап «Добавить товар» на мобилке шлёт две мутации
   * подряд, без блокировки обе увидели бы «позиции ещё нет» и создали дубль.
   */
  private function findMergeTarget(Order $order, Assembly $assembly, Product $product): ?OrderItem
  {
    return OrderItem::query()
      ->where('order_id', $order->id)
      ->where('product_id', $product->id)
      ->where('is_supplemented', true)
      ->where('status_id', Status::ORDER_ITEM_WAITING)
      ->whereNull('origin_product_id')
      ->whereDoesntHave('supplements', fn($query) => $query->withTrashed())
      ->whereHas('assemblyItem', function ($query) use ($assembly) {
        $query->where('assembly_id', $assembly->id)
          ->where('status_id', Status::PRODUCT_WAIT);
      })
      ->orderBy('id')
      ->lockForUpdate()
      ->first();
  }

  /**
   * Долить количество в существующую добавку.
   *
   * Цена позиции считается от её price_during_order, чтобы order_item остался
   * консистентным с пересчётом в ProductConfirm (price = цена единицы × кол-во),
   * и ровно на эту же дельту растёт amount заказа.
   *
   * Позиция со снапшотом лесенки (MID-308) считается по ступени нового
   * суммарного количества, см. тело метода.
   */
  private function mergeIntoExistingItem(Order $order, OrderItem $item, int $quantity, OrderItemTierPricing $tier_pricing): void
  {
    // MID-308: у добавки со снапшотом лесенки ступень выбирается по НОВОМУ
    // суммарному количеству и действует на все единицы позиции, поэтому amount
    // заказа меняется на разницу итогов строки, а не на «цена × добавленное».
    // Разница может быть отрицательной (4 × 249 = 996 → 5 × 199 = 995).
    // price добавки сборщика хранит итог строки с момента создания.
    $merged_quantity = (int) $item->quantity + $quantity;
    $tier_quote = $tier_pricing->quoteFor($item, (float) $merged_quantity);

    if ($tier_quote !== null) {
      $old_line_total = (float) $item->price;
      $new_line_total = round($tier_quote->unitPrice * $merged_quantity, 2);

      $item->update([
        'quantity' => $merged_quantity,
        'price' => $new_line_total,
        'price_during_order' => $tier_quote->unitPrice,
        'price_old' => $tier_quote->unitPriceOld,
        'is_eligible' => $tier_quote->isEligible,
      ]);

      $order->increment('amount', round($new_line_total - $old_line_total, 2));

      return;
    }

    $unit_price = (float) $item->price_during_order;
    $new_quantity = (int) $item->quantity + $quantity;

    $item->update([
      'quantity' => $new_quantity,
      'price' => $unit_price * $new_quantity,
    ]);

    $order->increment('amount', $unit_price * $quantity);
  }
}
