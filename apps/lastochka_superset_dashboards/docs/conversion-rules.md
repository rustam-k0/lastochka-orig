# Conversion Rules: адаптация SQL из BUILD_PLAN под реальную схему

Каждый датасет из `docs/analyticv2/ANALYTICS_BUILD_PLAN.md` (далее BUILD_PLAN) ОБЯЗАН
пройти через эти 11 правил перед записью в YAML. SQL из BUILD_PLAN писался под
допущения, часть которых оказалась неверной (проверено по коду и боевым БД, см.
`docs/analyticv2/OPEN_QUESTIONS_ANSWERS.md`). Ниже: правило, почему, пример «до -> после».

Диалекты: db1 `lastochka_djami_copy` и db2 `djami_store_copy` это MySQL 8; db3
`lastochka_analytics` это PostgreSQL 16.

---

## Правило 1. Выручка и завершённость: `status_id`, не `paid`

`paid = true` проставляется только по событию онлайн-оплаты из storefront. Заказы с
оплатой при получении (наличные/карта курьеру) остаются `paid = false` навсегда: на dev
из 221 заказа `paid` только у 12. Использовать `paid` как признак выручки нельзя.

Выручка = заказы со `status_id = 5` (доставлен/выдан). Отменённые `status_id = 6`
исключать. Таблица `statuses` ПУСТАЯ, джойнить её нельзя. Семантика статусов зашита в
коде: `1` создан, `2` на сборке, `3` собран, `4` в доставке, `5` доставлен, `6` отменён,
`7` готовится (кафе). При необходимости маппить CASE-ом.

```sql
-- До (BUILD_PLAN):
revenue_net = SUM(CASE WHEN paid THEN amount ELSE 0 END)

-- После:
revenue_net = SUM(CASE WHEN status_id = 5 THEN amount ELSE 0 END)
```

Метка статуса текстом:

```sql
CASE status_id
  WHEN 1 THEN 'Создан'  WHEN 2 THEN 'На сборке' WHEN 3 THEN 'Собран'
  WHEN 4 THEN 'В доставке' WHEN 5 THEN 'Доставлен' WHEN 6 THEN 'Отменён'
  WHEN 7 THEN 'Готовится' ELSE 'Неизвестно'
END AS status_name
```

## Правило 2. Сплит заказов: фильтр `parent_order_id IS NULL`

При сплите у дочерних подзаказов (`parent_order_id IS NOT NULL`) поле `amount`
ЗАПОЛНЕНО (сумма позиций своей зоны). На dev 106 из 221 заказов это дети. Без фильтра
выручка задваивается. Финансово реальный заказ это родитель.

Во всех финансовых и количественных order-level датасетах:

```sql
-- После: обязательное условие
WHERE o.parent_order_id IS NULL
```

Исключение: витрины по позициям во время активной сборки (позиции живут на детях и
синхронизируются на родителя при `AssemblyFinish::syncItemsToParent`) требуют отдельного
разбора, но для агрегатов по деньгам фильтр родителей обязателен.

## Правило 3. Позиция: итог = `price_during_order * quantity` (у `oi.price` СМЕШАННАЯ семантика)

`order_items.price` имеет **смешанную семантику** и НЕ пригоден для суммы выручки позиций:

- при создании заказа (`admin_back`) в `price` пишется цена **ЗА ЕДИНИЦУ** (`unit_price`);
- после сборки/замен (`delivery_back`, `OrderController` `'price' => $orderItem->price_during_order * $quantity`)
  `price` перезаписывается на **ИТОГ позиции** (`price_during_order * quantity`).

Поэтому в суммах **запрещены оба** варианта — и `SUM(oi.price)`, и `SUM(oi.price * oi.quantity)`:
на смешанных данных первый занижает собранные заказы, второй их задваивает-и-более
(эмпирика dev: `SUM(price*quantity)` по 28 доставленным заказам = 87211.70 против
`SUM(o.amount)` = 24540.84, раздувание в 3.55 раза).

**ЕДИНСТВЕННО ВЕРНАЯ формула итога позиции** — всегда через `price_during_order`
(поле заполнено на 578/578 позиций dev, стабильно за единицу):

```sql
-- До (неверно, оба варианта):
oi.price                 AS item_price_total   -- занижает собранные
oi.price * oi.quantity   AS item_price_total   -- задваивает собранные

-- После (единственно верно):
oi.price_during_order * oi.quantity AS item_price_total
```

Для **РЕАЛИЗОВАННОЙ выручки позиций** (доставленные заказы, `o.status_id = 5`)
дополнительно исключать отменённые при сборке позиции: `AND oi.status_id <> 3`
(`3` = `Status::ORDER_ITEM_CANCELED`) — это КАНОНИЧЕСКИЙ и ЕДИНСТВЕННО ДОПУСТИМЫЙ
фильтр реализованных позиций во всём проекте (round 2 ревью Task 8 закрыл разнобой,
см. ниже). Для метрик «весь спрос / заказано» (до сборки) cancel-фильтр не нужен —
решать по семантике виджета и фиксировать решение комментарием в датасете.

```sql
-- Реализованная выручка позиций (доставленные заказы):
SUM(CASE WHEN o.status_id = 5 AND oi.status_id <> 3
         THEN oi.price_during_order * oi.quantity ELSE 0 END) AS revenue_realized
```

**Симметрия revenue/COGS обязательна**: если рядом с реализованной выручкой считается
COGS позиций (`SUM(oi.quantity * p.cost_price)`), тот же фильтр `oi.status_id <> 3`
обязан стоять на ОБЕИХ ветках (revenue-leg и COGS-leg). Иначе COGS отменённых позиций
вычитается из выручки без них — маржа занижается (в round 1 Task 8 эта асимметрия
была допущена и замаскирована тем, что `products.cost_price` ещё пуст на dev, поэтому
`order_cogs = NULL` и баг не проявлялся численно).

Исключение: `replacement_price_delta` и подобные **разницы цен за единицу** — там
`price_during_order` (unit) БЕЗ `× quantity` уместен (`oi.price_during_order - op.price_during_order`);
сверять семантику виджета.

> Данные dev (2026-07): у 15 из 28 доставленных заказов есть позиции со
> `oi.status_id = 3` (`Status::ORDER_ITEM_CANCELED`), но `cancel_reason_id` проставлен
> лишь у 1 из них. Т.е. на текущих данных `cancel_reason_id IS NULL` вырезал бы почти
> ничего (реконсиляция с `amount` ~7-15%), тогда как `oi.status_id <> 3` исключает все
> 15 (реконсиляция ~2%). Поэтому **`cancel_reason_id IS NULL` для фильтра реализованных
> позиций больше НЕ используется нигде в проекте** — единственный допустимый вариант
> `status_id <> 3`. `cancel_reason_id`/`replace_reason_id` остаются полноценными
> колонками-сигналами (причина отмены/замены, точность сборки, дефицит ассортимента) —
> запрет касается только их использования как ФИЛЬТРА реализованной выручки.

Пункт 1 чек-листа валидации сверяет `orders.amount` против
`SUM(oi.price_during_order * oi.quantity)` (реализованные позиции), а не `SUM(oi.price)`
и не `SUM(oi.price * oi.quantity)`.

## Правило 4. Тип доставки: `orders.type_id`, не `shipping_method`

Реальный enum `shipping_method`: `own_courier`, `cdek`, `russian_post` (НЕ
`courier`/`pickup`/`rp`). Условие `shipping_method = 'courier'` не совпадёт никогда.

Тип доставки берётся из `orders.type_id`: `1` самовывоз, `2` курьерская доставка,
`3` СДЭК, `4` Почта России, `5` Яндекс Доставка.

SLA on-time (доставка в срок) считается только по доставочным типам:

```sql
-- До (BUILD_PLAN):
CASE WHEN o.shipping_method = 'courier' AND o.finished_at <= o.slot_end_date ...

-- После:
CASE
  WHEN o.type_id IN (2, 5) AND o.finished_at IS NOT NULL AND o.slot_end_date IS NOT NULL
    THEN CASE WHEN o.finished_at <= o.slot_end_date THEN 1 ELSE 0 END
END AS is_on_time_delivery
```

`shipping_method` для определения типа доставки НЕ использовать.

## Правило 5. Категории: JOIN через pivot `category_product`

Колонки `products.category_id` НЕ существует и не будет. Связь товар-категория это
many-to-many через pivot `category_product` (`category_id`, `product_id`, `position`).
Первая категория = минимальный `position`, при равном `position` (возможно несколько
строк pivot с одинаковым минимумом) - детерминированный tie-break по `category_id`.

```sql
-- До (BUILD_PLAN, несуществующая колонка):
LEFT JOIN products p ON p.id = oi.product_id
... p.category_id ...

-- ПОСЛЕ (устаревший вариант - НЕ использовать, недетерминирован при
-- нескольких строках pivot с одинаковым MIN(position): LEFT JOIN
-- расфанивается на несколько строк -> задвоение сумм при агрегации):
LEFT JOIN category_product cp
       ON cp.product_id = p.id
      AND cp.position = (
        SELECT MIN(cp2.position) FROM category_product cp2 WHERE cp2.product_id = p.id
      )

-- ПОСЛЕ (канонический вариант - коррелированный подзапрос гарантированно
-- возвращает ОДНУ строку: сортировка по position, затем по category_id
-- как детерминированный tie-break):
LEFT JOIN products p ON p.id = oi.product_id
LEFT JOIN category_product cp
       ON cp.product_id = p.id
      AND cp.category_id = (
            SELECT cp2.category_id
            FROM category_product cp2
            WHERE cp2.product_id = p.id
            ORDER BY cp2.position, cp2.category_id
            LIMIT 1
          )
LEFT JOIN categories c ON c.id = cp.category_id
... c.title AS category_name ...
```

## Правило 6. Analytics (db3, Postgres): колонка `store_id`, реальные event_type

Схема `analytics_events`: `id, event_id, event_type, occurred_at, source,
correlation_id, session_id, store_id (varchar), employee_id, role, order_id, client_id,
payload (jsonb)`. Колонки `shop_id` НЕТ, датасет с `ae.shop_id` упадёт. `store_id`
строковый.

Реальные `event_type` (проверено `SELECT DISTINCT`), а не допущения BUILD_PLAN:
- `order.stage_changed` (НЕ `order_stage.changed`, то routing key)
- `delivery.finished` (НЕ `delivery.completed`), `delivery.started|arrived|courier_assigned|distance_logged`
- `picking.started|cooking_started|item_assembled|item_replaced|item_not_found|item_scanned|finished`
- `shift.started|ended`
- B2C без префикса `b2c`: `cart.item_added`, `checkout.started`, `product.viewed`, `search.performed`, `app.opened`, `identity.linked`
- `order.created|updated|order_cancelled`
- Плюс платформенный шум wildcard-консьюмера (`category_1c.updated`, `product.stock_updated`, `product.updated`, `1c_exchange.updated`) - в продуктовых датасетах фильтровать.

```sql
-- До (BUILD_PLAN):
WHERE ae.event_type IN ('b2c.add_to_cart','b2c.cart_updated','b2c.checkout_started','order.created')
  ... ae.shop_id ...

-- После:
WHERE ae.event_type IN ('cart.item_added','checkout.started','order.created')
  ... ae.store_id AS store_id ...
```

Ключи `payload` (проверено на prod), примеры:
- `cart.item_added`: `product_id, quantity, session_id, device_client_id, client_id`
- `checkout.started`: `cart_total, items_count, session_id, device_client_id`
- `picking.finished`: `picker_id, assembled_items, replaced_items, cancelled_items, assembled_amount, total_weight, is_weight_partial, session_id`
- `delivery.distance_logged`: `payload->>'distance_m'` (единственный источник расстояния)

Извлечение из jsonb: `ae.payload->>'session_id'`, число: `(ae.payload->>'distance_m')::numeric`.

B2C-поток пока единичный (мобилка не выпущена). Воронки строить, но пустой результат
это норма, а не баг (см. правило 6 в отчётах: «пусто-ожидаемо»).

## Правило 7. Диалект MySQL (db1/db2): PostgreSQL-конструкции переписать

BUILD_PLAN написан в Postgres-стиле. Для db1/db2 (MySQL 8) переписать:

| Postgres (до) | MySQL (после) |
|---|---|
| `EXTRACT(EPOCH FROM (a - b))` | `TIMESTAMPDIFF(SECOND, b, a)` |
| `date_trunc('day', x)` | `DATE(x)` |
| `date_trunc('month', x)` | `DATE_FORMAT(x, '%Y-%m-01')` |
| `COUNT(*) FILTER (WHERE c)` | `SUM(CASE WHEN c THEN 1 ELSE 0 END)` |
| `true` / `false` | `1` / `0` |
| `val::numeric` / `val::int` | касты убрать (или `CAST(val AS DECIMAL)`) |

`NULLIF` доступен в обоих. db3 (Postgres) диалект из BUILD_PLAN в основном оставлять.

```sql
-- До (BUILD_PLAN, Postgres):
EXTRACT(EPOCH FROM (o.created_at - c.created_at)) / 3600.0 AS hours_since_client_created

-- После (MySQL):
TIMESTAMPDIFF(SECOND, c.created_at, o.created_at) / 3600.0 AS hours_since_client_created
```

```sql
-- Разбивка по месяцам, До:  date_trunc('month', o.created_at)
-- После (MySQL):           DATE_FORMAT(o.created_at, '%Y-%m-01') AS order_month
```

## Правило 8. TZ-ADD поля теперь СУЩЕСТВУЮТ, использовать (снимает часть blocked)

Эти поля добавлены бэкендом (эпик feat/analytics-data-requirements, уже на dev и в
копиях). Виджеты, которые BUILD_PLAN пометил blocked из-за их отсутствия, теперь строим:

- `orders.zone_id` -> JOIN `shop_zones z ON z.id = o.zone_id` для имени зоны. Больше НЕ
  price-matching хак (`z.price = o.delivery_price`), который был в `money_zone_profit`.
- `products.cost_price` -> COGS и маржа. NULL-safe: пока значения не приехали из 1С,
  `cost_price IS NULL`, метрики маржи будут NULL. НЕ подменять нулями (иначе маржа = 100%).
- `shops.area_sqm` -> выручка на м2.
- `store_operating_costs` -> опекс за период. Реальные колонки: `shop_id`
  (NULL = вся сеть), `category` (напр. `rent`), `rule_type`, `value`, `period_start`,
  `period_end` (NULL = бессрочно), `is_active`, `deleted_at`. `rule_type` принимает
  `fixed_amount` (в данных на dev есть демо-строка rent/fixed_amount/50000) и
  `pct_of_revenue` (процент от оборота: `value / 100 * выручка периода`). SoftDeletes.
- `kpi_targets` -> план для «факт vs план». Реальные колонки: `metric_key`, `shop_id`,
  `category_id`, `period_start`, `period_end`, `target_value`, `is_active`, `deleted_at`
  (правило 11 связывает по `metric_key`).
- `shop_alert_rules` -> «Лента сигналов». Реальные колонки: `name`, `metric_key`,
  `comparison`, `threshold_value`, `recommended_action_text`, `shop_id`, `is_active`,
  `position`, `deleted_at` (правило 11).

```sql
-- Прибыль по зонам, До (BUILD_PLAN, ненадёжный price-match):
LEFT JOIN shop_zones z ON z.shop_id = o.shop_id AND z.price = o.delivery_price

-- После (правило 8):
LEFT JOIN shop_zones z ON z.id = o.zone_id AND z.deleted_at IS NULL
```

```sql
-- Валовая прибыль/маржа, NULL-safe (cost_price ещё пустой):
SUM(oi.quantity * p.cost_price)                              AS cogs,        -- NULL пока нет 1С
SUM(CASE WHEN o.status_id = 5 THEN o.amount ELSE 0 END)
  - SUM(oi.quantity * p.cost_price)                          AS gross_profit -- NULL-safe
```

Опекс с процентным правилом:

```sql
SUM(CASE
      WHEN soc.rule_type = 'fixed_amount'    THEN soc.value
      WHEN soc.rule_type = 'pct_of_revenue'  THEN soc.value / 100.0 * period_revenue
      ELSE 0
    END) AS opex
FROM store_operating_costs soc
WHERE soc.deleted_at IS NULL AND soc.is_active = 1
  -- период правила пересекается с отчётным: (period_end IS NULL OR period_end >= ...)
```

## Правило 9. Справочники SoftDeletes: `deleted_at IS NULL`

Все новые справочники (`store_operating_costs`, `kpi_targets`, `shop_alert_rules`,
`shop_zones`, `shops`, `categories` и т.п. где есть колонка) используют SoftDeletes.
Обязательно `deleted_at IS NULL`. Где по смыслу нужны только активные записи, добавить
`is_active = 1`.

```sql
-- После:
WHERE sar.deleted_at IS NULL AND sar.is_active = 1
```

## Правило 10. Доставка в выручке, не в расходах

`orders.delivery_price` и `shop_zones.delivery_price` это цена доставки для покупателя,
то есть ЧАСТЬ ВЫРУЧКИ, а не расход бизнеса. «Минус доставка» из макета P&L напрямую не
считается. Фактическая себестоимость логистики (ФОТ курьера, топливо) в БД отсутствует.

Единственное исключение: у заказов через Яндекс Доставку есть `orders.yandex_delivery_price`
(сумма, которую бизнес платит Яндексу) - для этих заказов расход логистики известен.

```sql
-- delivery_price трактовать как выручку:
SUM(CASE WHEN o.status_id = 5 THEN o.delivery_price ELSE 0 END) AS delivery_revenue
-- реальный логистический расход только для Яндекса:
SUM(o.yandex_delivery_price) AS yandex_logistics_cost
```

## Правило 11. Словарь `metric_key` (kpi_targets / shop_alert_rules)

Планы (`kpi_targets`) и правила сигналов (`shop_alert_rules`) ссылаются на метрику по
строковому ключу `metric_key`. «Факт vs план» и «Лента сигналов» джойнят фактические
метрики к плану/порогу по этому ключу. Канонический словарь с SQL-формулами:
`docs/metric-keys.md` (9 ключей). Имена ключей менять нельзя, они матчатся строкой.

```sql
-- Пример матча факта к порогу правила (Лента сигналов, Task 8):
JOIN shop_alert_rules sar
  ON sar.metric_key = fact.metric_key         -- строковый ключ из metric-keys.md
 AND sar.deleted_at IS NULL AND sar.is_active = 1
WHERE CASE sar.comparison
        WHEN 'lt'  THEN fact.metric_value <  sar.threshold_value
        WHEN 'gt'  THEN fact.metric_value >  sar.threshold_value
        WHEN 'lte' THEN fact.metric_value <= sar.threshold_value
        WHEN 'gte' THEN fact.metric_value >= sar.threshold_value
      END
-- колонки правила: sar.name, sar.recommended_action_text выводятся в ленту
```

---

## Чек-лист конверсии (прогнать по каждому датасету перед записью YAML)

1. [ ] Выручка/завершённость через `status_id = 5`, отменённые `status_id = 6` исключены; `statuses` НЕ джойнится (правило 1).
2. [ ] В финансовых/количественных order-датасетах есть `WHERE o.parent_order_id IS NULL` (правило 2).
3. [ ] Итог позиции = `oi.price_during_order * oi.quantity` (НЕ `oi.price` и НЕ `oi.price * oi.quantity` - у `oi.price` смешанная семантика); для реализованной выручки + `AND oi.status_id <> 3` (канонический фильтр, симметрично на COGS-ноге если она считается рядом) (правило 3).
4. [ ] Тип доставки через `type_id`, SLA по `type_id IN (2,5)`; нет `shipping_method = 'courier'` (правило 4).
5. [ ] Категории через pivot `category_product` + `categories`, нет `products.category_id`; tie-break при равном `position` - коррелированный подзапрос по `category_id`, не `MIN(position)` в JOIN (правило 5).
6. [ ] db3: `store_id` вместо `shop_id`; event_type из реального списка; `payload->>'...'` для jsonb (правило 6).
7. [ ] db1/db2 (MySQL): нет `EXTRACT(EPOCH)`, `date_trunc`, `FILTER(WHERE)`, `true/false`, `::` кастов (правило 7).
8. [ ] Новые поля используются где применимо; `cost_price` NULL-safe, не подменён нулями (правило 8).
9. [ ] У всех справочников `deleted_at IS NULL`; активные `is_active = 1` (правило 9).
10. [ ] `delivery_price` не вычитается как расход; логистика только `yandex_delivery_price` (правило 10).
11. [ ] Метрики для планов/сигналов используют ключи из `docs/metric-keys.md` (правило 11).
12. [ ] shop_id / store_id / даты оставлены КОЛОНКАМИ (не зашиты константой) для native-фильтров.
13. [ ] uuid датасета сгенерирован один раз (uuid4) и не меняется при последующих правках.
