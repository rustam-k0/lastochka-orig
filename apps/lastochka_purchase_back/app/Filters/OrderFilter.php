<?php

namespace App\Filters;

use App\Models\Order;
use App\Models\Status;
use App\Models\Type;

class OrderFilter extends QueryFilter
{
  /**
   * Виртуальный код фильтра «Выдан» (завершённый самовывоз).
   * В таблице statuses не существует — разворачивается в условие
   * status_id + type_id, см. statuses().
   */
  public const FILTER_ISSUED = 7;

  /**
   * Виртуальный код фильтра «Назначен курьер» (собранный заказ с назначенным
   * курьером, модуль courier_assignment). В таблице statuses не существует.
   */
  public const FILTER_COURIER_ASSIGNED = 8;

  /**
   * Виртуальный код фильтра «Без курьера» (курьер на заказ не назначен).
   * Пользователей с id=0 не существует, поэтому 0 безопасен как сентинел
   * (тот же приём, что FILTER_ISSUED/FILTER_COURIER_ASSIGNED для статусов).
   */
  public const FILTER_NO_COURIER = 0;

  /**
   * Виртуальный код фильтра «Без сборщика» (сборка на заказ не назначена).
   * Тот же приём и то же значение, что FILTER_NO_COURIER, — пользователей
   * с id=0 не существует.
   */
  public const FILTER_NO_COLLECTOR = 0;

  public function title(string $value):void
  {
    $this->builder->where(function ($query) use ($value) {
      $query->where("uuid", "LIKE", "%$value%")
        ->orWhere("id", "LIKE", "%$value%");
    });
  }

  public function client_id($value)
  {
    $this->builder->where("client_id",$value);
  }

  public function shops($value)
  {
    $this->builder->whereIn('shop_id',$value);
  }

  /**
   * Выбрать начиная от
   * @param string $value
   */
  public function created_at_start_date(string $value): void
  {
    $this->builder->where('created_at', '>=', $value);
  }

  /**
   * Выбрать до
   * @param string $value
   */
  public function created_at_end_date(string $value): void
  {
    $this->builder->where('created_at', '<=', $value);
  }

  /**
   * Фильтр по статусам. Коды 1-6 — реальные status_id; особые случаи:
   * 3 («Собрано») — при включённом модуле courier_assignment только
   *   собранные БЕЗ назначенного курьера (при выключенном — все собранные),
   * 5 («Доставлен») — завершённые заказы доставки (без самовывозов),
   * 7 («Выдан») — завершённые самовывозы (status_id=5 AND type_id=1),
   * 8 («Назначен курьер») — собранные с назначенным курьером,
   * 5 и 7 вместе — все завершённые; 3 и 8 вместе — все собранные.
   */
  public function statuses($value): void
  {
    $value = array_map('intval', $value);
    $hasIssued = in_array(self::FILTER_ISSUED, $value, true);
    $hasFinished = in_array(Status::ORDER_FINISHED, $value, true);
    $hasCourierAssigned = in_array(self::FILTER_COURIER_ASSIGNED, $value, true);
    $hasAssembled = in_array(Status::ORDER_ASSEMBLED, $value, true);
    $plain = array_values(array_diff($value, [
      self::FILTER_ISSUED,
      Status::ORDER_FINISHED,
      self::FILTER_COURIER_ASSIGNED,
      Status::ORDER_ASSEMBLED,
    ]));

    if ($hasIssued && $hasFinished) {
      $plain[] = Status::ORDER_FINISHED;
      $hasIssued = false;
      $hasFinished = false;
    }

    if ($hasAssembled && $hasCourierAssigned) {
      $plain[] = Status::ORDER_ASSEMBLED;
      $hasAssembled = false;
      $hasCourierAssigned = false;
    } elseif ($hasAssembled && !Order::isCourierAssignmentModuleEnabled()) {
      // Модуль выключен: «Собрано» = все собранные (текущее поведение)
      $plain[] = Status::ORDER_ASSEMBLED;
      $hasAssembled = false;
    }

    $this->builder->where(function ($query) use ($plain, $hasIssued, $hasFinished, $hasCourierAssigned, $hasAssembled) {
      $query->whereIn('status_id', $plain);

      if ($hasIssued) {
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_FINISHED)
            ->where('type_id', Type::RECEIVE_PICKUP);
        });
      }

      if ($hasFinished) {
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_FINISHED)
            ->where(function ($t) {
              $t->where('type_id', '!=', Type::RECEIVE_PICKUP)
                ->orWhereNull('type_id');
            });
        });
      }

      if ($hasCourierAssigned) {
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_ASSEMBLED);
          $this->whereCourierAssigned($q, true);
        });
      }

      if ($hasAssembled) {
        // Достижимо только при включённом модуле: собранные без назначения
        $query->orWhere(function ($q) {
          $q->where('status_id', Status::ORDER_ASSEMBLED);
          $this->whereCourierAssigned($q, false);
        });
      }
    });
  }

  /**
   * Назначен ли заказу курьер: существует активное (не мягко удалённое)
   * delivery_orders на сам заказ или его подзаказ (зеркало
   * OrderCouriersResolver / selfAndChildOrderIds). Использует тот же
   * подзапрос, что и couriers(), — activeCourierAssignmentQuery().
   */
  private function whereCourierAssigned($query, bool $exists): void
  {
    $method = $exists ? 'whereExists' : 'whereNotExists';
    $query->{$method}(function ($q) {
      $this->activeCourierAssignmentQuery($q);
    });
  }

  /**
   * Общий блок подзапроса «назначен ли заказу активный курьер»: активные
   * (не мягко удалённые) назначения delivery_orders/deliveries на заказ или
   * его подзаказ. Используется whereCourierAssigned() (фильтр статусов) и
   * веткам «назначен один из списка»/«без курьера» в couriers() — чтобы
   * soft-delete-гард и child-order условие не расходились между ними.
   */
  private function activeCourierAssignmentQuery($q): void
  {
    $q->selectRaw('1')
      ->from('delivery_orders')
      ->join('deliveries', 'deliveries.id', '=', 'delivery_orders.delivery_id')
      ->whereNull('delivery_orders.deleted_at')
      ->whereNull('deliveries.deleted_at')
      ->whereRaw('(delivery_orders.order_id = orders.id OR delivery_orders.order_id IN (select child.id from orders as child where child.parent_order_id = orders.id))');
  }

  /**
   * Фильтр по назначенным курьерам. Код 0 — «Без курьера»; при выборе и
   * курьеров, и «Без курьера» условия объединяются через OR.
   *
   * Мягко удалённые назначения игнорируются — иначе фильтр расходится с
   * колонкой «Курьер» в таблице, которую резолвит OrderCouriersResolver
   * через Eloquent с SoftDeletes.
   *
   * @param array $value
   */
  public function couriers(array $value): void
  {
    $value = array_map('intval', $value);
    $withoutCourier = in_array(self::FILTER_NO_COURIER, $value, true);
    $courierIds = array_values(array_filter(
      $value,
      static fn (int $id): bool => $id !== self::FILTER_NO_COURIER
    ));

    if (!$withoutCourier && !$courierIds) {
      return;
    }

    $this->builder->where(function ($query) use ($courierIds, $withoutCourier) {
      if ($courierIds) {
        $query->whereExists(function ($q) use ($courierIds) {
          $this->activeCourierAssignmentQuery($q);
          $q->whereIn('deliveries.user_id', $courierIds);
        });
      }

      if ($withoutCourier) {
        $method = $courierIds ? 'orWhereNotExists' : 'whereNotExists';
        $query->{$method}(function ($q) {
          $this->activeCourierAssignmentQuery($q);
        });
      }
    });
  }

  /**
   * Общий блок подзапроса «есть ли у заказа активная сборка»: записи
   * assemblies на заказ или его подзаказ, не мягко удалённые. Зеркалит
   * OrderCollectorResolver, который читает сборки через Eloquent с
   * SoftDeletes и по Order::selfAndChildOrderIds(); здесь подзапрос строится
   * на query builder, поэтому глобальный scope не применяется и deleted_at
   * проверяется явно.
   */
  private function activeAssemblyQuery($q): void
  {
    $q->selectRaw('1')
      ->from('assemblies')
      ->whereNull('assemblies.deleted_at')
      ->whereRaw('(assemblies.order_id = orders.id OR assemblies.order_id IN (select child.id from orders as child where child.parent_order_id = orders.id))');
  }

  /**
   * Фильтр по сборщикам. Код 0 — «Без сборщика»; при выборе и сборщиков,
   * и «Без сборщика» условия объединяются через OR.
   *
   * Заказ матчится, если его собирал любой из выбранных сборщиков. У
   * расщеплённого по зонам сборки заказа активных сборок несколько, а
   * колонка «Сборщик» показывает последнюю — в этом случае фильтр найдёт
   * заказ по любому из его сборщиков (см. спеку
   * docs/superpowers/specs/2026-07-31-orders-collector-filter-design.md).
   *
   * @param array $value
   */
  public function collectors(array $value): void
  {
    $value = array_map('intval', $value);
    $withoutCollector = in_array(self::FILTER_NO_COLLECTOR, $value, true);
    $collectorIds = array_values(array_filter(
      $value,
      static fn (int $id): bool => $id !== self::FILTER_NO_COLLECTOR
    ));

    if (!$withoutCollector && !$collectorIds) {
      return;
    }

    $this->builder->where(function ($query) use ($collectorIds, $withoutCollector) {
      if ($collectorIds) {
        $query->whereExists(function ($q) use ($collectorIds) {
          $this->activeAssemblyQuery($q);
          $q->whereIn('assemblies.user_id', $collectorIds);
        });
      }

      if ($withoutCollector) {
        $method = $collectorIds ? 'orWhereNotExists' : 'whereNotExists';
        $query->{$method}(function ($q) {
          $this->activeAssemblyQuery($q);
        });
      }
    });
  }

  /**
   * Фильтр по типу заказа (1 — самовывоз, 2 — доставка)
   * @param array $value
   */
  public function types(array $value): void
  {
    $this->builder->whereIn('type_id', $value);
  }

  /**
   * Фильтр по типу оплаты (1 — наличные, 2 — карта)
   * @param array $value
   */
  public function payment_types(array $value): void
  {
    $this->builder->whereIn('payment_type_id', $value);
  }

  /**
   * Фильтр по статусу оплаты: pending (ожидает — бакетит ещё и hold/null,
   * т.к. заказ без единой транзакции или с холдом для пользователя АП —
   * тоже «ожидает оплаты»), paid, failed, refunded.
   * @param array $value
   */
  public function payment_statuses(array $value): void
  {
    $statuses = [];
    $includeNull = false;

    foreach ($value as $status) {
      if ($status === 'pending') {
        $statuses[] = 'pending';
        $statuses[] = 'hold';
        $includeNull = true;
      } else {
        $statuses[] = $status;
      }
    }

    $this->builder->where(function ($query) use ($statuses, $includeNull) {
      $query->whereIn('payment_status', $statuses);
      if ($includeNull) {
        $query->orWhereNull('payment_status');
      }
    });
  }

  /**
   * Фильтр по минимальной сумме заказа
   * @param float $value
   */
  public function amount_from(float $value): void
  {
    $this->builder->where('amount', '>=', $value);
  }

  /**
   * Фильтр по максимальной сумме заказа
   * @param float $value
   */
  public function amount_to(float $value): void
  {
    $this->builder->where('amount', '<=', $value);
  }

  /**
   * Поиск по клиенту (имя/фамилия/телефон/email)
   * @param string $value
   */
  public function client_search(string $value): void
  {
    $this->builder->whereHas('client', function ($c) use ($value) {
      $c->where('name', 'LIKE', "%$value%")
        ->orWhere('surname', 'LIKE', "%$value%")
        ->orWhere('phone', 'LIKE', "%$value%")
        ->orWhere('email', 'LIKE', "%$value%");
    });
  }

}
