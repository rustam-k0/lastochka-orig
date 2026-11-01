# Итоговый GAP-анализ и Вопросы

## Сводная таблица расхождений

### ✅ Решённые

| № | Область | Было | Решение |
|---|---------|------|---------|
| 1 | Товары | КБЖУ отсутствуют в Exchange | ✅ Парсить из `attributes[]` по code |

### 🔴 Критические (блокируют разработку)

| № | Область | Проблема | Решение |
|---|---------|----------|---------|
| 2 | Товары | **Только 1 изображение** в Exchange, API требует массив | Расширить Exchange: `images[]` |
| 3 | Статусы заказа | **Разные enum**: API (4 статуса) vs Exchange (7 статусов) | Определить маппинг |
| 4 | Адреса | **Разная структура**: API (раздельные поля) vs Exchange (одна строка) | Хранить оба формата |
| 5 | Слоты доставки | **API слишком простой**: нет min_order, shipping_price | Расширить ответ API |
| 6 | Платежи | **addCard() — mock**: нет реальной интеграции | Выбрать эквайринг |

### 🟡 Важные (требуют уточнения)

| № | Область | Проблема | Вопрос |
|---|---------|----------|--------|
| 7 | Профиль | `patronymic` есть в Exchange, нет в API | Добавить в API? |
| 8 | Профиль | `gender: other` есть в API, нет в Exchange | Убрать из API? |
| 9 | Категории | Нет изображения категории в API | Есть в дизайне? |
| 10 | Магазины | `rating` — откуда брать? | Есть отзывы? |
| 11 | Магазины | `image` — откуда брать? | Загрузка через админку? |
| 12 | Заказы | `promocode` — нет в Exchange | Где хранить? |
| 13 | Заказы | `comment` — нет в Exchange | Добавить? |
| 14 | Часы работы | API: одно время, Exchange: по дням недели | Какой формат нужен? |
| 15 | КБЖУ | Передаются через attributes | Какие `code` используются? |

### 🟢 Решаемые (маппинг на бэке)

| № | Область | API | Exchange | Маппинг |
|---|---------|-----|----------|---------|
| 15 | ID | int | string | external_id |
| 16 | Имя | firstName | name | Прямой |
| 17 | Фамилия | lastName | surname | Прямой |
| 18 | Название | name/title | title | Прямой |
| 19 | Квартира | apartment | flat | Прямой |
| 20 | Цена | price/priceOld | price/discount_price | Инверсия |

---

## Полный список вопросов

### Бизнес-вопросы

1. **Откуда брать данные о пищевой ценности (КБЖУ)?**
   - Вводятся в CRM вручную?
   - Есть внешний справочник?
   - Нужно расширить Exchange?

2. **Будет ли у товара несколько фотографий?**
   - Если да — расширить Exchange
   - Если нет — упростить API до одного image

3. **Какие статусы заказа видит клиент?**
   - Нужны ли "Собирается", "Собран"?
   - Или достаточно "В обработке", "В доставке", "Доставлен"?

4. **Есть ли функционал отзывов?**
   - Экран ОТЗЫВЫ в дизайне — что там?
   - Если есть — откуда rating магазина?

5. **Как работают промокоды?**
   - Где хранятся?
   - Как валидируются?
   - Синхронизируются с CRM?

6. **Какой эквайринг будет использоваться?**
   - CloudPayments / ЮKassa / Тинькофф?
   - Рекуррентные платежи нужны?

7. **Нужно ли отчество в профиле?**
   - Есть в дизайне?

8. **Как определяется зона доставки?**
   - По координатам через DeliverySystem?
   - Нужен ли геокодинг адресов?

### Технические вопросы

9. **Формат ID между системами?**
   - API: int, Exchange: string
   - Рекомендация: хранить external_id

10. **Формат адреса при синхронизации?**
    - API → Exchange: конкатенировать поля
    - Exchange → API: парсить или хранить как есть?

11. **Как хранить часы работы магазина?**
    - Полный schedule по дням?
    - Или упрощённый формат?

12. **Нужна ли синхронизация корзины?**
    - Сейчас корзина локальная
    - Нужно ли сохранять в CRM?

13. **Как обрабатывать soft delete?**
    - deleted_at в Exchange
    - Скрывать или удалять на бэке?

---

## Расширения Exchange (рекомендации)

### Товары (products)
```diff
+ images: [
+   {url: "...", sort_order: 0},
+   {url: "...", sort_order: 1}
+ ]
+ composition: "string"
+ proteins: number
+ fats: number
+ carbohydrates: number
+ calories: number
```

### Заказы (orders)
```diff
+ promocode: {
+   code: "string",
+   discount_amount: number,
+   discount_percent: number
+ }
+ comment: "string"
```

### Адреса (addresses)
```diff
+ comment: "string"
```

---

## Расширения API (рекомендации)

### User
```diff
+ patronymic: String?
+ external_id: String?
```

### Address
```diff
+ title: String? // "Дом", "Работа"
+ latitude: double?
+ longitude: double?
+ fullAddress: String?
```

### Category
```diff
+ image: Image?
```

### Product
```diff
+ external_id: String?
+ quantity: int? // остатки
+ unit_type: String? // kg/piece
+ manufacturer: String?
+ brand: String?
+ weight: int? // граммы
```

### Store
```diff
+ code: String // shop_code
+ latitude: double?
+ longitude: double?
+ freeDeliveryFrom: int?
+ deliveryPrice: int?
```

### DeliverySlot (расширенный ответ)
```diff
{
  "slots": [...],
+ "minOrderAmount": int,
+ "freeDeliveryFrom": int,
+ "deliveryPrice": int
}
```

### Order
```diff
+ external_id: String?
+ isPaid: bool
+ paymentType: String // cash/card
+ shippingMethod: String
+ trackingNumber: String?
```

---

## Маппинг статусов заказов

| Exchange | API | Описание для клиента |
|----------|-----|---------------------|
| created | pending | "Заказ создан" |
| assembling | pending | "Заказ обрабатывается" |
| assembled | pending | "Заказ готов к доставке" |
| in_delivery | inDelivery | "В пути" |
| delivered | delivered | "Доставлен" |
| cancelled | cancelled | "Отменён" |
| returned | cancelled | "Возврат" (или новый статус) |

**Альтернатива:** Расширить API enum:
```
pending | assembling | assembled | inDelivery | delivered | cancelled | returned
```

---

## Приоритеты реализации

### Фаза 1: MVP (без изменений Exchange)
- [ ] Авторизация по телефону
- [ ] Профиль (без patronymic)
- [ ] Категории и товары (без КБЖУ)
- [ ] Корзина (локально)
- [ ] Заказы (базовые статусы)
- [ ] Адреса (раздельные поля)

### Фаза 2: Интеграции
- [ ] RabbitMQ consumers/producers
- [ ] Маппинг данных Exchange ↔ API
- [ ] DeliverySystem API
- [ ] Эквайринг

### Фаза 3: Расширения
- [ ] КБЖУ товаров
- [ ] Множественные фото
- [ ] Полный schedule магазинов
- [ ] Отзывы и рейтинги
- [ ] Промокоды

---

## Следующие шаги

1. **Согласовать с заказчиком** критические вопросы (КБЖУ, фото, статусы)
2. **Определить приоритеты** — что нужно для MVP
3. **Согласовать расширения Exchange** с командой интеграций
4. **Выбрать эквайринг** и получить доступы
5. **Получить доступ к DeliverySystem API** — URL, авторизация
6. **Уточнить дизайн** — детально изучить каждый экран
