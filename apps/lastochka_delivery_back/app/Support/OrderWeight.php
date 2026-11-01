<?php

namespace App\Support;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Order;

/**
 * Вес заказа для выдач мобильного приложения.
 *
 * Колонка orders.total_weight заполняется дважды: витриной при оформлении
 * (плановый вес по составу корзины, доезжает событием order.created) и
 * AssemblyFinish при завершении сборки (фактический, с учётом взвешенных
 * позиций и замен). Для заказов, созданных мимо витрины (1С, админка),
 * плановый вес никто не считает — тогда он резолвится на лету по составу,
 * тем же алгоритмом, что и при сборке. В БД расчёт не пишется: до сборки
 * состав заказа ещё меняется, храним только зафиксированное значение.
 */
final class OrderWeight
{
  /**
   * Единица измерения веса во всех выдачах API. Граммы — то же значение,
   * в котором вес хранится в orders.total_weight и приходит из витрины.
   */
  public const UNIT = 'g';

  /**
   * Вес заказа и признак его неполноты.
   *
   * total_weight = null означает «посчитать нельзя» (нет позиций либо ни у
   * одной нет веса в карточке товара) — клиент скрывает строку веса.
   *
   * Результат не мемоизируется: расчётная ветка идёт по уже загруженному
   * relation, поэтому повторный вызов из соседнего поля выдачи новых запросов
   * не делает. Кеш по spl_object_id тут был бы миной — id переиспользуется
   * после сборки мусора, и заказ получил бы чужой вес.
   *
   * @return array{total_weight: ?int, is_weight_partial: bool}
   */
  public static function resolve(Order $order): array
  {
    if ($order->total_weight !== null) {
      return [
        'total_weight' => (int) $order->total_weight,
        'is_weight_partial' => (bool) $order->is_weight_partial,
      ];
    }

    $order->loadMissing('orderItems.product');
    $calculated = AssemblyFinish::calculateOrderWeight($order->orderItems ?? collect());

    return [
      // 0 грамм — это «ни одна позиция не дала веса», а не «заказ ничего не весит»
      'total_weight' => $calculated['total_weight'] > 0 ? (int) $calculated['total_weight'] : null,
      'is_weight_partial' => (bool) $calculated['is_weight_partial'],
    ];
  }

  /** Суммарный вес заказа в граммах (null — рассчитать невозможно). */
  public static function totalWeight(Order $order): ?int
  {
    return self::resolve($order)['total_weight'];
  }

  /** Вес неполный: часть позиций без веса в карточке товара. */
  public static function isWeightPartial(Order $order): bool
  {
    return self::resolve($order)['is_weight_partial'];
  }

  /**
   * Признак «Тяжёлый заказ».
   *
   * Зафиксированное в БД значение (проставляет витрина при оформлении и
   * AssemblyFinish при сборке) приоритетно. Если веса в БД нет вообще —
   * заказ мимо витрины, — признак выводится из расчётного веса, иначе плашка
   * «Тяжёлый заказ» пропадала бы до конца сборки. Гейт heavy_orders только на
   * расчётной ветке: замороженное в БД значение отдаём как раньше.
   */
  public static function isHeavy(Order $order): bool
  {
    if ((bool) $order->is_heavy) {
      return true;
    }

    if ($order->total_weight !== null || !Order::isHeavyOrdersModuleEnabled()) {
      return false;
    }

    $weight = self::totalWeight($order);

    return $weight !== null && AssemblyFinish::isHeavyWeight($order, $weight);
  }
}
