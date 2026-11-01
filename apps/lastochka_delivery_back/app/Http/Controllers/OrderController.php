<?php

namespace App\Http\Controllers;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Scopes\NotFinished;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryStart;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\GraphQL\Mutations\Order\OrderPrepare;

use App\Models\Assembly;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Status;
use App\Models\Type;
use App\Services\FcmNotificationService;
use App\Services\Pricing\OrderDeliveryPriceResolver;
use Illuminate\Http\Request;

class OrderController extends Controller
{

  protected function checkCredentials($token): bool
  {
    return $token == config('services.system.token');
  }

  /**
   * Единый ответ «сущность не найдена» для внутреннего REST.
   * Без него отсутствующий/пустой order_id улетал в мутацию со строгой
   * сигнатурой (Order $order) и давал TypeError 500 (GlitchTip DELIVERY_BACK-6).
   */
  protected function notFound(string $message)
  {
    return response()->json(['message' => $message], 404);
  }

  public function prepare(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    $order = Order::find($request->order_id);

    if (!$order) {
      return $this->notFound('Заказ не найден');
    }

    if (!$request->collector_id) {
      return response()->json(['message' => 'Не передан сборщик'], 422);
    }

    OrderPrepare::orderPrepare($order, (int) $request->collector_id, $request->admin_id);

    return response()->json(['message' => 'OK']);
  }

  public function assembly(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    $assembly = Assembly::with(['assemblyItems.orderItem'])
      ->whereOrderId($request->order_id)
      ->first();

      \Log::info('Assembly found', [
        'assembly_id' => $assembly?->id,
        'order_id' => $request->order_id
      ]);
    
    if ($assembly) {
      // MID-308: один экземпляр сервиса на запрос
      $tierPricing = new \App\Services\Pricing\OrderItemTierPricing();

      // Обновляем все assemblyItems для этой сборки
      $assembly->assemblyItems()->with('orderItem')->get()->each(function ($assemblyItem) use ($tierPricing) {
        // Позицию уже подтвердил сборщик: её количество/вес/цена — факт сборки
        // (весовая — 528 г за 313.63), заказанными порциями не перетираем.
        // Так же поступает AssemblyStartCooking.
        if ((int) $assemblyItem->status_id === Status::PRODUCT_ASSEMBLED) {
          return;
        }

        if ($assemblyItem->orderItem) {
          $orderItem = $assemblyItem->orderItem;
          $quantity = $orderItem->quantity;

          // MID-308: позиция со снапшотом лесенки считается по ступени для её
          // количества; без снапшота прежняя формула
          $linePrice = $tierPricing->linePriceFor($orderItem, (float) $quantity);

          $assemblyItem->update([
            'status_id' => Status::PRODUCT_ASSEMBLED,
            'assembled_at' => now(),
            'quantity' => $quantity,
            'price' => $linePrice,
          ]);
        }
      });
      
      AssemblyFinish::assemblyFinish($assembly);
    } else {
      $order = Order::find($request->order_id);

      if (!$order) {
        return $this->notFound('Заказ не найден');
      }

      $order->update(['status_id' => Status::ORDER_ASSEMBLED]);
    }

    return response()->json(['message' => 'OK']);
  }

  public function assemblyFinish(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    // Без скоупа NotFinished: завершённую сборку надо увидеть, чтобы ответить
    // на повтор идемпотентно, а не «Сборка заказа не найдена». Берём последнюю:
    // после отмены сборки на заказе может остаться несколько записей.
    $assembly = Assembly::withoutGlobalScope(NotFinished::class)
      ->with(['assemblyItems.orderItem'])
      ->whereOrderId($request->order_id)
      ->orderByDesc('id')
      ->first();

    if (!$assembly || !$assembly->order) {
      return $this->notFound('Сборка заказа не найдена');
    }

    if ($assembly->finished_at !== null) {
      return response()->json(['message' => 'OK']);
    }

    // Тот же лок, что и в GraphQL-мутации: админка («Следующий шаг») и мобилка
    // сборщика могут завершать одну сборку одновременно, а списание оплаты
    // не идемпотентно на дистанции между проверкой paid и capture.
    try {
      $result = AssemblyFinish::lockFor((int) $assembly->id)
        ->block(AssemblyFinish::LOCK_WAIT_SECONDS, function () use ($request, $assembly) {
          $assembly->refresh();

          if ($assembly->finished_at !== null) {
            return ['code' => 200, 'message' => 'OK'];
          }

          // Модуль collector_payment: этот REST-эндпоинт дёргает OrderGoToNextStep
          // из админки в обход GraphQL-мутации AssemblyFinish::__invoke — списание
          // в контексте завершения сборки дублируется здесь, иначе онлайн-заказ
          // из админки было бы невозможно завершить. Провал доплаты → 402 с
          // текстом для оператора, заказ остаётся на сборке.
          $order = $assembly->order;
          if (Order::isCollectorPaymentModuleEnabled()
            && (int) $order->payment_type_id === Type::PAYMENT_ONLINE
            && !$order->paid
          ) {
            $charge = \App\GraphQL\Mutations\Assembly\AssemblyChargePayment::chargeForAssembly(
              Assembly::withoutGlobalScope(NotFinished::class)
                ->with(['order', 'assemblyItems.orderItem.supplements'])
                ->whereKey($assembly->id)
                ->first()
            );

            if ($charge['code'] !== 200) {
              return $charge;
            }
          }

          AssemblyFinish::finishOrder([], $assembly);

          return ['code' => 200, 'message' => 'OK'];
        });
    } catch (LockTimeoutException $e) {
      return response()->json(
        ['message' => 'Завершение сборки уже выполняется, повторите через несколько секунд'],
        409
      );
    }

    if ($result['code'] !== 200) {
      return response()->json(['message' => $result['message']], $result['code']);
    }

    return response()->json(['message' => 'OK']);
  }

  public function delivery(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    // Модуль collector_payment: назначение курьера (в т.ч. админом из АП)
    // на неоплаченный онлайн-заказ запрещено — гейт дублирует GraphQL
    // DeliveryAssignCouriersValidator для REST-пути.
    $order = Order::find($request->order_id);
    if ($order
      && Order::isCollectorPaymentModuleEnabled()
      && (int) $order->payment_type_id === Type::PAYMENT_ONLINE
      && !$order->paid
    ) {
      return response()->json(['message' => 'Заказ не оплачен: назначение курьера недоступно'], 422);
    }

    $delivery = Delivery::whereUserId($request->courier_id)
      ->first();

    $data = DeliveryTakeOrder::takeOrder($request->courier_id, $request->order_id, $request->admin_id);

    // Флаг start_delivery — явное намерение провести доставку (кнопка
    // «В доставке» в АП, шлёт ТОЛЬКО purchase_back OrderGoToNextStep):
    // рейс стартует даже при включённом courier_assignment (MID-242).
    // Назначения БЕЗ флага (сборщик DeliveryAssignCouriers, доп. курьер
    // OrderCourierAdd) при модуле рейс не стартуют — старт за курьером.
    $forceStart = $request->boolean('start_delivery');

    //Если курьер уже в процессе доставки, то добавляем ему еще заказ в последний приоритет
    if ($delivery && $data && $delivery->started_at != null) {
      $last_order = $delivery->deliveryOrders()->orderByDesc('priority')->first();
      DeliveryOrder::whereId($data['delivery_order']->id)->update([
        'priority' => $last_order->priority + 1
      ]);

    } elseif ($data && (!Order::isCourierAssignmentModuleEnabled() || $forceStart)) {
      DeliveryStart::deliveryStart($data['delivery'], $data['delivery_order']);
    } elseif (!$data && $forceStart && $delivery && $delivery->started_at === null) {
      // Курьер уже был назначен ранее (сборщиком или прошлым кликом админа) —
      // «В доставке» стартует его ожидающий рейс. Рейс в пути не трогаем:
      // заказ уже стоит в очереди этого курьера.
      $assignment = $delivery->deliveryOrders()
        ->whereOrderId($request->order_id)
        ->where('status_id', Status::DELIVERY_WAIT_TO_START)
        ->first();

      if ($assignment) {
        DeliveryStart::deliveryStart($delivery, $assignment);
      }
    }

    if ($data) {
      app(FcmNotificationService::class)->sendToUser(
        $request->courier_id,
        'Новый заказ на доставку',
        'Вам назначен заказ на доставку. Номер: ' . Order::labelById((int) $request->order_id),
        ['type' => 'order_assigned', 'order_id' => (string) $request->order_id]
      );
    }

    return response()->json(['message' => 'OK']);
  }

  public function finish(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    $delivery = Delivery::whereRelation('deliveryOrders', 'order_id', $request->order_id)
      ->first();

    if (!$delivery) {
      return $this->notFound('Активная доставка по заказу не найдена');
    }

    $delivery_order = $delivery->deliveryOrders()->where('order_id', $request->order_id)->first();

    if (!$delivery_order) {
      return $this->notFound('Назначение заказа на доставку не найдено');
    }

    DeliveryFinish::deliveryFinish($delivery_order, $delivery);

    return response()->json(['message' => 'OK']);
  }

  public function shopChange(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    $order_id = $request->order_id;
    $shop_id = $request->shop_id;
    if (!$order_id || !$shop_id) {
      return response()->json(null, 500);
    }

    $order = Order::find($order_id);

    if (!$order) {
      return $this->notFound('Заказ не найден');
    }

    // MID-280: снимок условий доставки по зоне нового магазина; сама доставка
    // заказа при смене магазина не пересчитывается
    $deliverySnapshot = (new OrderDeliveryPriceResolver())->snapshotForShop($order, (int) $shop_id);

    $order->update(array_merge([
      'status_id' => Status::ORDER_CREATED,
      'shop_id' => $shop_id
    ], $deliverySnapshot !== null ? ['delivery_price_snapshot' => $deliverySnapshot] : []));

    Assembly::whereOrderId($order_id)->delete();

    // Аналитика: магазин сменился — пересчитать зону доставки (orders.zone_id)
    // Аналитика: зона не должна ронять смену магазина — ошибки только в лог
    try {
      (new \App\Services\OrderDeliveryZoneSync())->sync($order->fresh());
    } catch (\Throwable $e) {
      \Log::warning('OrderDeliveryZoneSync failed', [
        'order_id' => $order->id,
        'error' => $e->getMessage(),
      ]);
    }

    return response()->json(['message' => 'OK', 'code' => 200]);
  }

  public function cancel(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }
    $order_id = $request->order_id;

    $order = Order::find($order_id);

    if (!$order) {
      return $this->notFound('Заказ не найден');
    }

    $response = response()->json(['message' => 'OK', 'code' => 200]);

    // Закрываем открытую сборку по факту её наличия, а не по статусу заказа.
    // При отмене split-заказа из админки (purchase_back/OrderCancel) статус
    // подзаказа уже переведён в CANCELLED до вызова этого эндпоинта, поэтому
    // проверка на ORDER_PREPARING пропускала сборку — она оставалась открытой
    // (finished_at IS NULL) и навсегда занимала лимит сборщика MAX_ORDERS_COUNT.
    // Global scope NotFinished оставляет в выборке только незавершённые сборки.
    $openAssemblies = Assembly::whereOrderId($request->order_id)->get();
    if ($openAssemblies->isNotEmpty()) {
      foreach ($openAssemblies as $assembly) {
        $assembly->update(['finished_at' => now()]);
        $assembly->delete();
      }

      // Если это подзаказ — проверяем статус родителя
      $order->refresh();
      if ($order->parent_order_id !== null) {
        AssemblyFinish::syncParentStatus($order);
      }

      return $response;
    }

    // Если заказ уже собран или в доставке — возвращаем остатки тех товаров,
    // что были списаны при сборке (AssemblyFinish::collectOrderItems).
    // Assembly после finishOrder помечена finished_at, поэтому ищем без global scope NotFinished.
    if (in_array($order->status_id, [Status::ORDER_ASSEMBLED, Status::ORDER_IN_DELIVERY], true)) {
      $assembly = Assembly::withoutGlobalScopes()
        ->whereOrderId($order_id)
        ->first();

      if ($assembly) {
        AssemblyFinish::restoreAssembledStock($assembly);
      }
    }

    // Закрываем ВСЕ открытые назначения курьеров НЕЗАВИСИМО от статуса заказа.
    // Раньше блок выполнялся только при ORDER_IN_DELIVERY и пропускал:
    // (1) отмену из админки — purchase_back/OrderCancel переводит заказ в
    // CANCELLED ДО вызова этого эндпоинта (см. комментарий про сборки выше);
    // (2) флоу courier_assignment — назначение (WAIT_TO_START) существует уже
    // при статусе «Собран», автостарта рейса нет. Незакрытое назначение
    // держит курьера «На заказе» (User::isBusy) навсегда (MID-243).
    // closeOtherAssignments закрывает назначения как DELIVERY_CANCELED и
    // продвигает маршруты затронутых курьеров — зеркало OrderCancelByCourier.
    $startedCourierIds = [];
    $closedCourierIds = \App\Support\JointDelivery::closeOtherAssignments($order, null, true, $startedCourierIds);

    // Соисполнителям следующего совместного заказа в продвинутых маршрутах
    if ($startedCourierIds !== []) {
      \App\Support\JointDelivery::notifyStarted($startedCourierIds, (int) $order->id);
    }

    if ($closedCourierIds !== []) {
      // Счётчик курьеров после отмены: отменённые назначения не считаются
      \App\Support\JointDelivery::recalcCouriersCount($order);

      // Push закрытым курьерам — как в OrderCancelByCourier
      foreach ($closedCourierIds as $courierUserId) {
        app(FcmNotificationService::class)->sendToUser(
          $courierUserId,
          'Заказ закрыт',
          "Заказ {$order->label} отменён",
          ['type' => 'order_closed_by_system', 'order_id' => (string) $order->id]
        );
      }
    }

    // Если это подзаказ — проверяем статус родителя
    $order->refresh();
    if ($order->parent_order_id !== null) {
      AssemblyFinish::syncParentStatus($order);
    }

    return $response;
  }

  public function productAdd(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    $order_item = OrderItem::find($request->order_item_id);

    if (!$order_item || !$order_item->order) {
      return $this->notFound('Позиция заказа не найдена');
    }

    $order = $order_item->order;
    $response = response()->json('OK');

    if ($order->status_id != Status::ORDER_PREPARING) {
      return $response;
    }

    $assembly = $order->assembly;

    if (!$assembly) {
      return $this->notFound('Сборка заказа не найдена');
    }

    $assembly->assemblyItems()->create([
      'order_item_id' => $order_item->id,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS
    ]);

    return $response;
  }
}
