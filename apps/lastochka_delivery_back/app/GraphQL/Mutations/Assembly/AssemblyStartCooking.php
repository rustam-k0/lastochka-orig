<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\Assembly;
use App\Models\Status;
use App\Services\Pricing\OrderItemTierPricing;

final class AssemblyStartCooking
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $assembly = Assembly::with(['order', 'assemblyItems.orderItem'])->find($args['id']);

    // Та же защита что в AssemblyFinish — если клиент успел отменить заказ
    // между взятием в сборку и началом готовки, не даём стартовать.
    // См. LASTOCHKI-JAMI-9665.
    if ($assembly->order->status_id === Status::ORDER_CANCELLED) {
      throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
        "Заказ отменён клиентом, готовку начать нельзя"
      );
    }

    // MID-308: один экземпляр сервиса на мутацию
    $tierPricing = new OrderItemTierPricing();

    foreach ($assembly->assemblyItems as $assemblyItem) {

      $orderItem = $assemblyItem->orderItem;

      if ($assemblyItem->status_id == Status::PRODUCT_CANCELED) {
        continue;
      }

      $quantity = $assemblyItem->quantity ?? $orderItem->quantity;

      // MID-308: позиция со снапшотом лесенки считается по ступени для количества
      // строки; без снапшота прежняя формула
      $linePrice = $tierPricing->linePriceFor($orderItem, (float) $quantity);

      $assemblyItem->update([
        'status_id' => Status::PRODUCT_ASSEMBLED,
        'price' => $assemblyItem->price ?? $linePrice,
        'quantity' => $quantity,
      ]);

    }

    $assembly->update([
      'cooking_started_at' => now()
    ]);

    // Аналитика: веха «готовка началась» (LASTOCHKI-JAMI-11659)
    \App\Services\AnalyticsEvents::cookingStarted($assembly);

//    $assembly->order->update([
//      'status_id' => Status::ORDER_COOKING
//    ]);

    return [
      'code' => 200,
      'message' => 'OK'
    ];

  }
}
