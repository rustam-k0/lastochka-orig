# Мобилка (client_mobile): показ «за N шт» для штучных товаров с шагом > 1

**Дата:** 2026-06-15
**Приложение:** `lastochka_client_mobile`
**Тип:** правка отображения (UI), контракт ручек НЕ меняется

## Контекст

Бэкенд теперь поддерживает продажу **штучных** товаров порциями (например, «по 10 штук»):
REST API (`GET /api/v1/products/{id}`, корзина, заказы) отдаёт `quantityStep > 1`
при `measurementUnit = "piece"` (`measurementUnitLabel = "шт"`).

Вся остальная логика мобилки уже работает корректно (проверено):
- инкремент/декремент количества по `quantityStep` (не по 1), первое добавление = `quantityStep`;
- цена строки = `price × (quantity / quantityStep)`;
- `quantity` хранится в наименьших единицах (штуках, кратно шагу);
- в каталожной карточке и в корзине показывается «10 шт», «20 шт».

## Что нужно поправить

Подпись «цена за N шт» (`pricePerUnitLabel`) сейчас выводится только если товар «весовой»
(`isWeighted`, т.е. `measurementUnit != 'piece'`). Для **штучного товара со `step > 1`**
на двух экранах эта подпись не показывается — цена выглядит без указания «за сколько штук»:

1. **Страница товара** — `lib/.../product/.../product_page.dart` (≈ строки 196-198):
   условие `product.isWeighted ? product.pricePerUnitLabel : null` —
   показывать `pricePerUnitLabel` также при `measurementUnit == 'piece' && quantityStep > 1`.

2. **Кнопка «В корзину»** — `lib/.../product_bottom_bar.dart` (≈ строки 186-188):
   `product.isWeighted ? '<totalPrice> / <pricePerUnitLabel>' : <totalPrice>` —
   аналогично показывать `/ <pricePerUnitLabel>` для штучных со `step > 1`.

Каталожная карточка (`cardUnitLabel`) и корзина (`displayQuantity`) — **уже корректны**, менять не нужно.

## Предлагаемый критерий

Ввести признак «продаётся порциями» вместо `isWeighted` на этих двух экранах:

```dart
bool get isSoldByPortion => isWeighted || quantityStep > 1;
```

и использовать его для показа `pricePerUnitLabel`.
