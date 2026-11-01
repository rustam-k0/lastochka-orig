<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Status;
use App\Services\OrderCreatorService;
use App\Services\Pricing\OrderItemTierPricing;
use App\Support\NetworkCatalogMode;
use App\Support\StockUnits;
use Illuminate\Support\Facades\DB;

final class OrderShopUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $url = config('app.url') . '/backend-delivery/order/shop/change';
    $token = config('services.system.token');

    $data = [
      'token' => $token,
      'order_id' => $args['order_id'],
      'shop_id' => $args['shop_id'],
    ];

    $result = \Http::post($url, $data);

    if ($result->failed()) {
      return [
        'code' => 500,
        'message' => 'Server error'
      ];
    }

    if (NetworkCatalogMode::isEnabled()) {
      $this->recalculateItemPrices((int) $args['order_id'], (int) $args['shop_id']);
    }

    return [
      'code' => $result->json('code'),
      'message' => $result->json('message'),
    ];

  }

  /**
   * Сетевой каталог: цены принадлежат магазину, поэтому после смены магазина
   * заказа позиции нужно пересчитать по прайсу нового магазина (спека §8.3).
   *
   * Всё в одной транзакции: заказ с ценами двух магазинов хуже, чем заказ
   * со старыми ценами и записью в логе. Магазин к этому моменту уже сменён
   * delivery_back'ом (общая БД) — упасть наружу нельзя, иначе админка получит
   * 500 на успешно выполненной смене; поэтому исключение гасим в лог.
   */
  private function recalculateItemPrices(int $orderId, int $shopId): void
  {
    try {
      DB::transaction(function () use ($orderId, $shopId) {
        $order = Order::with('orderItems.product')->find($orderId);

        if (!$order) {
          return;
        }

        $creator = new OrderCreatorService();
        // MID-308: один экземпляр на пересчёт: флаг модуля (гейтит только свежие
        // снапшоты) читается один раз на все позиции заказа
        $tierPricing = new OrderItemTierPricing();
        $itemsTotal = 0.0;

        foreach ($order->orderItems as $item) {
          // Отменённые позиции не перецениваем и в сумму заказа не берём
          // (симметрично collectOrderItems в delivery_back).
          if ((int) $item->status_id === Status::ORDER_ITEM_CANCELED) {
            continue;
          }

          $unitPrice = $this->resolveUnitPrice($item, $shopId, $creator, $orderId);

          if ($unitPrice === null) {
            // Мягкий пропуск: старую цену НЕ затираем (иначе позиция уедет
            // в ноль/NULL), но в сумму заказа она всё равно входит.
            $itemsTotal += $this->lineTotal($item, (float) ($item->price_during_order ?? $item->price));

            continue;
          }

          // MID-308: лесенка принадлежит магазину, поэтому позиция получает свежий
          // снапшот лесенки НОВОГО магазина. $unitPrice (обычная цена нового
          // магазина, уже за порцию) становится base_price снапшота; цена позиции
          // берётся по ступени для её количества. Акций нового магазина курьерка
          // не знает: base_price_old = null, base_is_eligible = true.
          // freshSnapshot() гейтится флагом модуля: при выключенном он отдаёт null
          // и не читает таблицу price_tiers.
          //
          // Снапшот СТАРОГО магазина снимается с позиции в любом случае (пересчёт
          // по имеющемуся снапшоту от флага не зависит, чужая лесенка дала бы в
          // сборке неверную цену), но не значением NULL, а пустышкой с пустой
          // лесенкой: ключ price_tiers в исходящем событии получает только позиция
          // с непустой колонкой, и без пустышки витрина оставила бы у себя лесенку
          // старого магазина. Расчёты пустышку не видят:
          // PriceTierSnapshot::fromArray() на пустой лесенке возвращает null.
          // Позиция, у которой снапшота не было, остаётся с NULL, как до фичи.
          $tierAttributes = [];
          $hadSnapshot = $item->price_tiers !== null;
          $snapshot = $tierPricing->freshSnapshot($item->product, $shopId, $unitPrice, null, true);

          if ($snapshot !== null) {
            $quote = $snapshot->quote((float) $item->quantity);

            $tierAttributes['price_tiers'] = $snapshot->toArray();
            $tierAttributes['price_old'] = $quote->unitPriceOld;
            $tierAttributes['is_eligible'] = $quote->isEligible;
            $unitPrice = $quote->unitPrice;
          } elseif ($hadSnapshot) {
            $tierAttributes['price_tiers'] = OrderItemTierPricing::stubSnapshot($unitPrice);
            // MID-308 (фикс-волна финального ревью): ступень старого магазина
            // перестала применяться (в новом магазине нет лесенки либо модуль
            // выключен) — позиция вернулась на обычную цену нового магазина
            // (price_old уже обнулён ниже), пустышка несёт base_is_eligible:
            // true, поэтому и признак «участвует в процентном промокоде»
            // обязан вернуться в true. Иначе AssemblyFinish::percentDiscount()
            // (delivery_back) продолжал бы считать позицию вне базы скидки по
            // is_eligible=0 от снятой дешёвой ступени. Позиции, у которых
            // снапшота не было вовсе ($hadSnapshot === false), не трогаем:
            // до фичи OrderShopUpdate не менял is_eligible, это поведение
            // обязано сохраниться побайтно.
            $tierAttributes['is_eligible'] = true;
          }

          $item->update(array_merge([
            // ВНИМАНИЕ: order_items.price — цена ЗА ЕДИНИЦУ, не итог позиции.
            // Так пишет inbound-консьюмер ('price' => unit_price) и так читает
            // OrderEventTransformer (line_total = price * quantity). Записать
            // сюда итог позиции значит утроить line_total на бусе.
            'price_during_order' => $unitPrice,
            'price' => $unitPrice,
            // Зачёркнутая цена принадлежала акции старого магазина.
            'price_old' => null,
          ], $tierAttributes));

          // quantity не пересчитываем: порция задаётся карточкой товара
          // (info_weight), она общая для всех магазинов сети, поэтому число
          // порций позиции от смены магазина не меняется.
          $itemsTotal += $this->lineTotal($item, $unitPrice);
        }

        $this->recalculateTotals($order, $itemsTotal);
      });
    } catch (\Throwable $e) {
      \Log::warning('OrderShopUpdate: пересчёт цен позиций не удался', [
        'order_id' => $orderId,
        'new_shop_id' => $shopId,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Цена за единицу в новом магазине или null, если позицию пересчитать нельзя.
   * null — это «мягкий» пропуск: пишем предупреждение и оставляем старую цену.
   */
  private function resolveUnitPrice(OrderItem $item, int $shopId, OrderCreatorService $creator, int $orderId): ?float
  {
    $price = $item->product?->priceForShop($shopId);

    if (!$price) {
      \Log::warning('OrderShopUpdate: у позиции нет цены в новом магазине', [
        'order_id' => $orderId,
        'order_item_id' => $item->id,
        'product_id' => $item->product_id,
        'new_shop_id' => $shopId,
      ]);

      return null;
    }

    try {
      $unitPrice = $creator->getPriceDuringOrder($item->product, $price);
    } catch (\Throwable $e) {
      // Неизвестный тип единицы измерения — считать цену нечем.
      \Log::warning('OrderShopUpdate: не удалось определить цену за единицу', [
        'order_id' => $orderId,
        'order_item_id' => $item->id,
        'product_id' => $item->product_id,
        'new_shop_id' => $shopId,
        'error' => $e->getMessage(),
      ]);

      return null;
    }

    if ($unitPrice === null) {
      // Записав null, мы сделали бы позицию бесплатной.
      \Log::warning('OrderShopUpdate: у цены нового магазина не заполнена цена', [
        'order_id' => $orderId,
        'order_item_id' => $item->id,
        'product_id' => $item->product_id,
        'price_id' => $price->id,
        'new_shop_id' => $shopId,
      ]);

      return null;
    }

    return (float) $unitPrice;
  }

  /**
   * Итог позиции по цене за единицу — ровно той же формулой, что публикует
   * OrderEventTransformer, иначе amount в БД разойдётся с line_total на шине.
   */
  private function lineTotal(OrderItem $item, float $unitPrice): float
  {
    // Взвешенная позиция — цена порции × фактический вес / размер порции, с
    // копейками (round без точности округлял до рубля, литры не × 1000).
    if ($item->actual_weight !== null && $item->product) {
      return round($unitPrice * $item->actual_weight / StockUnits::portionInSmallest($item->product), 2);
    }

    return round($unitPrice * $item->quantity, 2);
  }

  /**
   * Денежные итоги заказа по новой сумме товаров. Формула — как в
   * AssemblyFinish::recalcOrderTotals (delivery_back): товары - скидка -
   * бонусы + доставка + сборка.
   *
   * Скидку, бонусы и стоимость доставки НЕ пересчитываем: смена магазина
   * не меняет состав заказа, а промокод/бонусы/порог бесплатной доставки —
   * отдельная механика (см. «Сомнения» в отчёте задачи 17).
   *
   * Сохраняем через $order->update(), а не whereId()->update(): нужен
   * Eloquent-event updated, чтобы order.updated с новыми ценами уехал в
   * шину (delivery опубликовал своё событие ДО пересчёта, со старыми).
   */
  private function recalculateTotals(Order $order, float $itemsTotal): void
  {
    $itemsTotal = round($itemsTotal, 2);

    $amount = round(max(0, $itemsTotal
      - (float) ($order->discount ?? 0)
      - (int) ($order->bonus_spent ?? 0)
      + (int) ($order->delivery_price ?? 0)
      + (int) ($order->assembly_cost ?? 0)), 2);

    $order->update([
      'items_subtotal' => $itemsTotal,
      'amount' => $amount,
    ]);

    if (!$order->wasChanged()) {
      // Итог совпал со старым — Eloquent счёл update «чистым» и НЕ выстрелил
      // updated, значит order.updated с новыми ценами позиций не уехал бы.
      // Совпадение реально: originalIsEquivalent сравнивает строковые формы
      // (is_numeric + strcmp), и 1220.55 против "1220.55" из PDO — равны;
      // расходятся только хвостовые нули (800.0 против "800.00").
      // Поднимаем ровно то событие, на котором висит ModelEventObserver
      // пакета rabbitmq-events (см. RabbitMQEventsServiceProvider).
      // touch() здесь не годится: updated_at посекундный, при повторе в ту же
      // секунду он тоже «чистый» — событие снова не ушло бы.
      event('eloquent.updated: ' . Order::class, $order);
    }
  }
}
