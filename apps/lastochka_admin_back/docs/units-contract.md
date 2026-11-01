# Единицы количества: правило для всех модулей

Одно и то же число в системе бывает в пяти разных единицах. Ошибки «12 гр. вместо 12 кг»,
«списали 3 кг вместо 900 г», «Огурцы 1 г» и подобные — всегда одна причина: код угадал
единицу числа. Здесь записано, где какая единица, и где лежит единственная функция перевода.

## Три класса товаров (данные прода, октябрь 2026)

| Класс | Пример | `base_unit_type_id` | `info_weight_type_id` / `info_weight` | Кол-во на проде |
|---|---|---|---|---|
| A — весовой с фасовкой | «Филе грудки», порция 300 г | 3 (кг) | 2 (г) / 300 | 982 |
| B — весовой без фасовки | картофель, порция 1 кг | 3 (кг) | 3 (кг) / NULL | 179 |
| C — штучный | сыр | 1 (шт) | 1 (шт) / NULL | 13 808 |

`info_weight_type_id` — единица **порции**, а не товара. С фасовкой (LASTOCHKI-JAMI-11200)
её ставит `CategoryQuantumService`, базовая единица 1С хранится отдельно в `base_unit_type_id`.

## Где какая единица

| Число | Модуль | Единица | Пример (класс A) |
|---|---|---|---|
| `remains.quantity` | курьерка (admin/delivery/purchase) | базовая единица 1С: кг/л/шт, дробная | 12.806 |
| `order_items.quantity` | курьерка | порции | 3 (= 900 г) |
| `order_items.ordered_quantity` | курьерка | порции, заказанные клиентом (до пересчёта сборкой) | 3 |
| `actual_weight` | курьерка, витрина | наименьшая единица: г/мл, итог строки | 528 |
| `prices.value` | курьерка | цена порции | 178.20 |
| `prices.price_per_small_unit` | курьерка | цена за г/мл/шт, 6 знаков | 0.594 |
| `prices.price_per_kg` | курьерка | цена за кг | 594 |
| `prices.price_without_discount` | курьерка | старая цена акции **за базовую единицу** (как пришла из 1С) | 650 |
| `products.quantity` (stock) | витрина | наименьшая единица: г/мл/шт | 12806 |
| `cart_items.quantity` | витрина | единица корзины: г/мл/шт; у товара «по кг/л» без фасовки — кг/л | 900; у класса B — 1 |
| `order_items.quantity` | витрина | наименьшая единица, `measurement_unit` = gram/milliliter/piece | 900 |
| `Product.remain.quantity` | GraphQL `/graphql-delivery` (мобилка сборщика) | единица порции (`info_weight_type_id`) | 12806 |
| `quantity` в `order.*` событии | шина | порции; у строки `_origin` (replaced) — наименьшая единица | 3; 900 |
| `portion_size` в `order.*` событии | шина | размер порции в г/мл/шт | 300 |
| `quantity` в `product.*` событии | шина | базовая единица 1С; `unit_type` kg/l → витрина ×1000 | 12.806 |

## Функции перевода — только через них

- Курьерка: `App\Support\StockUnits` в admin_back, delivery_back, purchase_back (одинаковый API):
  `stockUnit`, `sizeInSmallest`, `portionInSmallest`, `portionsToStock`, `smallestToStock`,
  `collectedToStock`, `stockToPortionUnit`; в admin_back ещё `basePriceToPortion`, `lineWeightGrams`.
- Витрина: `Product::stockUnitsPerCartUnit()`, `cartQuantityInStockUnits()`, `minSaleStock()`,
  `minSaleStockSql()` (порог наличия), `OrderItemPortions::storedFromCart()` / `fromStored()`,
  `ProductPriceResolver::lineTotalForQuantity()`.
- 1c_parser: `OrderEventTransformer::buildOrderItemRow($item, $product)` — количество в единице
  товара 1С; `ProductEventTransformer::measurementUnitOf()`.

## Правила

1. Любое движение остатка (списание, возврат, резерв) — через перевод в единицу остатка.
   Порции и граммы напрямую из `remains.quantity` не вычитать.
2. Количество и единицу писать только парой: если пишешь `quantity` из события как есть, пиши
   и единицу, в которой его прислали (MID-267).
3. Сравнение «остаток ≥ количество» — после перевода к одной единице (на витрине:
   `cartQuantityInStockUnits`, порог — `minSaleStock`).
4. Цена порции считается от сырой цены без промежуточного округления цены за грамм.
5. Весовой товар определять по единице (`isWeight`/`isWeightBased`, `measurement_unit`), а не по
   наличию `actual_weight` и не по `unit_type_id` (у 1С-товаров он ничего не говорит).
6. Новое поле с количеством — сразу записать сюда, в какой оно единице.
