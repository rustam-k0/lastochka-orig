# Различия между api_docs.md и текущей реализацией

> Документ создан: 2026-01-28
> Цель: Фиксация расхождений между спецификацией Flutter-клиента и текущим состоянием бэкенда.

---

## 1. Общие различия

### 1.1 Формат денежных сумм

| Спецификация | Реализация |
|--------------|------------|
| `int` в рублях (100 = 100₽) | `int` в рублях (соответствует) |

**Статус:** ✅ Соответствует

### 1.2 Формат DateTime

| Спецификация | Реализация |
|--------------|------------|
| ISO 8601 (`"2024-01-15T10:30:00.000Z"`) | `toIso8601String()` |

**Статус:** ✅ Соответствует

---

## 2. Различия в Enums

### 2.1 Gender

| Спецификация | Реализация |
|--------------|------------|
| `male \| female \| other` | `male \| female` |

**Статус:** ⚠️ Отсутствует значение `other`

### 2.2 OrderStatus

| Спецификация | Реализация |
|--------------|------------|
| `pending \| inDelivery \| delivered \| cancelled` | `pending \| assembling \| assembled \| inDelivery \| delivered \| cancelled` |

**Статус:** ⚠️ Добавлены `assembling` и `assembled`

---

## 3. Различия в моделях данных

### 3.1 Store

**Спецификация:**
```json
{
  "id": 1,
  "name": "Магазин №1",
  "minOrderAmount": 500,
  "workingHours": { ... },
  "isActive": true,
  "image": { ... },
  "rating": 4.5
}
```

**Реализация соответствует:** ✅

### 3.2 OrderAddress

**Спецификация:**
```json
{
  "originalId": 1,
  "country": "Россия",
  ...
}
```

**Реализация:**
```json
{
  "id": 1,
  "country": "Россия",
  "fullAddress": "...",
  "latitude": ...,
  "longitude": ...,
  ...
}
```

**Статус:** ⚠️ Различия:
- Нет `originalId` → есть `id`
- Добавлено: `fullAddress`, `latitude`, `longitude`, `entrance`, `intercom`

### 3.3 OrderPromocode

**Спецификация:**
```json
{
  "originalId": 1,
  "code": "PROMO10",
  "percent": 10
}
```

**Реализация:**
```json
{
  "id": 1,
  "code": "PROMO10",
  "type": "percent",
  "value": 10,
  "discountAmount": 50
}
```

**Статус:** ⚠️ Различия:
- Нет `originalId` → есть `id`
- Нет `percent` → есть `type` + `value`
- Добавлено: `discountAmount`

### 3.4 CartPromocode

**Спецификация:**
```json
{
  "id": 1,
  "code": "PROMO10",
  "percent": 10
}
```

**Реализация:**
```json
{
  "id": 1,
  "code": "PROMO10",
  "percent": 10  // только для type=percent, иначе null
}
```

**Статус:** ✅ Соответствует (для процентных промокодов)

### 3.5 Order

**Спецификация:**
```json
{
  ...
  "paymentCard": { ... }
}
```

**Реализация:**
```json
{
  ...
  "isPaid": false,
  "paymentType": "cash",
  "shippingMethod": "courier"
}
```

**Статус:** 🔴 Критические различия:
- Нет `paymentCard` — система карт не реализована
- Добавлено: `isPaid`, `paymentType`, `shippingMethod`

---

## 4. Отсутствующие эндпоинты

### 4.1 PaymentCardRepository

❌ **Полностью отсутствует!**

Согласно спецификации должны быть:
- `GET /api/v1/payment-cards` — getCards
- `POST /api/v1/payment-cards` — addCard
- `PUT /api/v1/payment-cards/{id}/active` — setActiveCard
- `DELETE /api/v1/payment-cards/{id}` — deleteCard

**Статус:** 🔴 CRITICAL — Flutter-клиент ожидает эти эндпоинты

### 4.2 DeliverySlot эндпоинт

**Спецификация:** `getDeliverySlots(storeId)` → `DeliverySlot[]`

**Реализация:** `GET /api/v1/stores/{store}/delivery-slots`

**Статус:** ✅ Соответствует (реализовано в `DeliveryController`)

---

## 5. Дополнительные эндпоинты (отсутствуют в спецификации)

Следующие эндпоинты реализованы, но **не описаны** в `api_docs.md`:

### 5.1 Stories
- `GET /api/v1/stories` — список историй
- `GET /api/v1/stories/{story}` — детали истории

### 5.2 Banners
- `GET /api/v1/stores/{store}/banners` — баннеры магазина

### 5.3 Promotions (Акции)
- `GET /api/v1/promotions` — список акций
- `GET /api/v1/promotions/{promotion}` — детали акции
- `GET /api/v1/promotions/{promotion}/products` — товары акции

### 5.4 Product Groups (Товарные группы)
- `GET /api/v1/stores/{store}/product-groups` — группы магазина
- `GET /api/v1/product-groups/{productGroup}` — детали группы
- `GET /api/v1/product-groups/{productGroup}/products` — товары группы

### 5.5 Reviews (Отзывы)
- `GET /api/v1/stores/{store}/reviews` — отзывы магазина
- `POST /api/v1/stores/{store}/reviews` — создать отзыв
- `PUT /api/v1/stores/{store}/reviews` — обновить отзыв
- `GET /api/v1/stores/{store}/reviews/my` — мой отзыв

- `GET /api/v1/products/{product}/reviews` — отзывы товара
- `POST /api/v1/products/{product}/reviews` — создать отзыв
- `PUT /api/v1/products/{product}/reviews` — обновить отзыв
- `GET /api/v1/products/{product}/reviews/my` — мой отзыв

---

## 6. Различия в API Request/Response

### 6.1 phoneLogin Response

**Спецификация (api_docs.md):**
```
POST /auth/phone-login
Request:  { "phone": "+79991234567" }
Response: "1234"  // String — OTP код напрямую
```

**Реализация (AuthController.php:28-47):**
```json
// POST /api/v1/auth/phone-login
// Request:  { "phone": "+79991234567" }
// Response:
{
  "message": "OTP sent",
  "expiresIn": 300,
  "code": "1234"  // ⚠️ только в local/testing окружении!
}
```

**Статус:** ⚠️ Структура отличается

**Детали различий:**

| Аспект | Спецификация | Реализация |
|--------|--------------|------------|
| Тип ответа | `String` | `Object` |
| Код в ответе | Всегда | Только в dev |
| Поле message | Нет | Есть |
| Поле expiresIn | Нет | Есть (время жизни OTP в секундах) |

**Влияние на Flutter:**
```dart
// Спецификация ожидает:
final String code = response.body;

// Реализация требует:
final Map<String, dynamic> data = jsonDecode(response.body);
final String? code = data['code'];  // null в production!
final int expiresIn = data['expiresIn'];
```

**Рекомендация:**
- В production код **не возвращается** (отправляется по SMS)
- Flutter должен показывать таймер на `expiresIn` секунд
- Поле `message` можно использовать для уведомления пользователя

### 6.2 createOrder Request

**Спецификация:**
```
storeId, addressId, paymentCardId, deliverySlotId, comment
```

**Реализация:**
```
storeId, addressId, deliverySlotId, comment, idempotencyKey
```

**Статус:** 🔴 Нет `paymentCardId`!

### 6.3 createOrder Response

**Спецификация:** `int` — ID заказа

**Реализация:**
```json
{
  "orderId": 123
}
```

**Статус:** ⚠️ Структура отличается

---

## 7. Сводка критических расхождений

| Критичность | Описание | Влияние на Flutter |
|-------------|----------|-------------------|
| 🔴 CRITICAL | PaymentCard endpoints отсутствуют | Оплата картой невозможна |
| 🔴 CRITICAL | createOrder без paymentCardId | Нельзя указать карту при заказе |
| 🟠 HIGH | OrderAddress без originalId | Возможна проблема при маппинге |
| 🟠 HIGH | OrderPromocode структура отличается | Нужна адаптация на клиенте |
| 🟡 MEDIUM | Gender без "other" | Минорная проблема |
| 🟡 MEDIUM | phoneLogin response структура | Нужна адаптация |
| 🟢 INFO | Дополнительные эндпоинты | Нужно добавить в документацию |

---

## 8. Рекомендации

### 8.1 Для бэкенда

1. **Реализовать PaymentCard** (если требуется) или обновить спецификацию
2. **Добавить originalId** в OrderAddress и OrderPromocode
3. **Добавить Gender::Other** если требуется по бизнес-логике
4. **Документировать** новые эндпоинты (Stories, Banners, Promotions, Reviews, ProductGroups)

### 8.2 Для Flutter-клиента

1. Адаптировать под текущую структуру Order если бэкенд не будет меняться
2. Учесть дополнительные статусы заказа (`assembling`, `assembled`)
3. Обработать response от phoneLogin в новом формате

---

## 9. Файлы для проверки

Ключевые файлы реализации:
- `app/Http/Controllers/Api/V1/` — контроллеры
- `app/Http/Resources/Api/V1/` — ресурсы (структура ответов)
- `app/Enums/` — перечисления
- `app/Http/Requests/Api/V1/` — валидация запросов
