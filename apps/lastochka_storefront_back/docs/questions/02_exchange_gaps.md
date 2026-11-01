# Вопросы: Что не доработано в Exchange

> На основе GAP-анализа между требованиями API клиента и текущим протоколом обмена RabbitMQ.

---

## 🔴 Критические недоработки (блокируют интеграцию)

### 1. Products — только одно изображение

**Текущий Exchange:**
```json
{
  "image_url": "https://..."
}
```

**API требует:**
```json
{
  "images": [
    {"id": 1, "path": "https://..."},
    {"id": 2, "path": "https://..."}
  ]
}
```

**Вопросы:**
1. Будет ли у товара несколько фотографий?
2. Как передавать галерею изображений?

**Рекомендация — расширить Exchange:**
```json
{
  "images": [
    {"url": "https://...", "sort_order": 0},
    {"url": "https://...", "sort_order": 1}
  ]
}
```

---

### 2. Products — КБЖУ через attributes

**Текущее решение:** Данные КБЖУ передаются через `attributes[]`.

**Вопрос:** Какие именно `code` используются в CRM для:
- `proteins` — белки
- `fats` — жиры
- `carbohydrates` — углеводы
- `calories` — калории
- `composition` — состав

**Или добавить явные поля в Exchange:**
```diff
+ composition: "string"
+ proteins: number
+ fats: number
+ carbohydrates: number
+ calories: number
```

---

### 3. Addresses — раздельные поля отсутствуют

**Текущий Exchange:**
```json
{
  "address": "Россия, Москва, ул. Ленина, д. 10"
}
```

**API хранит раздельно:**
```json
{
  "country": "Россия",
  "city": "Москва",
  "street": "Ленина",
  "houseNumber": "10"
}
```

**Вопросы:**
1. Какой формат используется в CRM — раздельные поля или одна строка?
2. Нужен ли парсинг адреса при получении из Exchange?
3. Достаточно ли хранить `fullAddress` как есть?

**Рекомендация — расширить Exchange:**
```diff
+ country: "string"
+ city: "string"
+ street: "string"
+ house_number: "string"
```

---

### 4. Addresses — нет комментария

**API поддерживает:**
```json
{
  "comment": "Домофон не работает, звоните"
}
```

**Exchange:** Поле `comment` отсутствует.

**Вопрос:** Добавить поле `comment` в Exchange addresses?

---

### 5. Orders — нет промокода

**API поддерживает:**
```json
{
  "promocode": {
    "code": "PROMO10",
    "percent": 10
  }
}
```

**Exchange:** Промокод отсутствует.

**Вопросы:**
1. Где хранятся промокоды? В CRM?
2. Нужна ли синхронизация скидки по промокоду?
3. Как CRM узнает, что применён промокод?

**Рекомендация — добавить в Exchange orders:**
```diff
+ promocode: {
+   code: "string",
+   discount_amount: number,
+   discount_percent: number
+ }
```

---

### 6. Orders — нет комментария

**API поддерживает:**
```json
{
  "comment": "Позвоните за 30 минут до доставки"
}
```

**Exchange:** Поле `comment` отсутствует.

**Вопрос:** Добавить поле `comment` в Exchange orders?

---

### 7. Orders — OrderItem без изображения

**API OrderItem:**
```json
{
  "imageUrl": "https://..."
}
```

**Exchange order_item:** Поле `imageUrl` отсутствует.

**Вопросы:**
1. Нужно ли CRM изображение товара в позиции заказа?
2. Или копировать из Product при создании заказа на бэке?

---

### 8. Shops — нет изображения

**API Store:**
```json
{
  "image": {"id": 1, "path": "https://..."}
}
```

**Exchange shops:** Поле изображения отсутствует.

**Вопросы:**
1. Откуда брать логотип/фото магазина?
2. Загружается через админку отдельно?
3. Добавить в Exchange?

**Рекомендация:**
```diff
+ image_url: "string"
```

---

### 9. Shops — нет minOrderAmount

**API Store:**
```json
{
  "minOrderAmount": 500
}
```

**Exchange shops:** Поле отсутствует (есть в DeliverySystem API).

**Вопросы:**
1. Откуда брать минимальную сумму заказа?
2. Из DeliverySystem при запросе слотов?
3. Или добавить в Exchange shops?

---

### 10. Shops — нет рейтинга

**API Store:**
```json
{
  "rating": 4.5
}
```

**Exchange shops:** Поле `rating` отсутствует.

**Вопросы:**
1. Есть ли функционал отзывов в системе?
2. Как считается рейтинг магазина?
3. Нужно ли синхронизировать с CRM?

---

### 11. Customers — gender "other"

**API:**
```json
{
  "gender": "male | female | other"
}
```

**Exchange:**
```json
{
  "gender": "male | female"
}
```

**Вопрос:** Что делать при синхронизации, если пользователь выбрал `other`?
- Отправлять `null`?
- Не отправлять поле?
- Добавить `other` в Exchange?

---

## 🟡 Менее критичные вопросы

### 12. Статусы заказов — несоответствие

**Exchange (7 статусов):**
```
created | assembling | assembled | in_delivery | delivered | cancelled | returned
```

**API (4 статуса):**
```
pending | inDelivery | delivered | cancelled
```

**Вопросы:**
1. Нужны ли клиенту статусы `assembling`, `assembled`?
2. Как обрабатывать статус `returned`?

**Предложенный маппинг:**
| Exchange | → API |
|----------|-------|
| created | pending |
| assembling | pending |
| assembled | pending |
| in_delivery | inDelivery |
| delivered | delivered |
| cancelled | cancelled |
| returned | cancelled (или новый статус?) |

---

### 13. PaymentCard — детали карты

**API хранит:**
```json
{
  "last4": "4242",
  "cardType": "Visa"
}
```

**Exchange знает только:**
```json
{
  "payment_type": "cash | card",
  "is_paid": true
}
```

**Вопрос:** Нужна ли CRM информация о карте (last4, cardType)?

---

### 14. Слоты доставки — формат времени

**DeliverySystem:**
```json
{
  "start": "2025-01-15 10:00:00",
  "end": "2025-01-15 12:00:00"
}
```

**API DeliverySlot:**
```json
{
  "id": 1,
  "timeSlot": "10:00-12:00"
}
```

**Вопрос:** Как передавать выбранный слот в Exchange?
- Использовать `slot_start_date` и `slot_end_date`?
- Формат даты?

---

## Сводка: что добавить в Exchange

### Products
```diff
+ images: [
+   {url: "string", sort_order: number}
+ ]
+ composition: "string"
+ proteins: number
+ fats: number
+ carbohydrates: number
+ calories: number
```

### Orders
```diff
+ promocode: {
+   code: "string",
+   discount_amount: number,
+   discount_percent: number
+ }
+ comment: "string"
```

### Order Items
```diff
+ image_url: "string"
```

### Addresses
```diff
+ comment: "string"
+ country: "string"
+ city: "string"
+ street: "string"
+ house_number: "string"
```

### Shops
```diff
+ image_url: "string"
+ min_order_amount: number
+ rating: number
```

### Customers
```diff
  gender: "male | female" → "male | female | other"
```

---

## Следующие шаги

1. **Согласовать изменения Exchange** с командой интеграций (RetailBridge)
2. **Определить источник данных:**
   - КБЖУ — CRM или внешний справочник?
   - Изображения — CRM или отдельное хранилище?
   - Промокоды — CRM или локальная БД?
3. **Решить вопрос с адресами:**
   - Раздельные поля или одна строка?
   - Нужен ли геокодинг?
4. **Уточнить статусы заказов:**
   - Какие статусы показывать клиенту?
   - Нужен ли статус "Возврат"?
