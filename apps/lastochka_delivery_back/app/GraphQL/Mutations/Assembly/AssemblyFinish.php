<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Events\ProductStockChanged;
use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\Scopes\NotFinished;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\Pricing\OrderDeliveryPriceResolver;
use App\Services\Pricing\OrderItemTierPricing;
use App\Support\NetworkCatalogMode;
use App\Support\StockUnits;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class AssemblyFinish
{
  /** Тип промокода: фиксированный номинал в рублях. */
  private const PROMOCODE_FIXED = 'fixed';

  /** Тип промокода: процент от суммы eligible-позиций. */
  private const PROMOCODE_PERCENT = 'percent';

  /** Сколько ждать освобождения блокировки, прежде чем ответить «уже выполняется». */
  public const LOCK_WAIT_SECONDS = 15;

  /** Страховочный TTL блокировки: списание ходит в ЮKassa, ответ занимает секунды. */
  public const LOCK_TTL_SECONDS = 60;

  /**
   * Блокировка завершения конкретной сборки: одна точка для GraphQL-мутации и
   * REST-эндпоинта админки, чтобы ключ и TTL не разъезжались.
   *
   * Стор берётся из config('cache.lock_store') и намеренно отделён от основного
   * кэша. В файловом кэше блокировка ненадёжна: каталоги внутри
   * storage/framework/cache/data создают и php-fpm, и CI-раннер (прогрев
   * lighthouse:cache), и каталог, созданный раннером, для www-data закрыт —
   * блокировка падала с «Unable to create lockable file» и роняла завершение
   * сборки целиком (прод 11.09.2026, сборка 255 заказа 223-1).
   */
  public static function lockFor(int $assemblyId): Lock
  {
    return Cache::store(config('cache.lock_store'))
      ->lock('assembly-finish:' . $assemblyId, self::LOCK_TTL_SECONDS);
  }

  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    // Завершение сборки сериализуется по сборке: мобилка отправляет запрос
    // повторно (двойной свайп, обрыв связи), а между charge и finishOrder
    // проходят секунды похода в ЮKassa. Два параллельных прохода на проде
    // дали два capture одного холда (заказ 195-2, 2026-09-10).
    try {
      return self::lockFor((int) $args['id'])
        ->block(self::LOCK_WAIT_SECONDS, fn () => self::performFinish($args));
    } catch (LockTimeoutException $e) {
      return [
        'code' => 409,
        'message' => 'Завершение сборки уже выполняется, повторите через несколько секунд',
      ];
    }
  }

  /**
   * @param array{} $args
   * @return array{code: int, message: string}
   */
  private static function performFinish(array $args): array
  {
    // Без скоупа NotFinished: завершённая сборка обязана быть видимой, иначе
    // повтор отвечает «Сборка не найдена», а мобилка показывает это как
    // «Увы! Платеж не прошел» — при уже прошедшей оплате и собранном заказе.
    $assembly = Assembly::withoutGlobalScope(NotFinished::class)
      ->with(['order', 'assemblyItems.orderItem.supplements'])
      ->find($args['id']);

    if (!$assembly) {
      throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException('Сборка не найдена');
    }

    // Идемпотентность: сборка уже завершена — повтор ничего не делает и
    // отвечает успехом. Ни второго capture, ни второго пакета, ни повторного
    // списания остатка (collectOrderItems не идемпотентен).
    if ($assembly->finished_at !== null) {
      return ['code' => 200, 'message' => 'OK'];
    }

    // Защита от race condition: между тем как сборщик начал сборку и моментом
    // её завершения клиент мог отменить заказ через storefront. Если статус
    // заказа уже CANCELLED — сборку завершать нельзя, иначе finishOrder
    // перезатрёт CANCELLED обратно в ASSEMBLED. См. LASTOCHKI-JAMI-9665.
    if ($assembly->order->status_id === Status::ORDER_CANCELLED) {
      throw new \Nuwave\Lighthouse\Exceptions\AuthorizationException(
        "Заказ отменён клиентом, завершить сборку нельзя"
      );
    }

    // Модуль collector_payment: списание оплаты происходит в контексте
    // завершения сборки (решение ПМ 2026-07-24) — отдельная кнопка сборщику
    // не нужна. chargeForAssembly пересчитывает итог БЕЗ списания стока и
    // синхронно списывает оплату (доплата разницы первой, затем capture
    // холда). Провал (402/500) возвращается сборщику как ответ этой же
    // мутации: заказ остаётся на сборке, сток не тронут, повтор = снова
    // «Завершить сборку».
    if (Order::isCollectorPaymentModuleEnabled()
      && (int) $assembly->order->payment_type_id === Type::PAYMENT_ONLINE
      && !$assembly->order->paid
    ) {
      $charge = AssemblyChargePayment::chargeForAssembly($assembly);

      if ($charge['code'] !== 200) {
        return $charge;
      }
    }

    self::assemblyFinish($assembly);

    self::finishOrder($args['packages'], $assembly);

    return [
      'code' => 200,
      'message' => 'OK'
    ];

  }

  public static function finishOrder(?array $packages, Assembly $assembly): void
  {
    //Сохраняем количество пакетов
    foreach ($packages as $package) {
      $package['order_id'] = $assembly->order_id;
      $assembly->packages()->create($package);
    }

    //Сборка завершена
    $assembly->update([
      'finished_at' => now()
    ]);

    $order = $assembly->order;

    // Defense-in-depth: даже если __invoke не отсеял CANCELLED (например, статус
    // изменился между __invoke и finishOrder), не перезаписываем терминальный
    // CANCELLED обратно на ASSEMBLED. Условный SELECT + refresh, чтобы Eloquent
    // не сработал на устаревшем in-memory state. См. LASTOCHKI-JAMI-9665.
    $order->refresh();
    if ($order->status_id === Status::ORDER_CANCELLED) {
      \Log::warning('AssemblyFinish skipped status update — order is CANCELLED', [
        'order_id' => $order->id,
        'assembly_id' => $assembly->id,
      ]);
      return;
    }

    $oldStatusId = $order->status_id;

    //Заказ собран
    $order->update(array_merge(
      ['status_id' => Status::ORDER_ASSEMBLED],
      $order->pickup_ready_at ? [] : ['pickup_ready_at' => now()]
    ));

    $collector = User::find($assembly->user_id);
    \App\Models\OrderHistory::log(
      $order->id,
      \App\Models\OrderHistory::ACTION_STATUS_CHANGED,
      \App\Models\OrderHistory::ACTOR_STAFF,
      $assembly->user_id,
      $collector ? trim("{$collector->surname} {$collector->name}") : null,
      self::statusLabel($oldStatusId),
      self::statusLabel(Status::ORDER_ASSEMBLED)
    );

    // Аналитика: сборка завершена, fill rate по факту (LASTOCHKI-JAMI-11659)
    \App\Services\AnalyticsEvents::pickingFinished($assembly);

    // Синхронизация статуса родителя при завершении сборки подзаказа
    self::syncParentStatus($order);

    // Пуш курьерам магазина: заказ готов к доставке.
    self::notifyCouriersOrderReady($order);
  }

  /**
   * Отправляет push-уведомление всем курьерам магазина, когда заказ
   * полностью собран и готов к выдаче на доставку. Для split-заказов
   * пуш срабатывает только когда все child sub-orders завершены и
   * родитель переведён в ORDER_ASSEMBLED.
   */
  private static function notifyCouriersOrderReady(Order $order): void
  {
    if ($order->parent_order_id !== null) {
      $order = Order::find($order->parent_order_id);
      if ($order === null) {
        return;
      }
    }

    if ($order->status_id !== Status::ORDER_ASSEMBLED) {
      return;
    }

    // Модуль courier_assignment: курьеры не видят общий список — общий пуш
    // не имеет смысла, назначенному курьеру пуш шлёт DeliveryAssignCouriers.
    if (Order::isCourierAssignmentModuleEnabled()) {
      return;
    }

    $userIds = User::role(Role::COURIER)
      ->whereHas('shift', fn($q) => $q->whereShopId($order->shop_id))
      ->pluck('id')
      ->toArray();

    if (empty($userIds)) {
      return;
    }

    app(FcmNotificationService::class)->sendToUsers(
      $userIds,
      'Новый заказ готов к доставке',
      "Номер: {$order->label}",
      ['type' => 'order_ready_for_delivery', 'order_id' => (string) $order->id]
    );
  }

  private static function statusLabel(int $statusId): string
  {
    return match ($statusId) {
      Status::ORDER_CREATED => 'Создан',
      Status::ORDER_PREPARING => 'Заказ на сборке',
      Status::ORDER_ASSEMBLED => 'Собрано',
      Status::ORDER_IN_DELIVERY => 'В доставке',
      Status::ORDER_FINISHED => 'Доставлен',
      Status::ORDER_CANCELLED => 'Отменён',
      default => "Статус {$statusId}",
    };
  }

  /**
   * Завершить сборку.
   *
   * $adjustStock: списывать ли складские остатки (collectOrderItems). По умолчанию
   * true — историческое поведение (мутация AssemblyFinish, REST OrderController).
   * Списание остатков относительное и НЕ идемпотентное, поэтому пересчёт суммы
   * без завершения сборки (AssemblyChargePayment «Списать оплату») обязан звать с
   * false: остаток должен списаться ровно один раз — при финальном завершении.
   */
  public static function assemblyFinish(Assembly $assembly, bool $adjustStock = true)
  {
    \Log::info('Starting assembly finish', [
      'assembly_id' => $assembly->id,
      'order_id' => $assembly->order_id
    ]);


    return \DB::transaction(function () use ($assembly, $adjustStock) {

      $order = $assembly->order;

      // Считаем исходную сумму товаров ДО сборки (до скидки), чтобы определить коэффициент скидки
      $order->load('orderItems.supplements');
      $originalSubtotal = 0;
      foreach ($order->orderItems as $item) {
        $supplementsTotal = $item->supplements->sum('amount');
        $originalSubtotal += ($item->price + $supplementsTotal) * $item->quantity;
      }

      // Пересчитываем позиции на основе результатов сборки (отмены, замены, изменения кол-ва)
      $newItemsTotal = self::collectOrderItems($assembly, $adjustStock);

      // Суммарный вес заказа фиксируется по финализированному составу
      // (после collectOrderItems: замены применены, отменённые помечены).
      // Запрашиваем order_items напрямую: relation Order::orderItems()
      // фильтрует отменённые позиции для роли COURIER. LASTOCHKI-JAMI-11656.
      $weightData = self::calculateOrderWeight(
        OrderItem::with('product')->where('order_id', $order->id)->get()
      );

      // Стоимость сборки (модуль assembly_cost) — плоская сумма, снапшот из
      // storefront; входит в холд при онлайн-оплате, поэтому обязана остаться
      // в amount после пересчёта (иначе capture < hold — недосбор у мерчанта).
      $assemblyCost = (int) ($order->assembly_cost ?? 0);

      // Применяем промо-скидку пропорционально, если она была
      // origin_amount = сумма товаров ПОСЛЕ скидки (из события storefront)
      $originAmount = (int) ($order->origin_amount ?? 0);

      // Пересчитываем скидку по промокоду
      $discount = self::recalculateDiscount($order, $newItemsTotal);

      // Доставка: по снимку условий заказа (MID-280) от суммы товаров до промокода,
      // без снимка прежний порог бесплатной доставки зоны магазина
      $deliveryPrice = (new OrderDeliveryPriceResolver())->resolve($order, $newItemsTotal, $discount);

      // Списанные бонусы (взаимоисключают промокод, поэтому не конфликтуют с discount)
      $bonusSpent = (int) ($order->bonus_spent ?? 0);
      // Ограничить bonusSpent: не больше суммы товаров И не больше разрешённого процента магазина
      $spendPercent = (int) ($order->shop->bonus_spend_percent ?? 0);
      $maxByAmount  = max(0, $newItemsTotal - $discount);
      $maxByPercent = (int) floor($newItemsTotal * $spendPercent / 100);
      $bonusSpent   = min($bonusSpent, $maxByAmount, $maxByPercent);

      // Сумма = товары - скидка - бонусы + доставка + сборка
      $amount = round(max(0, $newItemsTotal - $discount - $bonusSpent + $deliveryPrice + $assemblyCost), 2);

      $updateData = [
        'amount' => $amount,
        // Сумма товаров по факту сборки: шапка заказа в админке считает
        // «Стоимость товаров − Скидка + Доставка = Итого», и с исходным
        // items_subtotal арифметика расходилась на отменённые позиции.
        'items_subtotal' => round($newItemsTotal, 2),
        'discount' => $discount,
        'delivery_price' => $deliveryPrice,
        'bonus_spent' => $bonusSpent,
        'total_weight' => $weightData['total_weight'],
        'is_weight_partial' => $weightData['is_weight_partial'],
      ];

      // Пересчёт признака «Тяжёлый заказ» по ФАКТИЧЕСКОМУ весу (замены/отмены/
      // взвешивание учтены) — к моменту назначения курьеров признак актуален.
      // Активен при heavy_orders ИЛИ courier_assignment (Platrum 12180);
      // при обоих выключенных признак не трогаем (заморожен как был).
      if (Order::isHeavyDeliveryFlowEnabled()) {
        $updateData['is_heavy'] = self::isHeavyWeight($order, (int) $weightData['total_weight']);
      }

      // Обновляем общую сумму заказа БЕЗ отправки события в outbox
      // (событие уйдёт только при смене статуса на assembled в finishOrder)
      Order::whereId($order->id)->update($updateData);

      // Обновляем модель чтобы дальнейший код видел актуальные данные
      $order->refresh();

      // Синхронизировать items и сумму в родительский заказ ПОСЛЕ обновления amount ребёнка
      self::syncItemsToParent($order);

      return $order;
    });
  }

  /**
   * Пересчитать денежные итоги заказа по новой сумме товаров (без списания остатка).
   * Используется и сборкой (assemblyFinish), и доставкой (DeliveryFinish settlement
   * возвратов курьера, tg-14265). Возвращает массив для Order::update.
   *
   * @return array{amount:float,discount:int,delivery_price:int,bonus_spent:int}
   */
  public static function recalcOrderTotals(Order $order, float $newItemsTotal): array
  {
    // Стоимость сборки — плоская, не зависит от состава: сохраняется в amount
    $assemblyCost = (int) ($order->assembly_cost ?? 0);
    $discount = self::recalculateDiscount($order, $newItemsTotal);
    // Доставка: по снимку условий заказа (MID-280) от суммы товаров до промокода,
    // без снимка прежний порог бесплатной доставки зоны магазина
    $deliveryPrice = (new OrderDeliveryPriceResolver())->resolve($order, $newItemsTotal, $discount);

    $bonusSpent = (int) ($order->bonus_spent ?? 0);
    $spendPercent = (int) ($order->shop->bonus_spend_percent ?? 0);
    $maxByAmount  = max(0, $newItemsTotal - $discount);
    $maxByPercent = (int) floor($newItemsTotal * $spendPercent / 100);
    $bonusSpent   = min($bonusSpent, $maxByAmount, $maxByPercent);

    $amount = round(max(0, $newItemsTotal - $discount - $bonusSpent + $deliveryPrice + $assemblyCost), 2);

    return [
      'amount' => $amount,
      'items_subtotal' => round($newItemsTotal, 2),
      'discount' => (int) $discount,
      'delivery_price' => $deliveryPrice,
      'bonus_spent' => $bonusSpent,
    ];
  }

  /**
   * Списать средства за заказ
   */
  private static function payForOrderOnline(Order $order): array
  {
    $url = config('app.url') . "/purchase/sberbank/payment";

    $token = config('services.system.token');

    $data = [
      'order_id' => $order->id,
      'token' => $token
    ];

    $result = \Http::asJson()->post($url, $data)->json();

    if (!$result) {
      \Log::error('Онлайн оплата не прошла: системная ошибка', [
        'order_id' => $order->id,
        'amount' => $order->amount,
      ]);

      return [
        'code' => 500,
        'message' => 'SYSTEM ERROR'
      ];
    }

    if (!$result['success']) {
      \Log::error('Онлайн оплата не прошла', [
        'order_id' => $order->id,
        'amount' => $order->amount,
        'message' => $result['message'] ?? 'Unknown error',
      ]);
    }

    return [
      'code' => $result['success'] ? 200 : 500,
      'message' => $result['success'] ? 'OK' : $result['message']
    ];

  }

  /**
   * Пересчёт скидки заказа после сборки.
   *
   * Источник правды — снапшот промокода (тип + номинал), тот же, по которому
   * считает витрина:
   *  - fixed   → min(номинал, сумма товаров): номинал не масштабируется по составу;
   *  - percent → процент от суммы eligible-позиций.
   *
   * Скидка живёт на РОДИТЕЛЕ: у подзаказов из splitOrderByZones её нет
   * (создаются с discount = 0), иначе зачёт был бы двойным — родитель
   * считает свою скидку сам в calculateParentTotals.
   */
  public static function recalculateDiscount(Order $order, float $newItemsTotal): float
  {
    if ($order->parent_order_id !== null) {
      return 0.0;
    }

    return self::discountForItems(
      $order,
      $newItemsTotal,
      self::itemsWithStatus($order, Status::ORDER_ITEM_ASSEMBLED)
    );
  }

  /**
   * Скидка по снапшоту промокода для заданной суммы товаров.
   *
   * @param \Illuminate\Support\Collection $eligibleSource позиции, от которых
   *        считается eligible-база процентного промокода
   */
  private static function discountForItems(Order $order, float $itemsTotal, $eligibleSource): float
  {
    $promocode = self::resolvePromocodeSnapshot($order);

    if ($promocode === null || $itemsTotal <= 0) {
      return 0.0;
    }

    if ($promocode['type'] === self::PROMOCODE_FIXED) {
      // Фиксированный номинал: ровно как на витрине — min(номинал, сумма товаров).
      return round(min($promocode['value'], $itemsTotal), 2);
    }

    return self::percentDiscount($order, $itemsTotal, $eligibleSource, $promocode['value']);
  }

  /**
   * Снапшот промокода заказа: ['type' => fixed|percent, 'value' => номинал].
   *
   * Заказы, созданные до появления снапшота, распознаём по старым полям:
   * процент лежит в discount_percent, а фиксированная скидка — в самой
   * сумме discount (у родителя она не затирается: пересчёт идемпотентен).
   *
   * @return array{type:string,value:float}|null
   */
  private static function resolvePromocodeSnapshot(Order $order): ?array
  {
    $type = $order->promocode_type;
    $value = (float) ($order->promocode_value ?? 0);

    if ($type !== null && $value > 0) {
      return ['type' => $type, 'value' => $value];
    }

    if ((int) $order->discount_percent > 0) {
      return ['type' => self::PROMOCODE_PERCENT, 'value' => (float) $order->discount_percent];
    }

    $discount = (float) ($order->discount ?? 0);

    return $discount > 0
      ? ['type' => self::PROMOCODE_FIXED, 'value' => $discount]
      : null;
  }

  /**
   * Процентный промокод: процент от суммы eligible-позиций.
   *
   * Для замен (origin_product_id != null) считаем is_eligible = true,
   * т.к. docker не знает об акциях на товар-замену.
   *
   * Fallback: если данные is_eligible не пришли из storefront
   * (все items eligible, но eligible_amount < items_total),
   * используем пропорциональное масштабирование.
   */
  private static function percentDiscount(Order $order, float $itemsTotal, $items, float $discountPercent): float
  {
    if ($items->isEmpty()) {
      return 0.0;
    }

    // Проверяем, пришли ли данные is_eligible из storefront:
    // если ВСЕ items eligible и при этом eligible_amount < original items total,
    // значит данные не пришли (default=true у всех) — используем fallback
    $hasExplicitEligibility = $items->contains(fn ($item) => !$item->is_eligible);

    // MID-308: у позиций со снапшотом лесенки is_eligible пересчитан по факту
    // сборки (collectOrderItems), данные о скидочности достоверны, даже когда все
    // позиции стали eligible: позиция вернулась с дешёвой ступени на первую.
    // Пропорциональный fallback «данные не пришли» для такого заказа ложный.
    $tierPricing = new OrderItemTierPricing();
    $hasTierSnapshots = $items->contains(fn ($item) => $tierPricing->snapshotOf($item) !== null);

    if (!$hasExplicitEligibility && !$hasTierSnapshots && $order->eligible_amount !== null && $order->eligible_amount > 0) {
      // amount включает доставку И стоимость сборки — восстанавливая сумму
      // товаров, вычитаем обе надбавки, иначе derivation завышен на assembly_cost
      $originalItemsTotal = (int) ($order->amount - ($order->delivery_price ?? 0) - ($order->assembly_cost ?? 0) + ($order->discount ?? 0));
      if ($originalItemsTotal > 0 && $order->eligible_amount < $originalItemsTotal) {
        // Данные is_eligible не пришли — fallback на пропорциональное масштабирование
        return self::percentDiscountProportional($order, $itemsTotal, $originalItemsTotal, $discountPercent);
      }
    }

    // Суммируем eligible-позиции
    // Замены (origin_product_id != null) считаем eligible,
    // т.к. docker не знает об акциях товара-замены
    $eligibleTotal = $items->sum(function ($item) use ($tierPricing) {
      // MID-308: замена со свежим снапшотом лесенки несёт достоверный
      // is_eligible (сработала ли дешёвая ступень товара-замены)
      if ($item->origin_product_id !== null && $tierPricing->snapshotOf($item) !== null) {
        return $item->is_eligible ? $item->price : 0;
      }
      if ($item->origin_product_id !== null) {
        return $item->price; // замена — считаем eligible
      }
      return $item->is_eligible ? $item->price : 0;
    });

    if ($eligibleTotal <= 0) {
      return 0.0;
    }

    $discount = round($eligibleTotal * $discountPercent / 100, 2);

    return min($discount, $itemsTotal);
  }

  /**
   * Fallback: масштабируем eligible-базу пропорционально (старая логика).
   * Используется для заказов, где is_eligible не пришёл из storefront.
   */
  private static function percentDiscountProportional(
    Order $order,
    float $itemsTotal,
    float $originalItemsTotal,
    float $discountPercent
  ): float {
    $eligibleAmount = (float) $order->eligible_amount;

    $scaledEligible = round($eligibleAmount * $itemsTotal / $originalItemsTotal, 2);
    $discount = round($scaledEligible * $discountPercent / 100, 2);

    return min($discount, $itemsTotal);
  }

  /**
   * Позиции заказа в заданном статусе. Читаем таблицей, а не через relation:
   * Order::orderItems() прячет отменённые позиции от роли COURIER.
   *
   * @return \Illuminate\Support\Collection
   */
  private static function itemsWithStatus(Order $order, int $statusId)
  {
    return OrderItem::where('order_id', $order->id)
      ->where('status_id', $statusId)
      ->get();
  }

  /**
   * Неотменённые позиции заказа — eligible-база родителя: подзаказы могут быть
   * собраны не одновременно, и ограничение статусом ASSEMBLED занижало бы
   * скидку в промежуточных состояниях.
   *
   * @return \Illuminate\Support\Collection
   */
  private static function activeItems(Order $order)
  {
    return OrderItem::where('order_id', $order->id)
      ->where('status_id', '!=', Status::ORDER_ITEM_CANCELED)
      ->get();
  }

  /**
   * Выполняет пересчет позиций в заказе на основе сборки
   */
  public static function collectOrderItems(Assembly $assembly, bool $adjustStock = true): float
  {
    $amount = 0;

    // MID-308: один экземпляр на проход (флаг модуля, нужный только для свежих
    // снапшотов замен, читается один раз)
    $tier_pricing = new OrderItemTierPricing();

    foreach ($assembly->assemblyItems as $assembly_item) {
      $order_item = $assembly_item->orderItem;

      \Log::info('Processing assembly item', [
        'assembly_item_id' => $assembly_item->id,
        'order_item_id' => $order_item->id
      ]);
      \Log::info('Checking if shop ignores stock', [
        'shop_id' => $assembly->order->shop->id,
        'ignore_stock' => $assembly->order->shop->ignore_stock
      ]);
      if ($adjustStock && !$assembly->order->shop->ignore_stock) {
        // Списываем остаток того товара, который реально собран (с учётом замены)
        $replacement_for_stock = $assembly_item->replacements()->selected()->first();
        $product = $replacement_for_stock
          ? $replacement_for_stock->product
          : $order_item->product;

        // Per-product режим ignore/fixed (effective_stock_mode) — этот товар
        // остаток не списывает, даже если магазин в целом остатки учитывает.
        if ($product && $assembly_item->status_id == Status::PRODUCT_ASSEMBLED && !$product->effectiveStockIgnored()) {
          // Фактически собранное количество (а не то, что заказал клиент) — в
          // единице остатка: весовой по фактическому весу, остальные по порциям.
          $collectedQuantity = StockUnits::collectedToStock(
            $product,
            $assembly_item->actual_weight,
            $assembly_item->quantity ?? $order_item->quantity
          );

          $remain = $product->remain()->withoutGlobalScope('onlyAuth')->where('shop_id', $assembly->order->shop->id)->first();
          \Log::info('Found remain', [
            'remain' => $remain,
            'collected_quantity' => $collectedQuantity,
          ]);

          if ($remain && $remain->quantity >= $collectedQuantity) {
            \Log::info('Перед обновлением остатка', [
              'remain_id' => $remain->id,
              'quantity_before' => $remain->quantity,
              'order_item_quantity' => $order_item->quantity,
              'collected_quantity' => $collectedQuantity,
              'product_id' => $product->id,
              'shop_id' => $assembly->order->shop->id
            ]);

            $newQuantity = round($remain->quantity - $collectedQuantity, 3);

            $rowsUpdated = $remain->update([
                'quantity' => $newQuantity,
                'updated_at' => now()
              ]);

            \Log::info('После обновления остатка', [
              'rows_updated' => $rowsUpdated,
              'remain_id' => $remain->id,
              'new_quantity' => $newQuantity
            ]);

            if ($rowsUpdated > 0) {
              // Запускаем событие изменения остатков

              \Log::error('Dispatching ProductStockChanged event', [
                'product_id' => $product->id,
                'old_quantity' => round($newQuantity + $collectedQuantity, 3),
                'new_quantity' => $newQuantity,
                'shop_code' => $assembly->order->shop->code
              ]);
              ProductStockChanged::dispatch(
                $product,
                round($newQuantity + $collectedQuantity, 3), // старое количество
                $newQuantity, // новое количество
                $assembly->order->shop->code
              );
            }
          }
        }
      }

      // Если товар отменен не берем его в учет при общем подсчете суммы заказа
      if ($assembly_item->status_id == Status::PRODUCT_CANCELED) {
        $order_item->update([
          'status_id' => Status::ORDER_ITEM_CANCELED
        ]);
        continue;
      }

      // Выбранный замененный товар
      $replacement_product = $assembly_item->replacements()->selected()->first();

      $price_during_order = $order_item->price_during_order;

      // MID-308: свежий снапшот лесенки товара-замены (null: модуль выключен,
      // у замены нет цены либо лесенки в магазине заказа). Единственное в этом
      // методе, что гейтится флагом модуля.
      $replacement_snapshot = null;

      if ($replacement_product) {
        // Сетевой каталог: цена замены строго по магазину заказа. hasOne price()
        // без фильтра отдал бы цену произвольного магазина сети — при списании
        // оплаты сборщиком это реальные деньги клиента.
        // Легаси: как было — цена связи price(), при её отсутствии null.
        $replacement_price = $replacement_product->product
          ?->effectivePriceForShop($assembly->order?->shop_id);

        if (!$replacement_price && NetworkCatalogMode::isEnabled()) {
          throw new \GraphQL\Error\Error('У товара для замены нет цены в магазине заказа');
        }

        $price_during_order = $replacement_price?->value;

        if ($replacement_price && $replacement_product->product) {
          $replacement_snapshot = $tier_pricing->freshSnapshot(
            $replacement_product->product,
            $assembly->order?->shop_id,
            (float) $replacement_price->value,
            null,
            true
          );
        }
      }

      // Сохраняем origin при замене (если ещё не заполнены)
      $origin_data = [];
      if ($replacement_product && !$order_item->origin_product_id) {
        $origin_data = [
          'origin_product_id' => $order_item->product_id,
          'origin_title' => $order_item->product?->title,
          'origin_quantity' => $order_item->quantity,
          'origin_price' => $order_item->price,
          'replace_reason_id' => $assembly_item->replace_reason_id,
        ];
      }

      $quantity = $assembly_item->quantity ?? $order_item->quantity;

      // MID-308: цена за единицу по ступени для ФАКТИЧЕСКОГО количества. Снапшот:
      // у замены свежий (лесенка товара-замены), иначе сохранённый в позиции
      // (его наличия достаточно, флаг модуля не проверяется).
      // null = лесенки нет: всё ниже считается прежней формулой.
      // Весовая позиция: порции = max(1, round(вес / шаг)), итог строки ниже
      // по-прежнему берётся из снапшота ProductConfirm (assembly_items.price).
      $tier_snapshot = $replacement_product
        ? $replacement_snapshot
        : $tier_pricing->snapshotOf($order_item);
      $tier_quote = null;

      if ($tier_snapshot !== null) {
        $tier_product = $replacement_product
          ? $replacement_product->product
          : $order_item->product;
        $tier_portions = $assembly_item->actual_weight !== null && $tier_product
          ? OrderItemTierPricing::weightPortions((int) $assembly_item->actual_weight, $tier_product)
          : (float) $quantity;

        $tier_quote = $tier_snapshot->quote($tier_portions);
        $price_during_order = $tier_quote->unitPrice;
      }

      // Весовой товар: итог строки — снапшот ProductConfirm (assembly_items.price),
      // НЕ пересчёт по текущему product.info_weight: правка карточки товара между
      // подтверждением сборщиком и завершением сборки меняла сумму заказа
      // (конфеты 225.12 → 112.56 после смены шага 250 → 500 г). Пересчёт по шагу —
      // только fallback, когда снапшота нет.
      if ($assembly_item->actual_weight !== null) {
        $product_for_weight = $replacement_product
          ? $replacement_product->product
          : $order_item->product;
        $step_grams = ProductConfirm::getStepGrams($product_for_weight);
        $linePrice = $assembly_item->price !== null
          ? round((float) $assembly_item->price, 2)
          : round($price_during_order * $assembly_item->actual_weight / $step_grams, 2);
        // Пересчитываем quantity в порциях по фактическому весу для корректного отображения
        $quantity = max(1, (int) round($assembly_item->actual_weight / $step_grams));
      } else {
        // Штучный товар: итоговая цена позиции: per-unit * quantity
        $linePrice = $price_during_order * $quantity;
      }

      // При замене товара — ставим is_eligible = true,
      // т.к. docker не знает об акциях на товар-замену
      $eligibleData = [];
      if ($replacement_product) {
        $eligibleData['is_eligible'] = true;
      }

      // MID-308: по факту сборки переписываются зачёркнутая цена и признак скидки
      // (сработала ступень дешевле опорной цены = позиция со скидкой, промокод на
      // неё не действует). $tier_data сливается ПОСЛЕ $eligibleData: у замены со
      // свежим снапшотом скидочность известна, правило «замена всегда eligible»
      // остаётся только для замен без лесенки.
      $tier_data = [];
      if ($tier_quote !== null) {
        $tier_data['price_old'] = $tier_quote->unitPriceOld;
        $tier_data['is_eligible'] = $tier_quote->isEligible;
      }
      if ($replacement_product && ($order_item->price_tiers !== null || $replacement_snapshot !== null)) {
        // Снапшот принадлежит товару: лесенка исходного товара не должна остаться
        // на товаре-замене (иначе возврат у двери посчитает замену по чужой
        // лесенке). Снимается всегда; свежий появляется только при включённом
        // модуле и наличии лесенки у товара-замены в магазине заказа.
        $tier_data['price_tiers'] = $replacement_snapshot !== null
          ? $replacement_snapshot->toArray()
          : null;
      }

      $order_item->update(array_merge([
        'quantity' => $quantity,
        // Заказанные порции: quantity ниже переписывается фактом сборки (3 → 2
        // по весу), а «было» в админке и заказанная сумма в аналитике — от 3.
        'ordered_quantity' => $order_item->ordered_quantity ?? $order_item->quantity,
        'price' => $linePrice,
        'price_during_order' => $price_during_order,
        'status_id' => Status::ORDER_ITEM_ASSEMBLED,
        'product_id' => $replacement_product?->product_id ?? $order_item->product_id,
        'actual_weight' => $assembly_item->actual_weight,
      ], $origin_data, $eligibleData, $tier_data));

      if ($replacement_product && !empty($origin_data)) {
        $reason = $assembly_item->replace_reason_id
          ? \App\Models\ProductReplaceReason::find($assembly_item->replace_reason_id)
          : null;
        $collector = \App\Models\User::find($assembly->user_id);

        \App\Models\OrderHistory::log(
          $order_item->order_id,
          \App\Models\OrderHistory::ACTION_ITEM_REPLACED,
          \App\Models\OrderHistory::ACTOR_STAFF,
          $assembly->user_id,
          $collector ? trim("{$collector->surname} {$collector->name}") : null,
          $origin_data['origin_title'] . ' ×' . $origin_data['origin_quantity'],
          $replacement_product->product?->title . ' ×' . $quantity,
          $reason?->title
        );
      } elseif (!$replacement_product) {
        // Товар подтверждён при сборке без замены (и не отменён — та ветка
        // делает continue выше). Симметрично ACTION_ITEM_REPLACED, чтобы
        // позиция не пропадала из истории молча.
        $collector = \App\Models\User::find($assembly->user_id);

        \App\Models\OrderHistory::log(
          $order_item->order_id,
          \App\Models\OrderHistory::ACTION_ITEM_CONFIRMED,
          \App\Models\OrderHistory::ACTOR_STAFF,
          $assembly->user_id,
          $collector ? trim("{$collector->surname} {$collector->name}") : null,
          null,
          $order_item->product?->title . ' ×' . $quantity,
          null
        );
      }

      $supplementsTotal = $order_item->supplements->sum('amount');
      $amount += $linePrice + $supplementsTotal * $quantity;
    }

    return round($amount, 2);
  }

  /**
   * Суммарный вес заказа в граммах по финализированному составу order_items
   * (после collectOrderItems: замены применены, отменённые помечены).
   * LASTOCHKI-JAMI-11656.
   *
   * Правила вклада позиции:
   *  - отменена → пропуск;
   *  - actual_weight задан (весовой товар, взвешен сборщиком) → как есть (граммы/мл);
   *  - весовой/объёмный (info_weight_type_id 2..5) без actual_weight —
   *    подтверждён порциями → quantity × getStepGrams (номинальный вес порций);
   *  - штучный (info_weight_type_id 1/NULL) с заполненным products.weight →
   *    weight × quantity;
   *  - штучный без веса или товар не найден → в сумму не входит,
   *    is_weight_partial = true.
   *
   * Мл/литры считаются 1:1 к граммам (объём ≈ вес — осознанное допущение).
   *
   * @param \Illuminate\Support\Collection<int, OrderItem> $orderItems с загруженным relation product
   * @return array{total_weight: int, is_weight_partial: bool}
   */
  public static function calculateOrderWeight(\Illuminate\Support\Collection $orderItems): array
  {
    $totalWeight = 0;
    $isPartial = false;

    foreach ($orderItems as $item) {
      if ((int) $item->status_id === Status::ORDER_ITEM_CANCELED) {
        continue;
      }

      if ($item->actual_weight !== null) {
        $totalWeight += (int) $item->actual_weight;
        continue;
      }

      $product = $item->product;

      if (!$product) {
        $isPartial = true;
        continue;
      }

      if ($product->isWeightBased()) {
        // Порционный весовой без взвешивания: номинал = порции × шаг в граммах
        $totalWeight += (int) $item->quantity * ProductConfirm::getStepGrams($product);
        continue;
      }

      if ((int) ($product->weight ?? 0) > 0) {
        $totalWeight += (int) $product->weight * (int) $item->quantity;
        continue;
      }

      $isPartial = true;
    }

    return [
      'total_weight' => $totalWeight,
      'is_weight_partial' => $isPartial,
    ];
  }

  /**
   * Превышает ли вес порог «Тяжёлого заказа» магазина (строго больше).
   * Порог null/0 = не задан → false.
   */
  public static function isHeavyWeight(Order $order, int $totalWeight): bool
  {
    $order->loadMissing('shop');
    $threshold = $order->shop?->heavy_order_threshold;

    return $threshold !== null && (int) $threshold > 0 && $totalWeight > (int) $threshold;
  }

  /**
   * После сборки/отмены подзаказа — обновить соответствующие items в родительском заказе.
   * Сопоставление по product_id (или origin_product_id при замене).
   *
   * Вызывается:
   *  - изнутри AssemblyFinish::assemblyFinish (нормальное завершение сборки)
   *  - из ProductCancel::finishAssemblyAndCancelOrder (отмена всех позиций сборщиком, LASTOCHKI-JAMI-10016)
   */
  public static function syncItemsToParent(Order $order): void
  {
    if ($order->parent_order_id === null) {
      return;
    }

    $parent = Order::find($order->parent_order_id);
    if (!$parent) {
      return;
    }

    $order->load('orderItems');
    $parentItems = OrderItem::where('order_id', $parent->id)->get();

    // Снимок ИСХОДНОГО товара каждой строки родителя (origin_product_id, а при
    // его отсутствии product_id) и список уже занятых строк.
    //
    // Снимок нужен, потому что цикл ниже сам перезаписывает product_id у
    // родителя (при замене строка принимает товар-замену): матчить по ЖИВОМУ
    // значению нельзя, вторая дочерняя строка с этим же product_id попадала в
    // только что обработанную строку и затирала замену. Ровно так терялись
    // прод-заказы 386 и 370 — сборщик добавлял товар-замену отдельной позицией
    // (ProductAddToOrder) и отменял её, отменённая шла в цикле второй и уносила
    // и цену, и origin_product_id. Одной строке родителя соответствует ровно
    // одна дочерняя.
    //
    // Ключ берётся именно по origin_product_id, иначе склейка не идемпотентна:
    // при включённом collector_payment завершение сборки прогоняет
    // assemblyFinish дважды (AssemblyChargePayment::chargeForAssembly — списание
    // оплаты без списания остатка, затем performFinish), и на втором проходе
    // строка родителя уже несёт товар-замену. Матч по product_id её не находил,
    // и замена дублировалась новой строкой: прод-заказы 436 (+495.96, три
    // замены) и 459 (+44.90) 11.09.2026 уехали в витрину с завышенным составом.
    $matchKeys = $parentItems->mapWithKeys(fn (OrderItem $item) => [
      $item->id => (int) ($item->origin_product_id ?? $item->product_id),
    ]);
    $claimedParentIds = [];

    $createdCount = 0;

    foreach ($order->orderItems as $childItem) {
      // Матчим по оригинальному product_id (при замене) из снимка
      $matchProductId = (int) ($childItem->origin_product_id ?? $childItem->product_id);

      // Повторный проход (см. комментарий к снимку): строка родителя уже несёт
      // ровно этот товар-замену. Такая строка забирается первой, иначе дочерняя
      // позиция могла бы занять соседнюю строку той же номенклатуры и оставить
      // склеенную ранее без пары.
      $parentItem = $parentItems->first(
        fn (OrderItem $candidate) => !isset($claimedParentIds[$candidate->id])
          && (int) ($matchKeys[$candidate->id] ?? 0) === $matchProductId
          && (int) $candidate->product_id === (int) $childItem->product_id
      );

      $parentItem ??= $parentItems->first(
        fn (OrderItem $candidate) => !isset($claimedParentIds[$candidate->id])
          && (int) ($matchKeys[$candidate->id] ?? 0) === $matchProductId
      );

      // MID-308: родитель наследует снапшот лесенки и пересчитанную по факту
      // зачёркнутую цену ребёнка (событие заказа строится из позиций родителя).
      // Решает наличие снапшота у ребёнка или у найденной строки родителя, а не
      // флаг модуля; без снапшотов набор синхронизируемых полей прежний.
      $tierFields = $childItem->price_tiers !== null || ($parentItem && $parentItem->price_tiers !== null)
        ? ['price_tiers' => $childItem->price_tiers, 'price_old' => $childItem->price_old]
        : [];

      // Позиции, добавленной сборщиком (ProductAddToOrder), у родителя нет вовсе,
      // а её деньги уже входят в parent.amount через calculateParentTotals. Без
      // строки состав родителя расходится с его же суммой, и событие order.updated
      // уезжает в витрину неконсистентным (прод-заказ 133).
      if (!$parentItem) {
        $parentItem = OrderItem::withoutEvents(
          fn () => $parent->orderItems()->create(array_merge([
            'product_id'         => $childItem->product_id,
            'quantity'           => $childItem->quantity,
            'price'              => $childItem->price,
            'price_during_order' => $childItem->price_during_order,
            'status_id'          => $childItem->status_id,
          ], $tierFields))
        );
        $parentItems->push($parentItem);
        $matchKeys[$parentItem->id] = $matchProductId;
        $createdCount++;
      }

      $claimedParentIds[$parentItem->id] = true;

      OrderItem::withoutEvents(function () use ($parentItem, $childItem, $tierFields) {
        $parentItem->update(array_merge([
          'quantity'           => $childItem->quantity,
          'ordered_quantity'   => $childItem->ordered_quantity,
          'price'              => $childItem->price,
          'price_during_order' => $childItem->price_during_order,
          'status_id'          => $childItem->status_id,
          'product_id'         => $childItem->product_id,
          'actual_weight'      => $childItem->actual_weight,
          'origin_product_id'  => $childItem->origin_product_id,
          'origin_quantity'    => $childItem->origin_quantity,
          'origin_price'       => $childItem->origin_price,
          'replace_reason_id'  => $childItem->replace_reason_id,
          'is_eligible'        => $childItem->is_eligible,
          'is_supplemented'    => $childItem->is_supplemented,
        ], $tierFields));
      });
    }

    // Пересчитать сумму родительского заказа на основе всех дочерних
    $updates = self::calculateParentTotals($parent);
    Order::whereId($parent->id)->update($updates);

    \Log::info('Synced child items to parent', [
      'child_order_id'  => $order->id,
      'parent_order_id' => $parent->id,
      'items_synced'    => $order->orderItems->count(),
      'items_created'   => $createdCount,
      'parent_amount'   => $updates['amount'] ?? null,
    ]);
  }

  /**
   * Пересчитать суммы родителя по дочерним заказам.
   *
   * Скидка считается ОДИН раз здесь, по снапшоту промокода родителя: подзаказы
   * её не применяют (см. recalculateDiscount). Раньше родительская скидка была
   * суммой детских, и фиксированный промокод терялся целиком — у подзаказов
   * discount = 0, а discount_percent у fixed-промокода пуст (прод-заказы 200
   * и 207, 2026-09-10: минус 300 ₽ скидки и завышенный чек).
   *
   * Сумма товаров детей восстанавливается как amount + discount: у заказов,
   * собранных до этого фикса, скидка могла остаться на ребёнке.
   *
   * parent.amount = сумма товаров детей − parent.discount − parent.bonus_spent
   *                 + parent.delivery_price + parent.assembly_cost
   */
  private static function calculateParentTotals(Order $parent): array
  {
    $activeChildren = $parent->childOrders()
      ->where('status_id', '!=', Status::ORDER_CANCELLED)
      ->get();

    $childrenTotal = round(
      (float) $activeChildren->sum(fn ($child) => (float) $child->amount + (float) $child->discount),
      2
    );
    $totalDiscount = self::discountForItems($parent, $childrenTotal, self::activeItems($parent));

    // Вес родителя = сумма зафиксированных весов неотменённых детей
    // (NULL у ещё не собранных → 0; сходится к полному после сборки всех).
    // LASTOCHKI-JAMI-11656.
    $totalWeight = (int) $activeChildren->sum('total_weight');
    $isWeightPartial = $activeChildren->contains(
      fn ($child) => (bool) $child->is_weight_partial
    );

    $bonusSpent = min((int) ($parent->bonus_spent ?? 0), max(0, $childrenTotal - $totalDiscount));
    // Доставка: по снимку условий родителя (MID-280) от суммы товаров детей до скидки,
    // без снимка прежний порог бесплатной доставки зоны магазина
    $deliveryPrice = (new OrderDeliveryPriceResolver())->resolve($parent, $childrenTotal, $totalDiscount);
    // Стоимость сборки живёт на родителе (дети создаются с assembly_cost=0)
    $assemblyCost = (int) ($parent->assembly_cost ?? 0);

    $amount = round(max(0, $childrenTotal - $totalDiscount - $bonusSpent + $deliveryPrice + $assemblyCost), 2);

    $updates = [
      'amount'            => $amount,
      'items_subtotal'    => $childrenTotal,
      'discount'          => $totalDiscount,
      'delivery_price'    => $deliveryPrice,
      'bonus_spent'       => $bonusSpent,
      'total_weight'      => $totalWeight,
      'is_weight_partial' => $isWeightPartial,
    ];

    // Признак тяжёлого для родителя — по суммарному весу детей
    if (Order::isHeavyDeliveryFlowEnabled()) {
      $updates['is_heavy'] = self::isHeavyWeight($parent, (int) $totalWeight);
    }

    return $updates;
  }

  /**
   * Возврат остатков при отмене собранного/доставляющегося заказа.
   *
   * Симметричен collectOrderItems: для каждого assembly_item в статусе PRODUCT_ASSEMBLED
   * инкрементит Remain.quantity и публикует ProductStockChanged (для синхронизации со storefront).
   * Идемпотентен: после возврата assembly_item переводится в PRODUCT_CANCELED,
   * поэтому повторный вызов (HTTP-retry purchase_back→delivery_back) ничего не возвращает повторно.
   */
  public static function restoreAssembledStock(Assembly $assembly): void
  {
    $assembly->loadMissing([
      'order.shop',
      'assemblyItems.replacements.product',
      'assemblyItems.orderItem.product',
    ]);

    $shop = $assembly->order?->shop;
    if (!$shop || $shop->ignore_stock) {
      return;
    }

    foreach ($assembly->assemblyItems as $assembly_item) {
      if ($assembly_item->status_id !== Status::PRODUCT_ASSEMBLED) {
        continue;
      }

      $replacement_for_stock = $assembly_item->replacements()->selected()->first();
      $product = $replacement_for_stock
        ? $replacement_for_stock->product
        : $assembly_item->orderItem?->product;

      if (!$product) {
        continue;
      }

      // Per-product режим ignore/fixed (effective_stock_mode) — остаток этого
      // товара не списывался при сборке, значит и возвращать нечего.
      if ($product->effectiveStockIgnored()) {
        continue;
      }

      // Симметрично collectOrderItems — в единице остатка.
      $restoreQuantity = StockUnits::collectedToStock(
        $product,
        $assembly_item->actual_weight,
        $assembly_item->quantity ?? $assembly_item->orderItem?->quantity
      );

      if ($restoreQuantity <= 0) {
        continue;
      }

      $remain = $product->remain()
        ->withoutGlobalScope('onlyAuth')
        ->where('shop_id', $shop->id)
        ->first();

      if (!$remain) {
        \Log::warning('AssemblyFinish::restoreAssembledStock: remain not found', [
          'product_id' => $product->id,
          'shop_id' => $shop->id,
          'assembly_item_id' => $assembly_item->id,
        ]);
        // assembly_item всё равно переводим, чтобы не пытаться вернуть повторно
        $assembly_item->update(['status_id' => Status::PRODUCT_CANCELED]);
        continue;
      }

      $oldQuantity = (float) $remain->quantity;
      $newQuantity = round($oldQuantity + $restoreQuantity, 3);

      $remain->update([
        'quantity' => $newQuantity,
        'updated_at' => now(),
      ]);

      ProductStockChanged::dispatch(
        $product,
        $oldQuantity,
        $newQuantity,
        $shop->code
      );

      \Log::info('AssemblyFinish::restoreAssembledStock: stock restored', [
        'assembly_item_id' => $assembly_item->id,
        'product_id' => $product->id,
        'shop_id' => $shop->id,
        'old_quantity' => $oldQuantity,
        'new_quantity' => $newQuantity,
        'restored' => $restoreQuantity,
      ]);

      $assembly_item->update(['status_id' => Status::PRODUCT_CANCELED]);
    }
  }

  /**
   * Если заказ — подзаказ, проверить все ли дочерние собраны/отменены.
   * Если да — пересчитать и перевести родителя в ASSEMBLED (или CANCELLED).
   */
  public static function syncParentStatus(Order $order): void
  {
    if ($order->parent_order_id === null) {
      return;
    }

    $parent = Order::find($order->parent_order_id);
    if (!$parent) {
      return;
    }

    // Если родитель уже отменён — не переводим его в ASSEMBLED при завершении
    // оставшихся подзаказов, которые не успели подхватить каскадную отмену
    if ($parent->status_id === Status::ORDER_CANCELLED) {
      return;
    }

    // Все дочерние должны быть ASSEMBLED или CANCELLED
    $hasUnfinished = $parent->childOrders()
      ->whereNotIn('status_id', [Status::ORDER_ASSEMBLED, Status::ORDER_CANCELLED])
      ->exists();

    if ($hasUnfinished) {
      return;
    }

    // Если все дочерние отменены — отменяем родителя
    $hasAssembled = $parent->childOrders()
      ->where('status_id', Status::ORDER_ASSEMBLED)
      ->exists();

    if (!$hasAssembled) {
      $oldStatusId = $parent->status_id;
      $parent->update(['status_id' => Status::ORDER_CANCELLED]);

      \App\Models\OrderHistory::log(
        $parent->id,
        \App\Models\OrderHistory::ACTION_CANCELED,
        \App\Models\OrderHistory::ACTOR_SYSTEM,
        null,
        null,
        self::statusLabel($oldStatusId),
        self::statusLabel(Status::ORDER_CANCELLED),
        'Все подзаказы отменены'
      );

      \Log::info('Parent order cancelled — all children cancelled', [
        'parent_order_id' => $parent->id,
      ]);
      return;
    }

    $updates = self::calculateParentTotals($parent);

    Order::whereId($parent->id)->update($updates);

    $parent->refresh();
    $oldStatusId = $parent->status_id;
    $parent->update(array_merge(
      ['status_id' => Status::ORDER_ASSEMBLED],
      $parent->pickup_ready_at ? [] : ['pickup_ready_at' => now()]
    ));

    \App\Models\OrderHistory::log(
      $parent->id,
      \App\Models\OrderHistory::ACTION_STATUS_CHANGED,
      \App\Models\OrderHistory::ACTOR_SYSTEM,
      null,
      null,
      self::statusLabel($oldStatusId),
      self::statusLabel(Status::ORDER_ASSEMBLED),
      'Все подзаказы собраны'
    );

    \Log::info('Parent order synced to ASSEMBLED', [
      'parent_order_id' => $parent->id,
      'amount'          => $updates['amount'],
    ]);

    // Модуль collector_payment: страховка для сплита, где последний подзаказ
    // не собирается, а отменяется (ProductCancel::finishAssemblyAndCancelOrder).
    // Тогда chargeForAssembly не вызывается вовсе, и без этого списание за уже
    // собранные зоны не прошло бы — заказ уехал бы курьеру неоплаченным.
    // В нормальном флоу родитель к этому моменту уже paid → no-op.
    if (Order::isCollectorPaymentModuleEnabled()
      && (int) $parent->payment_type_id === Type::PAYMENT_ONLINE
      && !$parent->paid
    ) {
      $charge = AssemblyChargePayment::settleOrder($parent->refresh());

      if ($charge['code'] !== 200) {
        \Log::warning('Parent order settle failed on ASSEMBLED sync', [
          'parent_order_id' => $parent->id,
          'code'            => $charge['code'],
          'message'         => $charge['message'],
        ]);
      }
    }
  }

  /**
   * Завершает сборку и отменяет заказ (весь его текущий под-заказ/родитель,
   * без каскада на соседние подзаказы по другим зонам сборки).
   *
   * Дополнительно проставляет cancelled-статус каждой позиции и синкает
   * статусы и order-status в родительский заказ. Без этого:
   *  - LASTOCHKI-JAMI-10016: курьер видит отменённые позиции в выдаче
   *    (фильтр Order::orderItems отсекает только OrderItem.status_id=
   *    ORDER_ITEM_CANCELED), admin_back transformOrderItems публикует
   *    item_status=confirmed для parent-items → клиент видит товары
   *    активными.
   *  - LASTOCHKI-JAMI-10054: parent.status_id остаётся в ORDER_PREPARING,
   *    т.к. syncItemsToParent трогает parent через mass-update без events
   *    → outbox молчит → storefront не получает order.updated с
   *    status=cancelled для parent. В админке parent висит в
   *    «Заказ на сборке» при отменённом единственном подзаказе.
   *
   * $comment (если передан) пишется в operator_comment ОДНИМ update вместе
   * со status_id, чтобы не плодить второй Eloquent-event/outbox-запись.
   */
  public static function finishAssemblyAndCancelOrder(Assembly $assembly, ?string $comment = null): void
  {
    \DB::transaction(function () use ($assembly, $comment) {
      $assembly->update([
        'finished_at' => now()
      ]);

      OrderItem::withoutEvents(function () use ($assembly) {
        $assembly->order->orderItems()->update([
          'status_id' => Status::ORDER_ITEM_CANCELED,
        ]);
      });

      $updateData = ['status_id' => Status::ORDER_CANCELED];
      if ($comment !== null) {
        $updateData['operator_comment'] = $comment;
      }
      $assembly->order->update($updateData);

      // syncItemsToParent сам делает no-op для не-подзаказов (parent_order_id IS NULL).
      // fresh('orderItems') нужен, чтобы перечитать только что изменённые status_id.
      self::syncItemsToParent($assembly->order->fresh('orderItems'));

      // Каскад child→parent на уровне order.status_id. Если все children в финале
      // (cancelled или mix cancelled+assembled) — переводит parent в соответствующий
      // терминальный статус через Eloquent update, что триггерит outbox publish.
      self::syncParentStatus($assembly->order->fresh());
    });
  }
}
