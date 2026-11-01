# Вопросы: Что не доработано в API

> На основе GAP-анализа между требованиями Flutter-клиента, Exchange-протоколом и текущей спецификацией API.

---

## 🔴 Критические недоработки (блокируют интеграцию)

### 1. User — отсутствуют поля для синхронизации

| Поле | Зачем нужно | Вопрос |
|------|-------------|--------|
| `patronymic` | Exchange передаёт отчество | **Добавлять в API? Есть в дизайне?** |
| `external_id` | Связь с ID в RetailCRM | Обязательно для синхронизации |
| `source` | Канал создания (bagisto/crm) | Для трекинга источника |

**Вопрос:** Нужно ли поле отчества в мобильном приложении?

---

### 2. Address — критическое несоответствие структуры

| Текущий API | Exchange | Проблема |
|-------------|----------|----------|
| `country`, `city`, `street`, `houseNumber` | `address` (одна строка) | **Полное несоответствие** |
| ❌ | `latitude`, `longitude` | Нет координат |
| ❌ | `title` ("Дом", "Работа") | Нет названия адреса |

**Вопросы:**
1. Нужны ли координаты для расчёта зоны доставки?
2. Будет ли геокодирование адресов (DaData/Яндекс)?
3. Как конвертировать раздельные поля в одну строку для Exchange?

---

### 3. Category — отсутствует изображение

| API | Exchange | Дизайн |
|-----|----------|--------|
| ❌ image | ✅ image_url | ❓ |

**Вопрос:** В дизайне категории отображаются с иконками? Если да — добавить `image: Image?` в Category.

---

### 4. Product — отсутствуют критичные поля

| Поле | Назначение | Статус |
|------|------------|--------|
| `external_id` | Связь с CRM | ❌ Нет |
| `quantity` | Остатки товара | ❌ Нет |
| `unit_type` | kg/piece | ❌ Нет |
| `manufacturer` | Производитель | ❌ Нет |
| `brand` | Бренд | ❌ Нет |
| `weight` | Вес в граммах | ❌ Нет |
| `category_id` | Связь с категорией | ❌ Нет в модели |
| `shop_code` | Привязка к магазину | ❌ Нет |

**Вопросы:**
1. Показывать ли остатки клиенту?
2. Нужен ли фильтр по бренду/производителю?

---

### 5. Store — недостающие поля

| Поле | Назначение | Статус |
|------|------------|--------|
| `code` | shop_code для Exchange | ❌ Нет |
| `latitude`, `longitude` | Координаты магазина | ❌ Нет |
| `freeDeliveryFrom` | Бесплатная доставка от суммы | ❌ Нет |
| `deliveryPrice` | Стоимость доставки | ❌ Нет |
| `slotDuration` | Размер слота в минутах | ❌ Нет |

**Вопросы:**
1. Откуда брать `image` магазина? Загрузка через админку?
2. Откуда брать `rating`? Есть функционал отзывов?

---

### 6. WorkingHours — упрощённая структура

**Текущий API:**
```json
{
  "openingHour": 9,
  "openingMinute": 0,
  "closingHour": 21,
  "closingMinute": 0
}
```

**Exchange передаёт расписание по дням:**
```json
{
  "monday": {"start": "10:00", "end": "20:00"},
  "saturday": {"start": "11:00", "end": "18:00"},
  "sunday": null
}
```

**Вопрос:** Нужен ли клиенту полный график по дням недели? Или достаточно "сегодня открыто до XX:XX"?

---

### 7. DeliverySlot — слишком простая модель

**Текущий API:**
```json
{
  "id": 1,
  "timeSlot": "10:00-12:00"
}
```

**DeliverySystem возвращает:**
```json
{
  "slots": [...],
  "min_order_price": 500,
  "shipping_free_from": 1500,
  "shipping_price": 200
}
```

**Вопросы:**
1. Расширить ответ `getDeliverySlots` дополнительными полями?
2. Или создать отдельный endpoint `/stores/{id}/delivery-info`?

---

### 8. Order — недостающие поля для Exchange

| Поле | Назначение | Статус |
|------|------------|--------|
| `external_id` | ID в CRM | ❌ Нет |
| `isPaid` | Статус оплаты | ❌ Нет |
| `paymentType` | cash/card | ❌ Нет (только paymentCard) |
| `shippingMethod` | courier/pickup/cdek | ❌ Нет |
| `trackingNumber` | Трек-номер доставки | ❌ Нет |

**Вопрос:** Нужен ли клиенту трек-номер для отслеживания?

---

### 9. OrderStatus — несоответствие enum

| API | Exchange | 
|-----|----------|
| `pending` | `created`, `assembling`, `assembled` |
| `inDelivery` | `in_delivery` |
| `delivered` | `delivered` |
| `cancelled` | `cancelled`, `returned` |

**Вопросы:**
1. Нужны ли клиенту статусы "Собирается" / "Собран"?
2. Что делать со статусом "Возврат"?

---

### 10. PaymentCard — mock-реализация

```
addCard() — mock
```

**Вопросы:**
1. Какой эквайринг будет использоваться? (CloudPayments / ЮKassa / Тинькофф)
2. Нужны ли рекуррентные платежи?
3. Поддерживается ли оплата наличными?

---

## 🟡 Менее критичные вопросы

### 11. Gender enum

API поддерживает `other`, Exchange только `male/female`.

**Вопрос:** Убрать `other` из API или добавить в Exchange?

---

### 12. Промокоды

API поддерживает `CartPromocode` и `OrderPromocode`, но в Exchange нет промокодов.

**Вопросы:**
1. Где хранятся промокоды?
2. Как валидируются?
3. Нужна ли синхронизация с CRM?

---

## Сводка: что добавить в API

### User
```diff
+ patronymic: String?
+ external_id: String?
+ source: String?
```

### Address
```diff
+ title: String?
+ latitude: double?
+ longitude: double?
+ fullAddress: String?
+ external_id: String?
```

### Category
```diff
+ image: Image?
+ external_id: String?
```

### Product
```diff
+ external_id: String?
+ quantity: int?
+ unit_type: String?
+ manufacturer: String?
+ brand: String?
+ weight: int?
```

### Store
```diff
+ code: String
+ latitude: double?
+ longitude: double?
+ freeDeliveryFrom: int?
+ deliveryPrice: int?
```

### Order
```diff
+ external_id: String?
+ isPaid: bool
+ paymentType: String
+ shippingMethod: String?
+ trackingNumber: String?
```

### DeliverySlot response
```diff
{
  "slots": [...],
+ "minOrderAmount": int,
+ "freeDeliveryFrom": int,
+ "deliveryPrice": int
}
```
