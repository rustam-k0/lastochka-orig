# Словарь metric_key

Канонические ключи метрик для `kpi_targets.metric_key` и `shop_alert_rules.metric_key`.
«Факт vs план» (Task 8, Светофор) и «Лента сигналов» (Task 8) джойнят фактическое
значение к плану/порогу строго по этому ключу. Имена ключей менять НЕЛЬЗЯ, матч идёт
строкой. Всего 9 ключей.

Все формулы order-level считаются по db1 `lastochka_djami_copy`, диалект MySQL 8, с
обязательными правилами конверсии (`docs/conversion-rules.md`): выручка через
`status_id = 5`, фильтр родителей `parent_order_id IS NULL`, итог позиции
`price_during_order * quantity` (у `oi.price` смешанная семантика - НЕ использовать
ни `SUM(price)`, ни `SUM(price*quantity)`), для реализованной выручки позиций
`AND oi.status_id <> 3` (канонический фильтр, `cancel_reason_id IS NULL` НЕ
используется - см. conversion-rules.md правило 3), SLA по `type_id IN (2,5)`,
справочники `deleted_at IS NULL`.

Обозначения: `o` = `orders`, `oi` = `order_items`, `p` = `products`, `sh` = `shops`.
`metric_value` в датасете сигналов/плана это результат соответствующей формулы,
сгруппированный по `shop_id` (и периоду).

| # | metric_key | Смысл | Ед. | Направление «хуже» |
|---|---|---|---|---|
| 1 | `revenue` | Выручка (доставленные заказы) | ₽ | ниже плана |
| 2 | `orders_count` | Количество заказов | шт | ниже плана |
| 3 | `avg_check` | Средний чек | ₽ | ниже плана |
| 4 | `gross_margin_pct` | Валовая маржа | % | ниже плана (NULL пока нет cost_price) |
| 5 | `repeat_rate_pct` | Доля повторных клиентов | % | ниже плана |
| 6 | `sla_on_time_pct` | Доля доставок в срок | % | ниже плана |
| 7 | `cancel_rate_pct` | Доля отмен | % | ВЫШЕ плана (плохо) |
| 8 | `assembly_accuracy_pct` | Точность сборки | % | ниже плана |
| 9 | `revenue_per_sqm` | Выручка на м2 площади | ₽/м2 | ниже плана |

---

## 1. `revenue` - выручка, ₽

Сумма `amount` доставленных заказов-родителей.

```sql
SUM(CASE WHEN o.status_id = 5 THEN o.amount ELSE 0 END) AS revenue
-- база: FROM orders o WHERE o.parent_order_id IS NULL
```

## 2. `orders_count` - количество заказов, шт

Число доставленных заказов-родителей (KPI считает результативные заказы). Если бизнесу
нужны все созданные, снять условие по статусу, но для плана/сигналов используем
доставленные.

```sql
COUNT(DISTINCT CASE WHEN o.status_id = 5 THEN o.id END) AS orders_count
-- база: FROM orders o WHERE o.parent_order_id IS NULL
```

## 3. `avg_check` - средний чек, ₽

Выручка, делённая на число доставленных заказов.

```sql
SUM(CASE WHEN o.status_id = 5 THEN o.amount ELSE 0 END)
  / NULLIF(COUNT(DISTINCT CASE WHEN o.status_id = 5 THEN o.id END), 0) AS avg_check
-- база: FROM orders o WHERE o.parent_order_id IS NULL
```

## 4. `gross_margin_pct` - валовая маржа, %

`(выручка - COGS) / выручка * 100`, где выручка позиций =
`SUM(price_during_order * quantity)` (НЕ `oi.price` - смешанная семантика),
`COGS = SUM(qty * cost_price)`. Реализованные позиции: `status_id = 5 AND
oi.status_id <> 3` (отменённые при сборке позиции не дают ни выручки, ни COGS).
`oi.status_id <> 3` - канонический фильтр (НЕ `cancel_reason_id IS NULL`, см.
conversion-rules.md правило 3); он ОБЯЗАН стоять на ОБЕИХ ветках формулы (revenue и
COGS) симметрично - иначе COGS отменённых позиций вычитается из выручки без них и
маржа занижается. NULL-safe: пока `products.cost_price` пуст (не приехал из 1С), COGS
и маржа = NULL. НЕ подменять нулями.

```sql
(SUM(CASE WHEN o.status_id = 5 AND oi.status_id <> 3 THEN oi.price_during_order * oi.quantity ELSE 0 END)
   - SUM(CASE WHEN o.status_id = 5 AND oi.status_id <> 3 THEN oi.quantity * p.cost_price ELSE 0 END))
  * 100.0
  / NULLIF(SUM(CASE WHEN o.status_id = 5 AND oi.status_id <> 3 THEN oi.price_during_order * oi.quantity ELSE 0 END), 0)
  AS gross_margin_pct
-- база: FROM order_items oi
--        JOIN orders o   ON o.id = oi.order_id AND o.parent_order_id IS NULL
--        LEFT JOIN products p ON p.id = oi.product_id
```

## 5. `repeat_rate_pct` - доля повторных клиентов, %

Доля клиентов, у кого порядковый номер заказа `client_order_seq > 1` (окно по client_id
по всей истории). `client_order_seq = ROW_NUMBER() OVER (PARTITION BY o.client_id ORDER BY o.created_at)`.

```sql
COUNT(DISTINCT CASE WHEN client_order_seq > 1 THEN client_id END) * 100.0
  / NULLIF(COUNT(DISTINCT client_id), 0) AS repeat_rate_pct
-- база: подзапрос с ROW_NUMBER() по orders WHERE parent_order_id IS NULL
```

## 6. `sla_on_time_pct` - доля доставок в срок, %

Среди доставочных заказов (`type_id IN (2,5)`) доля завершённых не позже конца слота.

```sql
AVG(
  CASE
    WHEN o.type_id IN (2, 5) AND o.finished_at IS NOT NULL AND o.slot_end_date IS NOT NULL
      THEN CASE WHEN o.finished_at <= o.slot_end_date THEN 1 ELSE 0 END
  END
) * 100.0 AS sla_on_time_pct
-- база: FROM orders o WHERE o.parent_order_id IS NULL
-- (NULL по недоставочным типам не попадает в AVG - знаменатель = доставочные)
```

## 7. `cancel_rate_pct` - доля отмен, %

Доля заказов со `status_id = 6` от всех заказов-родителей. ВНИМАНИЕ: здесь отменённые НЕ
исключаются (они и есть числитель). Направление: рост = ухудшение.

```sql
SUM(CASE WHEN o.status_id = 6 THEN 1 ELSE 0 END) * 100.0
  / NULLIF(COUNT(*), 0) AS cancel_rate_pct
-- база: FROM orders o WHERE o.parent_order_id IS NULL
```

## 8. `assembly_accuracy_pct` - точность сборки, %

100% минус доля позиций с заменой или отменой сборщиком (`replace_reason_id` /
`cancel_reason_id`). Возвраты (`return_reason_id`) в знаменатель ошибок не входят
(происходят после сборки).

```sql
100.0 - (
  SUM(CASE WHEN oi.replace_reason_id IS NOT NULL OR oi.cancel_reason_id IS NOT NULL
           THEN 1 ELSE 0 END) * 100.0
  / NULLIF(COUNT(*), 0)
) AS assembly_accuracy_pct
-- база: FROM order_items oi JOIN orders o ON o.id = oi.order_id
--        WHERE o.parent_order_id IS NULL
```

## 9. `revenue_per_sqm` - выручка на м2 площади магазина, ₽/м2

Выручка магазина, делённая на `shops.area_sqm`. NULL-safe: у магазинов без заданной
площади метрика NULL (не делить на 0).

```sql
SUM(CASE WHEN o.status_id = 5 THEN o.amount ELSE 0 END)
  / NULLIF(sh.area_sqm, 0) AS revenue_per_sqm
-- база: FROM orders o
--        JOIN shops sh ON sh.id = o.shop_id AND sh.deleted_at IS NULL
--        WHERE o.parent_order_id IS NULL
--        GROUP BY o.shop_id, sh.area_sqm
```

---

## Как это связано с планами и сигналами

- `kpi_targets`: строка = `metric_key` + `shop_id` (+ `category_id`) + период
  (`period_start`/`period_end`) + `target_value`. «Светофор» (Task 8) сравнивает факт
  метрики с `target_value` по совпадению `metric_key` и `shop_id`.
- `shop_alert_rules`: строка = `metric_key` + `comparison` (`lt`/`gt`/`lte`/`gte`) +
  `threshold_value` + `recommended_action_text`. «Лента сигналов» (Task 8) оставляет
  строки, где факт метрики нарушает порог по `comparison`, и выводит `name` +
  `recommended_action_text`.

Для дашбордиста (если вернётся) и Task 8: любой новый порог/план заводится ТОЛЬКО с
`metric_key` из этой таблицы. Ключ, которого здесь нет, не сматчится ни с одним фактом.
