# Тест-кейсы: Добавки (Supplements) — API Storefront

> **Сервер:** `https://store.aladdin.comrades.dev/api/v1`
> **БД:** `aladdin_store` (MySQL, root / 5yAznRcUL262WxoiD8XZ @ 127.0.0.1)

---

## 0. Подготовка тестовых данных

Добавки приходят через RabbitMQ из курьерки. Для тестирования API создаём данные напрямую в БД.

### SQL: создать добавки к товару «Укроп» (id=1, store_id=1, price=36)

```sql
-- Добавка type=one, обязательная (радиокнопки)
INSERT INTO supplements (title, product_id, type, is_required, created_at, updated_at)
VALUES ('Соус на выбор', 1, 'one', 1, NOW(), NOW());
SET @sup1 = LAST_INSERT_ID();

INSERT INTO supplement_values (title, price, supplement_id, created_at, updated_at) VALUES
('Сырный', 0, @sup1, NOW(), NOW()),
('Кетчуп', 0, @sup1, NOW(), NOW()),
('Горчица', 0, @sup1, NOW(), NOW());

-- Добавка type=many, необязательная (чекбоксы)
INSERT INTO supplements (title, product_id, type, is_required, created_at, updated_at)
VALUES ('Допы', 1, 'many', 0, NOW(), NOW());
SET @sup2 = LAST_INSERT_ID();

INSERT INTO supplement_values (title, price, supplement_id, created_at, updated_at) VALUES
('Аджика', 50, @sup2, NOW(), NOW()),
('Сметана с чесноком', 40, @sup2, NOW(), NOW()),
('Чесночно-томатная', 30, @sup2, NOW(), NOW());
```

### SQL: узнать ID созданных добавок

```sql
SELECT s.id as supplement_id, s.title, s.type, s.is_required,
       sv.id as value_id, sv.title as value_title, sv.price
FROM supplements s
JOIN supplement_values sv ON sv.supplement_id = s.id
WHERE s.product_id = 1
ORDER BY s.id, sv.id;
```

> Запомни ID из результата — они нужны во всех тестах ниже.
> В примерах ниже используются placeholder:
> - `{SUP1}` — ID добавки «Соус на выбор» (type=one, required)
> - `{SUP2}` — ID добавки «Допы» (type=many, optional)
> - `{VAL_CHEESE}`, `{VAL_KETCHUP}`, `{VAL_MUSTARD}` — значения Соуса
> - `{VAL_ADJIKA}`, `{VAL_SMETANA}`, `{VAL_TOMATO}` — значения Допов

### Авторизация

```
POST /api/v1/auth/phone-login
{"phone": "+79227934344"}

POST /api/v1/auth/phone-confirm
{"phone": "+79227934344", "code": <код из БД: SELECT code FROM otp_codes ORDER BY id DESC LIMIT 1>}
```

Токен из ответа использовать в заголовке: `Authorization: Bearer {token}`

---

## 1. Карточка товара — supplements приходят

### 1.1. Товар С добавками

```
GET /api/v1/products/1
```

**Ожидание:**
- Статус `200`
- `supplements` — массив из 2 элементов
- `supplements[0].type` = `"one"`, `supplements[0].isRequired` = `true`, `supplements[0].values` — 3 элемента (цена 0)
- `supplements[1].type` = `"many"`, `supplements[1].isRequired` = `false`, `supplements[1].values` — 3 элемента (цены 50, 40, 30)

### 1.2. Товар БЕЗ добавок

```
GET /api/v1/products/2
```

**Ожидание:**
- Статус `200`
- `supplements` = `[]`

---

## 2. Списки товаров — hasSupplements

### 2.1. Категория магазина

```
GET /api/v1/stores/1/categories?includeChildren=true
```

**Ожидание:**
- Статус `200`
- У товара id=1 (Укроп): `hasSupplements` = `true`
- У товара id=2 (Мука): `hasSupplements` = `false`

### 2.2. Похожие товары

```
GET /api/v1/products/1/similar
```

**Ожидание:**
- Каждый товар имеет поле `hasSupplements` (bool)

### 2.3. Поиск

```
GET /api/v1/search/products?query=Укроп
```

**Ожидание:**
- В результатах товар id=1 имеет `hasSupplements` = `true`

---

## 3. Корзина — добавление с добавками

### 3.1. Успешное добавление с обязательной + необязательной добавкой

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_CHEESE}]},
    {"supplementId": {SUP2}, "values": [{VAL_ADJIKA}, {VAL_SMETANA}]}
  ]
}
```

**Ожидание:**
- Статус `200`
- В `storeGroups[0].items[0]`:
  - `supplements` — 2 записи
  - `supplements[0].type` = `"one"`, 1 value
  - `supplements[1].type` = `"many"`, 2 values
  - `supplementsTotal` = `90` (50 + 40)
  - `price` = `36` (базовая цена товара)

### 3.2. Добавление только с обязательной добавкой (без необязательной)

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 2,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_KETCHUP}]}
  ]
}
```

**Ожидание:**
- Статус `200`
- Новый CartItem (отличается от 3.1 по набору добавок)
- `supplementsTotal` = `0` (Кетчуп бесплатный, 0 * 2 = 0)

### 3.3. Один товар + одинаковые добавки = quantity++

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_CHEESE}]},
    {"supplementId": {SUP2}, "values": [{VAL_ADJIKA}, {VAL_SMETANA}]}
  ]
}
```

> Повторяем точно такой же запрос как в 3.1

**Ожидание:**
- Статус `200`
- Количество CartItem'ов **не увеличилось** — `quantity` предыдущего стала `2`
- `supplementsTotal` = `180` ((50 + 40) * 2)

---

## 4. Корзина — валидация ошибок

### 4.1. Без обязательной добавки

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 1
}
```

**Ожидание:**
- Статус `422`
- Ошибка: `"Не все обязательные добавки выбраны"`

### 4.2. Обязательная добавка с пустыми values

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": []}
  ]
}
```

**Ожидание:**
- Статус `422`

### 4.3. type=one с двумя значениями

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_CHEESE}, {VAL_KETCHUP}]}
  ]
}
```

**Ожидание:**
- Статус `422`
- Ошибка: `"Для добавки «Соус на выбор» можно выбрать только одно значение"`

### 4.4. Добавка от другого товара

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 2,
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_CHEESE}]}
  ]
}
```

**Ожидание:**
- Статус `422`
- Ошибка: `"Добавка не принадлежит данному товару"`

### 4.5. Значение от чужой добавки

```
POST /api/v1/cart/items
Authorization: Bearer {token}

{
  "productId": 1,
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_ADJIKA}]}
  ]
}
```

> `{VAL_ADJIKA}` принадлежит SUP2, а не SUP1

**Ожидание:**
- Статус `422`
- Ошибка: `"Добавка не принадлежит данному товару"`

---

## 5. Корзина — обновление

### 5.1. Обновление quantity (добавки не меняются)

```
PUT /api/v1/cart/items/{cartItemId}
Authorization: Bearer {token}

{
  "quantity": 3
}
```

**Ожидание:**
- Статус `200`
- `quantity` = `3`
- `supplements` — те же, что были
- `supplementsTotal` пересчитан (* 3)

### 5.2. Обновление добавок

```
PUT /api/v1/cart/items/{cartItemId}
Authorization: Bearer {token}

{
  "quantity": 1,
  "supplements": [
    {"supplementId": {SUP1}, "values": [{VAL_MUSTARD}]},
    {"supplementId": {SUP2}, "values": [{VAL_TOMATO}]}
  ]
}
```

**Ожидание:**
- Статус `200`
- Добавки полностью заменены на новые
- `supplementsTotal` = `30` (Чесночно-томатная)

---

## 6. Получение корзины

```
GET /api/v1/cart
Authorization: Bearer {token}
```

**Ожидание:**
- Все CartItem содержат поле `supplements` и `supplementsTotal`
- У позиций без добавок: `supplements` = `[]`, `supplementsTotal` = `0`

---

## 7. Создание заказа с добавками

> Перед тестом очистить корзину и добавить одну позицию с добавками (тест 3.1).
> Нужен активный адрес у пользователя и delivery slot.

### 7.1. Получить слот доставки

```
GET /api/v1/stores/1/delivery-slots?addressId={addressId}&date={YYYY-MM-DD}
Authorization: Bearer {token}
```

### 7.2. Создать заказ

```
POST /api/v1/orders
Authorization: Bearer {token}

{
  "storeId": 1,
  "addressId": {addressId},
  "deliverySlotId": {slotId}
}
```

**Ожидание:**
- Статус `201`
- Ответ содержит `orderId`

### 7.3. Проверить заказ

```
GET /api/v1/orders/{orderId}
Authorization: Bearer {token}
```

**Ожидание:**
- `data.subtotal` = `126` (36 + 50 + 40 = 126, если qty=1 и Аджика+Сметана)
- `data.items[0].supplements` — массив из 2 записей
- `data.items[0].supplements[0].amount` = `0` (Сырный, цена 0)
- `data.items[0].supplements[1].amount` = `90` (50 + 40)
- `data.items[0].supplements[1].values[0].amount` = `50` (snapshot Аджика)
- `data.items[0].supplements[1].values[1].amount` = `40` (snapshot Сметана)

---

## 8. Очистка тестовых данных

```sql
-- Удалить добавки товара id=1
DELETE FROM supplement_values WHERE supplement_id IN (SELECT id FROM supplements WHERE product_id = 1);
DELETE FROM supplements WHERE product_id = 1;

-- Очистить корзину тестового пользователя (id=10)
DELETE FROM cart_item_supplements WHERE cart_item_id IN (SELECT id FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id = 10));
DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE user_id = 10);
DELETE FROM carts WHERE user_id = 10;
```
