<?php

namespace App\Models;

use App\Models\Scopes\NotFinished;
use App\Traits\ModelTools;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


/**
 * @mixin IdeHelperDeliveryOrder
 */
class DeliveryOrder extends Model
{
  use ModelTools, SoftDeletes;

  /**
   * Назначения «в работе» у курьера: заказ передан ему и ещё не закрыт.
   * Основание признака занятости курьера (User::isBusy) на экране
   * «Выберите курьера» в приложении сборщика.
   */
  const ACTIVE_STATUSES = [
    Status::DELIVERY_WAIT_TO_START,
    Status::DELIVERY_IN_PROGRESS,
    Status::DELIVERY_ARRIVED,
  ];

  protected $fillable = [
    'order_id',
    'status_id',
    'priority',
    'started_at',
    'arrived_at',
    'finished_at',
  ];

  public function delivery(): BelongsTo
  {
    return $this->belongsTo(Delivery::class);
  }

  public function order(): BelongsTo
  {
    return $this->belongsTo(Order::class);
  }

  public function scopeAuth($query): void
  {
    $query->whereHas('delivery', fn($query) => $query->auth());
  }

  /**
   * Назначения курьера для его приложения (delivery_orders*): весь текущий
   * рейс плюс доставленные заказы закрытых рейсов — вкладка «Доставлено»
   * (MID-322).
   *
   * scopeAuth для этого не годится: после DeliveryFinish последнего заказа
   * рейс получает finished_at, GlobalScope NotFinished на Delivery прячет его,
   * и вместе с ним пропадали все доставленные назначения. Сам scopeAuth
   * остаётся как есть — на нём держатся проверки мутаций (CourierHasOrderRule,
   * смена приоритета), им закрытые рейсы видеть нельзя.
   *
   * Из закрытого рейса берём только DELIVERY_FINISHED: незакрытые назначения
   * такого рейса (закрытие из админки, старые данные) вернулись бы во вкладки
   * «К доставке» / «В пути», а отменённые курьеру не показываем.
   *
   * История закрытых рейсов — только по явному запросу (status_id содержит 4)
   * и в delivery_orders_item. Приложение строит «В пути» из delivery_orders*
   * без фильтра, и вся история курьера попадала туда (прод 07.10: 228 заказов).
   *
   * @param array $args аргументы поля GraphQL (Lighthouse передаёт их в scopes)
   */
  public function scopeCourierList($query, array $args = []): void
  {
    $withHistory = in_array(Status::DELIVERY_FINISHED, $args['status_id'] ?? [], true);

    $this->scopeCourierAssignments($query, $withHistory);
  }

  /**
   * Одно назначение курьера по id (delivery_orders_item): доставленные заказы
   * закрытых рейсов тоже открываются.
   */
  public function scopeCourierItem($query): void
  {
    $this->scopeCourierAssignments($query, true);
  }

  private function scopeCourierAssignments($query, bool $withHistory): void
  {
    if (!$withHistory) {
      $this->scopeAuth($query);
      return;
    }

    $query->whereHas('delivery', function ($query) {
      $query->withoutGlobalScope(NotFinished::class)
        ->auth()
        ->where(fn($query) => $query->whereNull('deliveries.finished_at')
          ->orWhere('delivery_orders.status_id', Status::DELIVERY_FINISHED));
    });
  }

  /**
   * Порядок маршрута во вкладке «В пути» приложения курьера: сначала заказы
   * с выбранной приоритетностью (1…n) по возрастанию, затем те, где курьер её
   * не выбирал (priority = 0 — значение по умолчанию при назначении заказа).
   * Внутри групп — по началу слота доставки, при равных слотах по назначению.
   *
   * Без сортировки список шёл в порядке назначения заказов, и выбранная
   * курьером приоритетность в нём не отражалась.
   *
   * Доставленные (вкладка «Доставлено») идут после маршрута, от свежих
   * к старым: история закрытых рейсов растёт и иначе вытеснила бы текущий
   * маршрут со страницы delivery_orders_paginate.
   */
  public function scopeOrderByPriority($query): void
  {
    $query->orderByRaw('delivery_orders.status_id = ?', [Status::DELIVERY_FINISHED])
      ->orderByRaw(
        'CASE WHEN delivery_orders.status_id = ? THEN delivery_orders.finished_at END DESC',
        [Status::DELIVERY_FINISHED]
      )
      ->orderByRaw('delivery_orders.priority = 0')
      ->orderBy('delivery_orders.priority')
      ->orderBy(
        Order::query()
          ->select('slot_start_date')
          ->whereColumn('orders.id', 'delivery_orders.order_id')
      )
      ->orderBy('delivery_orders.id');
  }

  /**
   * Незакрытые назначения — заказ у курьера «в работе» (User::isBusy,
   * shift_couriers.active_delivery_orders_count).
   *
   * Закрытие назначений двойное: delivery_back меняет status_id
   * (DeliveryFinish / JointDelivery::closeOtherAssignments), а admin_back-
   * консьюмер при отмене/завершении заказа штампует ТОЛЬКО finished_at.
   * Поэтому «активно» = активный статус И нет finished_at. Заказ обязан
   * существовать: EntityDelete в админке и чистки dev-БД назначения не
   * закрывают, и назначение-сирота держало курьера «На заказе» навсегда
   * (MID-243).
   */
  public function scopeActive($query): void
  {
    $query->whereIn('delivery_orders.status_id', self::ACTIVE_STATUSES)
      ->whereNull('delivery_orders.finished_at')
      ->whereHas('order');
  }

}
